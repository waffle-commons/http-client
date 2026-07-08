<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Promise;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Promise\Promise;

/**
 * Unit-tests the promise state machine in isolation: the resolver closure stands
 * in for the cURL-driving logic, so these cover settlement, caching, and the
 * then/catch notification contract without the transport.
 *
 * Settle callbacks record into typed instance properties (not flow-narrowed
 * locals) so the by-reference capture stays type-clean under static analysis.
 */
#[CoversClass(Promise::class)]
final class PromiseTest extends TestCase
{
    private Psr17Factory $psr17;

    /** Response observed by a `then` callback, or null if none fired yet. */
    private ?ResponseInterface $observedResponse = null;

    /** Failure observed by a `catch` callback, or null if none fired yet. */
    private ?Throwable $observedError = null;

    /** Number of times the most-recently-registered settle callback fired. */
    private int $callbackInvocations = 0;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->psr17 = new Psr17Factory();
        $this->observedResponse = null;
        $this->observedError = null;
        $this->callbackInvocations = 0;
    }

    /**
     * Build a promise whose cleanup closure increments the by-reference
     * {@see $cleanupCount}, so a test can assert the per-promise handles are
     * released exactly once (the counter is a local, not a flow-narrowed
     * property, so it stays type-clean under static analysis).
     *
     * @param Closure(): ResponseInterface $resolver
     */
    private function promise(Closure $resolver, int &$cleanupCount = 0): Promise
    {
        return new Promise($resolver, static function () use (&$cleanupCount): void {
            $cleanupCount++;
        });
    }

    public function testStartsPending(): void
    {
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200));

        self::assertSame(PromiseState::Pending, $promise->state());
    }

    public function testWaitDrivesPendingToFulfilledAndReturnsResponse(): void
    {
        $expected = $this->psr17->createResponse(201, 'Created');
        $promise = $this->promise(static fn(): ResponseInterface => $expected);

        $response = $promise->wait();

        self::assertSame($expected, $response);
        self::assertSame(PromiseState::Fulfilled, $promise->state());
    }

    public function testWaitDrivesPendingToRejectedAndThrows(): void
    {
        $failure = new NetworkException($this->request(), 'boom', CURLE_OPERATION_TIMEOUTED);
        $promise = $this->promise(static function () use ($failure): ResponseInterface {
            throw $failure;
        });

        try {
            $promise->wait();
            self::fail('Expected the rejection to be thrown.');
        } catch (ClientExceptionInterface $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertSame(PromiseState::Rejected, $promise->state());
    }

    public function testResolverRunsOnlyOnceAndCachesTheResponse(): void
    {
        $calls = 0;
        $promise = $this->promise(function () use (&$calls): ResponseInterface {
            $calls++;
            return $this->psr17->createResponse(200);
        });

        $first = $promise->wait();
        $second = $promise->wait();

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testRejectionIsCachedAndReplayedWithoutReRunningResolver(): void
    {
        $calls = 0;
        $failure = new NetworkException($this->request(), 'down');
        $promise = $this->promise(static function () use (&$calls, $failure): ResponseInterface {
            $calls++;
            throw $failure;
        });

        try {
            $promise->wait();
            self::fail('Expected first wait() to throw.');
        } catch (ClientExceptionInterface) {
            // expected — the first drive rejects the promise.
            self::assertSame(PromiseState::Rejected, $promise->state());
        }

        $this->expectException(NetworkException::class);
        try {
            $promise->wait();
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testThenFiresOnceFulfilledViaWait(): void
    {
        $expected = $this->psr17->createResponse(200);
        $promise = $this->promise(static fn(): ResponseInterface => $expected);
        $promise->then($this->recordResponse());

        // Registering on a still-pending promise defers the callback: the promise
        // has not settled, so nothing has fired yet.
        self::assertSame(PromiseState::Pending, $promise->state());

        $promise->wait();

        self::assertSame($expected, $this->observedResponse);
        self::assertSame(1, $this->callbackInvocations);
    }

    public function testThenReturnsSamePromiseForFluentRegistration(): void
    {
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200));

        self::assertSame($promise, $promise->then(static fn(ResponseInterface $_r): null => null));
        self::assertSame($promise, $promise->catch(static fn(Throwable $_e): null => null));
    }

    public function testCatchFiresOnRejectionViaWait(): void
    {
        $failure = new NetworkException($this->request(), 'timeout');
        $promise = $this->promise(static function () use ($failure): ResponseInterface {
            throw $failure;
        });
        $promise->catch($this->recordError());

        try {
            $promise->wait();
        } catch (ClientExceptionInterface) {
            // expected — the drive rejects the promise.
            self::assertSame(PromiseState::Rejected, $promise->state());
        }

        self::assertSame($failure, $this->observedError);
        self::assertSame(1, $this->callbackInvocations);
    }

    public function testThenRegisteredAfterFulfilmentFiresImmediately(): void
    {
        $expected = $this->psr17->createResponse(200);
        $promise = $this->promise(static fn(): ResponseInterface => $expected);
        $promise->wait();

        $promise->then($this->recordResponse());

        self::assertSame($expected, $this->observedResponse);
        self::assertSame(1, $this->callbackInvocations);
    }

    public function testCatchRegisteredAfterRejectionFiresImmediately(): void
    {
        $failure = new NetworkException($this->request(), 'reset');
        $promise = $this->promise(static function () use ($failure): ResponseInterface {
            throw $failure;
        });
        try {
            $promise->wait();
        } catch (ClientExceptionInterface) {
            // expected — the drive rejects the promise.
            self::assertSame(PromiseState::Rejected, $promise->state());
        }

        $promise->catch($this->recordError());

        self::assertSame($failure, $this->observedError);
        self::assertSame(1, $this->callbackInvocations);
    }

    public function testThenOnRejectedPromiseNeverFires(): void
    {
        $promise = $this->promise(function (): ResponseInterface {
            throw new NetworkException($this->request(), 'nope');
        });
        try {
            $promise->wait();
        } catch (ClientExceptionInterface) {
            // expected — the drive rejects the promise.
            self::assertSame(PromiseState::Rejected, $promise->state());
        }

        $promise->then($this->recordResponse());

        self::assertNull($this->observedResponse);
        self::assertSame(0, $this->callbackInvocations);
    }

    public function testCatchOnFulfilledPromiseNeverFires(): void
    {
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200));
        $promise->wait();

        $promise->catch($this->recordError());

        self::assertNull($this->observedError);
        self::assertSame(0, $this->callbackInvocations);
    }

    public function testWaitReleasesHandlesOnFulfilment(): void
    {
        // ASYNC02-04: a promise frees its per-request cURL handles as soon as it
        // settles, not at some indeterminate GC time.
        $cleanups = 0;
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200), $cleanups);

        $promise->wait();

        self::assertSame(1, $cleanups);
    }

    public function testWaitReleasesHandlesOnRejection(): void
    {
        $cleanups = 0;
        $promise = $this->promise(function (): ResponseInterface {
            throw new NetworkException($this->request(), 'boom');
        }, $cleanups);

        try {
            $promise->wait();
            self::fail('Expected the rejection to be thrown.');
        } catch (ClientExceptionInterface) {
            self::assertSame(PromiseState::Rejected, $promise->state());
        }

        self::assertSame(1, $cleanups);
    }

    public function testCleanupRunsExactlyOnceAcrossRepeatedWaitAndDestruct(): void
    {
        // Cleanup is idempotent: a second wait() (replaying the cached outcome)
        // and the destructor must NOT free the handles again (ASYNC02-04).
        $cleanups = 0;
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200), $cleanups);

        $promise->wait();
        $promise->wait();
        unset($promise);

        self::assertSame(1, $cleanups);
    }

    public function testDestructorReleasesHandlesOfAnUnwaitedPromise(): void
    {
        // ASYNC02-04: a promise the caller never wait()s on must still free its
        // eagerly-allocated handles when it is discarded.
        $cleanups = 0;
        $promise = $this->promise(fn(): ResponseInterface => $this->psr17->createResponse(200), $cleanups);

        self::assertSame(PromiseState::Pending, $promise->state());
        $before = $cleanups;

        unset($promise);

        // Capturing the pre-destruct count as a local keeps the delta comparison a
        // plain `int` (not a flow-narrowed literal), so it stays analyzer-clean.
        self::assertSame($before + 1, $cleanups, 'The destructor must free the handles exactly once.');
    }

    /**
     * @return callable(ResponseInterface): void
     */
    private function recordResponse(): callable
    {
        return function (ResponseInterface $response): void {
            $this->observedResponse = $response;
            $this->callbackInvocations++;
        };
    }

    /**
     * @return callable(Throwable): void
     */
    private function recordError(): callable
    {
        return function (Throwable $error): void {
            $this->observedError = $error;
            $this->callbackInvocations++;
        };
    }

    private function request(): RequestInterface
    {
        return $this->psr17->createRequest('GET', 'https://example.com/');
    }
}
