<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Resilience\Settlement;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(Settlement::class)]
final class SettlementTest extends AbstractTestCase
{
    public function testABatchSettlesOneRequestAtATimeThroughAPlainClient(): void
    {
        $failure = new NetworkException(self::request('https://b.example.com/'), 'Connection reset');
        $inner = new ScriptedClient([self::response(200), $failure]);

        $outcomes = Settlement::through($inner, [
            'a' => self::request('https://a.example.com/'),
            7 => self::request('https://b.example.com/'),
        ]);

        static::assertSame(['a', 7], array_keys($outcomes));
        static::assertInstanceOf(ResponseInterface::class, $outcomes['a'] ?? null);
        static::assertSame($failure, $outcomes[7] ?? null);
        static::assertSame(2, $inner->calls);
    }

    public function testABatchSettlesTogetherThroughASettlingClient(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(200)],
            'https://b.example.com/' => [self::response(503)],
        ]);

        $outcomes = Settlement::through($inner, [
            self::request('https://a.example.com/'),
            self::request('https://b.example.com/'),
        ]);

        static::assertCount(2, $outcomes);
        static::assertSame([['https://a.example.com/', 'https://b.example.com/']], $inner->batches);
        static::assertSame([], $inner->sent);
    }

    public function testABatchOfOneGoesThroughSendRequest(): void
    {
        $inner = new ScriptedSettlingClient(['https://a.example.com/' => [self::response(200)]]);

        $outcomes = Settlement::through($inner, ['only' => self::request('https://a.example.com/')]);

        static::assertSame(['only'], array_keys($outcomes));
        static::assertSame([], $inner->batches);
        static::assertSame(['https://a.example.com/'], $inner->sent);
    }

    public function testTheFailFastViewThrowsTheFirstFailureInKeyOrder(): void
    {
        $first = new NetworkException(self::request('https://b.example.com/'), 'first');
        $second = new NetworkException(self::request('https://c.example.com/'), 'second');

        try {
            Settlement::responsesOrThrow(['a' => self::response(200), 'b' => $first, 'c' => $second]);
            static::fail('A settled failure must be thrown.');
        } catch (Throwable $e) {
            static::assertSame($first, $e);
        }
    }

    public function testTheFailFastViewKeepsEveryKeyWhenNothingFailed(): void
    {
        $responses = Settlement::responsesOrThrow(['a' => self::response(200), 3 => self::response(404)]);

        static::assertSame(['a', 3], array_keys($responses));
        static::assertSame(404, ($responses[3] ?? self::response(500))->getStatusCode());
    }

    public function testAnUnsettledRequestIsAFailureCarryingItsRequest(): void
    {
        $request = self::request('https://a.example.com/');

        $failure = Settlement::unsettled($request);

        static::assertInstanceOf(RequestException::class, $failure);
        static::assertSame($request, $failure->getRequest());
    }

    public function testADeferredPromiseSendsNothingUntilItIsWaitedOn(): void
    {
        $inner = new ScriptedClient([self::response(201)]);

        $promise = Settlement::deferred($inner, self::request('https://a.example.com/'));
        $callsBeforeWait = $inner->calls;
        $stateBeforeWait = $promise->state();

        static::assertSame(201, $promise->wait()->getStatusCode());
        static::assertSame(0, $callsBeforeWait);
        static::assertSame(PromiseState::Pending, $stateBeforeWait);
        static::assertSame(1, $inner->calls);
        static::assertSame(PromiseState::Fulfilled, $promise->state());
    }

    public function testADeferredPromiseRejectsWithTheFailure(): void
    {
        $failure = new NetworkException(self::request('https://a.example.com/'), 'Connection refused');
        $promise = Settlement::deferred(new ScriptedClient([$failure]), self::request('https://a.example.com/'));

        try {
            $promise->wait();
            static::fail('The deferred promise must reject.');
        } catch (Throwable $e) {
            static::assertSame($failure, $e);
            static::assertSame(PromiseState::Rejected, $promise->state());
        }
    }

    private static function request(string $uri): RequestInterface
    {
        return new Psr17Factory()->createRequest('GET', $uri);
    }

    private static function response(int $status): ResponseInterface
    {
        return new Psr17Factory()->createResponse($status);
    }
}
