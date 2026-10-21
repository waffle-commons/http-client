<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitDecision;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Contracts\CircuitBreaker\Event\CircuitStateChanged;
use Waffle\Commons\Contracts\CircuitBreaker\Exception\CircuitOpenExceptionInterface;
use Waffle\Commons\Contracts\EventDispatcher\EventDispatcherInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\RateLimit\Exception\StateStorageExceptionInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\HttpClient\Exception\CircuitOpenException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Exception\SsrfException;
use Waffle\Commons\HttpClient\Resilience\CircuitBreakerClient;
use Waffle\Commons\HttpClient\Resilience\CircuitObservers;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(CircuitBreakerClient::class)]
#[CoversClass(CircuitObservers::class)]
#[CoversClass(CircuitOpenException::class)]
final class CircuitBreakerClientTest extends AbstractTestCase
{
    public function testAClosedCircuitLetsTheCallThroughAndRecordsASuccess(): void
    {
        $storage = $this->createMock(CircuitStateStorageInterface::class);
        $storage
            ->expects($this->once())
            ->method('acquire')
            ->with('https://api.example.com:443')
            ->willReturn(new CircuitDecision(true, CircuitState::Closed));
        $storage
            ->expects($this->once())
            ->method('record')
            ->with('https://api.example.com:443', $this->anything(), true)
            ->willReturn(new CircuitDecision(true, CircuitState::Closed));
        $inner = new ScriptedClient([new Psr17Factory()->createResponse(200)]);

        static::assertSame(
            200,
            new CircuitBreakerClient($inner, $storage)
                ->sendRequest(self::request())
                ->getStatusCode(),
        );
    }

    public function testAnOpenCircuitFailsFastWithA503(): void
    {
        $storage = $this->createStub(CircuitStateStorageInterface::class);
        $storage->method('acquire')->willReturn(new CircuitDecision(false, CircuitState::Open));
        $inner = new ScriptedClient([]);

        try {
            new CircuitBreakerClient($inner, $storage)->sendRequest(self::request());
            static::fail('The open circuit must refuse the call.');
        } catch (CircuitOpenExceptionInterface $e) {
            static::assertSame(503, $e->getCode());
            static::assertSame(0, $inner->calls);
        }
    }

    public function testATransportFailureIsRecordedThenRethrown(): void
    {
        $storage = $this->createMock(CircuitStateStorageInterface::class);
        $storage->method('acquire')->willReturn(new CircuitDecision(true, CircuitState::Closed));
        $storage
            ->expects($this->once())
            ->method('record')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn(new CircuitDecision(true, CircuitState::Closed));
        $inner = new ScriptedClient([new NetworkException(self::request(), 'Connection refused')]);

        $this->expectException(NetworkException::class);

        new CircuitBreakerClient($inner, $storage)->sendRequest(self::request());
    }

