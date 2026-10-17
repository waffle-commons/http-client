<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(RetryingClient::class)]
final class RetryingClientTest extends AbstractTestCase
{
    /** @var list<int> */
    private array $waits = [];

    public function testASuccessIsReturnedWithoutWaiting(): void
    {
        $inner = new ScriptedClient([self::response(200)]);

        static::assertSame(200, $this->client($inner)->sendRequest(self::request())->getStatusCode());
        static::assertSame(1, $inner->calls);
        static::assertSame([], $this->waits);
    }

    public function testARetryableStatusIsRetried(): void
    {
        $inner = new ScriptedClient([self::response(503), self::response(200)]);

        static::assertSame(200, $this->client($inner)->sendRequest(self::request())->getStatusCode());
        static::assertSame(2, $inner->calls);
        static::assertCount(1, $this->waits);
        static::assertLessThanOrEqual(100, $this->waits[0] ?? -1);
    }

    public function testATransportFailureIsRetried(): void
    {
        $inner = new ScriptedClient([self::failure(), self::response(200)]);

        static::assertSame(200, $this->client($inner)->sendRequest(self::request())->getStatusCode());
        static::assertSame(2, $inner->calls);
    }

    public function testTheLastResponseIsReturnedOnceAttemptsRunOut(): void
    {
        $inner = new ScriptedClient([self::response(502), self::response(503), self::response(504)]);

        static::assertSame(504, $this->client($inner)->sendRequest(self::request())->getStatusCode());
        static::assertSame(3, $inner->calls);
    }

    public function testTheLastFailureIsThrownOnceAttemptsRunOut(): void
    {
        $inner = new ScriptedClient([self::failure(), self::failure(), self::failure()]);

        $this->expectException(NetworkException::class);

        try {
            $this->client($inner)->sendRequest(self::request());
        } finally {
            static::assertSame(3, $inner->calls);
        }
    }

    public function testANonIdempotentMethodIsNotRetried(): void
    {
        $inner = new ScriptedClient([self::response(503), self::response(200)]);

        static::assertSame(503, $this->client($inner)->sendRequest(self::request('POST'))->getStatusCode());
        static::assertSame(1, $inner->calls);
    }

    public function testANonIdempotentMethodIsRetriedWhenOptedIn(): void
    {
        $inner = new ScriptedClient([self::response(503), self::response(200)]);
        $client = $this->client($inner)->withPolicy(new RetryPolicy(3, 10, 100, 10_000, true));

        static::assertSame(200, $client->sendRequest(self::request('POST'))->getStatusCode());
    }

    public function testRetryAfterMayExceedTheBackoffCap(): void
    {
        $inner = new ScriptedClient([self::response(429)->withHeader('Retry-After', '2'), self::response(200)]);

        $this->client($inner)->sendRequest(self::request());

        static::assertSame([2000], $this->waits);
    }

    public function testNoWaitStartsThatWouldOverrunTheBudget(): void
    {
        $inner = new ScriptedClient([self::response(503)->withHeader('Retry-After', '60'), self::response(200)]);

        static::assertSame(503, $this->client($inner)->sendRequest(self::request())->getStatusCode());
        static::assertSame([], $this->waits);
    }

    public function testAFailureIsThrownWhenTheBudgetLeavesNoRoom(): void
    {
        $inner = new ScriptedClient([self::failure(), self::response(200)]);
        $client = $this->client($inner)->withPolicy(new RetryPolicy(3, 10_000, 10_000, 1));

        $this->expectException(NetworkException::class);

        $client->sendRequest(self::request());
    }

    private function client(ScriptedClient $inner): RetryingClient
    {
        return new RetryingClient($inner, new RetryPolicy(3, 10, 100, 10_000), function (int $ms): void {
            $this->waits[] = $ms;
        });
    }

    private static function request(string $method = 'GET'): RequestInterface
    {
        return new Psr17Factory()->createRequest($method, 'https://api.example.com/orders');
    }

    private static function response(int $status): ResponseInterface
    {
        return new Psr17Factory()->createResponse($status);
    }

    private static function failure(): NetworkException
    {
        return new NetworkException(self::request(), 'Connection reset');
    }
}
