<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\HttpClient\Exception\CircuitOpenException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Resilience\CircuitBreakerClient;
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

    public function testABatchRetriesOnlyWhatFailedAndKeepsItsKeyOrder(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(503), self::response(200)],
            'https://b.example.com/' => [self::response(200)],
            'https://c.example.com/' => [self::failure(), self::response(201)],
        ]);

        $outcomes = $this->client($inner)->settleRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
            'c' => self::requestTo('https://c.example.com/'),
        ]);

        static::assertSame(['a' => 200, 'b' => 200, 'c' => 201], self::summary($outcomes));
        static::assertSame(
            [
                ['https://a.example.com/', 'https://b.example.com/', 'https://c.example.com/'],
                ['https://a.example.com/', 'https://c.example.com/'],
            ],
            $inner->batches,
        );
        static::assertCount(1, $this->waits);
        static::assertLessThanOrEqual(10, $this->waits[0] ?? -1);
    }

    public function testAWaveWaitsForTheLongestDelayItCarries(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(429)->withHeader('Retry-After', '2'), self::response(200)],
            'https://b.example.com/' => [self::response(503), self::response(200)],
        ]);

        $this->client($inner)->settleRequests([
            self::requestTo('https://a.example.com/'),
            self::requestTo('https://b.example.com/'),
        ]);

        static::assertSame([2000], $this->waits);
    }

    public function testEachRequestOfABatchKeepsItsOwnAttempts(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(503), self::response(503), self::response(504)],
            'https://b.example.com/' => [self::response(503)],
        ]);

        $outcomes = $this->client($inner)->settleRequests([
            'get' => self::requestTo('https://a.example.com/'),
            'post' => self::requestTo('https://b.example.com/', 'POST'),
        ]);

        static::assertSame(['get' => 504, 'post' => 503], self::summary($outcomes));
        // The first wave goes out as a batch; a lone retry goes through sendRequest().
        static::assertSame([['https://a.example.com/', 'https://b.example.com/']], $inner->batches);
        static::assertSame(['https://a.example.com/', 'https://a.example.com/'], $inner->sent);
    }

    public function testNoWaveStartsThatWouldOverrunTheBudget(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(503)->withHeader('Retry-After', '60'), self::response(200)],
            'https://b.example.com/' => [self::response(200)],
        ]);

        $outcomes = $this->client($inner)->settleRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
        ]);

        static::assertSame(['a' => 503, 'b' => 200], self::summary($outcomes));
        static::assertSame([], $this->waits);
    }

    public function testARefusedOrForgottenRequestIsNeverRetried(): void
    {
        $storage = new RecordingCircuitStorage(['https://b.example.com:443']);
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(503), self::response(200)],
            'https://c.example.com/' => [self::response(200)],
        ], forget: ['d']);

        $outcomes = $this->client(new CircuitBreakerClient($inner, $storage))->settleRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
            'c' => self::requestTo('https://c.example.com/'),
            'd' => self::requestTo('https://d.example.com/'),
        ]);

        static::assertSame(
            [
                'a' => 200,
                'b' => CircuitOpenException::class,
                'c' => 200,
                'd' => RequestException::class,
            ],
            self::summary($outcomes),
        );
        // Every attempt asks the breaker again, the retry of `a` included.
        static::assertSame(
            [
                'https://a.example.com:443',
                'https://b.example.com:443',
                'https://c.example.com:443',
                'https://d.example.com:443',
                'https://a.example.com:443',
            ],
            $storage->acquired,
        );
    }

    public function testAFailFastBatchThrowsTheFirstFinalFailure(): void
    {
        $failure = self::failure();
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(200)],
            'https://b.example.com/' => [$failure, $failure, $failure],
        ]);

        try {
            $this->client($inner)->sendRequests([
                self::requestTo('https://a.example.com/'),
                self::requestTo('https://b.example.com/'),
            ]);
            static::fail('The failure that outlived its retries must fail the batch.');
        } catch (NetworkException $e) {
            static::assertSame($failure, $e);
        }
    }

    public function testAFailFastBatchReturnsEveryResponseOnceRetried(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(502), self::response(200)],
            'https://b.example.com/' => [self::response(204)],
        ]);

        $responses = $this->client($inner)->sendRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
        ]);

        static::assertSame(['a' => 200, 'b' => 204], self::summary($responses));
    }

    public function testAPromiseRetriesOnceItIsWaitedOn(): void
    {
        $inner = new ScriptedClient([self::response(503), self::response(200)]);

        $promise = $this->client($inner)->promise(self::request());
        $callsBeforeWait = $inner->calls;

        static::assertSame(200, $promise->wait()->getStatusCode());
        static::assertSame(0, $callsBeforeWait);
        static::assertSame(2, $inner->calls);
    }

    private function client(ClientInterface $inner): RetryingClient
    {
        return new RetryingClient($inner, new RetryPolicy(3, 10, 100, 10_000), function (int $ms): void {
            $this->waits[] = $ms;
        });
    }

    /**
     * Each outcome as its status code, or as the class of the exception it failed with, keyed like the batch.
     *
     * @param array<array-key, ResponseInterface|ClientExceptionInterface> $outcomes
     *
     * @return array<array-key, int|string>
     */
    private static function summary(array $outcomes): array
    {
        $summary = [];
        foreach ($outcomes as $key => $outcome) {
            $summary[$key] = $outcome instanceof ResponseInterface ? $outcome->getStatusCode() : $outcome::class;
        }

        return $summary;
    }

    private static function request(string $method = 'GET'): RequestInterface
    {
        return new Psr17Factory()->createRequest($method, 'https://api.example.com/orders');
    }

    private static function requestTo(string $uri, string $method = 'GET'): RequestInterface
    {
        return new Psr17Factory()->createRequest($method, $uri);
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