    public function testAServerErrorCountsAsAFailureAndATransitionIsPublished(): void
    {
        $storage = $this->createStub(CircuitStateStorageInterface::class);
        $storage->method('acquire')->willReturn(new CircuitDecision(true, CircuitState::Closed));
        $storage->method('record')->willReturn(new CircuitDecision(true, CircuitState::Open, CircuitState::Closed));
        $events = $this->createMock(EventDispatcherInterface::class);
        $events
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn(object $event): bool => (
                    $event instanceof CircuitStateChanged
                    && $event->from === CircuitState::Closed
                    && $event->to === CircuitState::Open
                    && $event->circuit === 'https://api.example.com:443'
                ),
            ))
            ->willReturnArgument(0);
        $metrics = $this->createMock(MetricsRegistryInterface::class);
        $metrics->expects($this->once())->method('increment')->with(CircuitBreakerClient::TRANSITIONS_METRIC, 1.0, [
            'circuit' => 'https://api.example.com:443',
            'to' => 'open',
        ]);
        $metrics->expects($this->exactly(2))->method('gauge');
        $inner = new ScriptedClient([new Psr17Factory()->createResponse(500)]);

        $client = new CircuitBreakerClient($inner, $storage, observers: new CircuitObservers($events, $metrics));

        static::assertSame(500, $client->sendRequest(self::request())->getStatusCode());
    }

    public function testAnUnavailableStorageBehavesAsClosed(): void
    {
        $outage = new class('Connection refused') extends RuntimeException implements StateStorageExceptionInterface {};
        $storage = $this->createStub(CircuitStateStorageInterface::class);
        $storage->method('acquire')->willThrowException($outage);
        $storage->method('record')->willThrowException($outage);
        $metrics = $this->createMock(MetricsRegistryInterface::class);
        $metrics
            ->expects($this->exactly(2))
            ->method('increment')
            ->with(CircuitBreakerClient::STORAGE_ERRORS_METRIC, 1.0, ['circuit' => 'http://legacy.internal:8080']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $inner = new ScriptedClient([new Psr17Factory()->createResponse(200)]);

        $client = new CircuitBreakerClient($inner, $storage, observers: new CircuitObservers(null, $metrics, $logger));
        $response = $client->sendRequest(new Psr17Factory()->createRequest('GET', 'http://legacy.internal:8080/x'));

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(1, $inner->calls);
    }

    public function testAnOpenCircuitIsNeverRetried(): void
    {
        $storage = $this->createStub(CircuitStateStorageInterface::class);
        $storage->method('acquire')->willReturn(new CircuitDecision(false, CircuitState::Open));
        $inner = new ScriptedClient([]);
        $client = new RetryingClient(new CircuitBreakerClient($inner, $storage), new RetryPolicy(5, 1, 1, 10_000));

        $this->expectException(CircuitOpenExceptionInterface::class);

        $client->sendRequest(self::request());
    }

    public function testTheCircuitKeyDefaultsThePortFromTheScheme(): void
    {
        $storage = $this->createMock(CircuitStateStorageInterface::class);
        $storage
            ->expects($this->once())
            ->method('acquire')
            ->with('http://example.com:80')
            ->willReturn(new CircuitDecision(false, CircuitState::Open));

        $this->expectException(CircuitOpenException::class);

        new CircuitBreakerClient(new ScriptedClient([]), $storage)->sendRequest(new Psr17Factory()->createRequest(
            'GET',
            '//example.com/',
        ));
    }

    public function testABatchSendsTheAdmittedRequestsTogetherAndSettlesTheRefusedOnes(): void
    {
        $storage = new RecordingCircuitStorage(['https://b.example.com:443']);
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(200)],
            'https://c.example.com/' => [self::response(200)],
        ]);

        $outcomes = new CircuitBreakerClient($inner, $storage)->settleRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
            'c' => self::requestTo('https://c.example.com/'),
        ]);

        static::assertSame(['a', 'b', 'c'], array_keys($outcomes));
        static::assertInstanceOf(CircuitOpenException::class, $outcomes['b'] ?? null);
        static::assertSame(503, $outcomes['b']->getCode());
        static::assertSame([['https://a.example.com/', 'https://c.example.com/']], $inner->batches);
    }

    public function testEveryOutcomeOfABatchIsRecordedExceptTheOnesThatSayNothingAboutTheUpstream(): void
    {
        $storage = new RecordingCircuitStorage();
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(404)],
            'https://b.example.com/' => [self::response(502)],
            'https://c.example.com/' => [new NetworkException(self::requestTo('https://c.example.com/'), 'Timeout')],
            'https://d.example.com/' => [new SsrfException(self::requestTo('https://d.example.com/'), 'Private')],
        ]);

        new CircuitBreakerClient($inner, $storage)->settleRequests([
            self::requestTo('https://a.example.com/'),
            self::requestTo('https://b.example.com/'),
            self::requestTo('https://c.example.com/'),
            self::requestTo('https://d.example.com/'),
        ]);

        static::assertSame(
            [
                ['https://a.example.com:443', true],
                ['https://b.example.com:443', false],
                ['https://c.example.com:443', false],
            ],
            $storage->recorded,
        );
    }

    public function testAFailFastBatchThrowsTheFirstFailureOnceEveryRequestSettled(): void
    {
        $storage = new RecordingCircuitStorage(['https://b.example.com:443']);
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(200)],
            'https://c.example.com/' => [self::response(500)],
        ]);

        try {
            new CircuitBreakerClient($inner, $storage)->sendRequests([
                self::requestTo('https://a.example.com/'),
                self::requestTo('https://b.example.com/'),
                self::requestTo('https://c.example.com/'),
            ]);
            static::fail('The refused request must fail the batch.');
        } catch (CircuitOpenExceptionInterface $e) {
            static::assertStringContainsString('https://b.example.com:443', $e->getMessage());
        }

        static::assertSame(
            [
                ['https://a.example.com:443', true],
                ['https://c.example.com:443', false],
            ],
            $storage->recorded,
        );
    }

    public function testAFailFastBatchReturnsEveryResponseWhenNothingFailed(): void
    {
        $inner = new ScriptedSettlingClient([
            'https://a.example.com/' => [self::response(200)],
            'https://b.example.com/' => [self::response(201)],
        ]);

        $responses = new CircuitBreakerClient($inner, new RecordingCircuitStorage())->sendRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
        ]);

        static::assertSame(['a', 'b'], array_keys($responses));
        static::assertSame(201, ($responses['b'] ?? self::response(500))->getStatusCode());
    }

    public function testAnOutcomeTheInnerClientForgotIsAFailureThatIsNotRecorded(): void
    {
        $storage = new RecordingCircuitStorage();
        $inner = new ScriptedSettlingClient(['https://a.example.com/' => [self::response(200)]], forget: ['b']);

        $outcomes = new CircuitBreakerClient($inner, $storage)->settleRequests([
            'a' => self::requestTo('https://a.example.com/'),
            'b' => self::requestTo('https://b.example.com/'),
        ]);

        static::assertInstanceOf(RequestException::class, $outcomes['b'] ?? null);
        static::assertSame([['https://a.example.com:443', true]], $storage->recorded);
    }

    public function testAPromiseChecksTheCircuitOnlyWhenItIsWaitedOn(): void
    {
        $storage = new RecordingCircuitStorage(['https://api.example.com:443']);
        $inner = new ScriptedClient([]);

        $promise = new CircuitBreakerClient($inner, $storage)->promise(self::request());
        $acquiredBeforeWait = $storage->acquired;

        try {
            $promise->wait();
            static::fail('The open circuit must reject the promise.');
        } catch (CircuitOpenExceptionInterface) {
            static::assertSame([], $acquiredBeforeWait);
            static::assertSame(['https://api.example.com:443'], $storage->acquired);
            static::assertSame(0, $inner->calls);
        }
    }

    private static function request(): RequestInterface
    {
        return new Psr17Factory()->createRequest('GET', 'https://api.example.com/orders');
    }

    private static function requestTo(string $uri): RequestInterface
    {
        return new Psr17Factory()->createRequest('GET', $uri);
    }

    private static function response(int $status): ResponseInterface
    {
        return new Psr17Factory()->createResponse($status);
    }
}
