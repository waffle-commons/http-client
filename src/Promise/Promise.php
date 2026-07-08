<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Promise;

use Closure;
use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;

/**
 * Non-blocking handle to a single in-flight HTTP request (ASYNC-02).
 *
 * The promise wraps one transfer that has already been registered on a multi
 * handle by {@see \Waffle\Commons\HttpClient\Client::promise()}. The actual
 * driving of the libcurl loop, the terminal-result read and the PSR-7 response
 * build all live behind the injected {@see $resolver} closure, so this class
 * holds none of the cURL machinery — only the settle state and callbacks.
 *
 * {@see self::wait()} runs the resolver exactly once: the outcome (response or
 * failure) is cached, the state transitions terminally to
 * {@see PromiseState::Fulfilled} or {@see PromiseState::Rejected}, and any
 * registered {@see self::then()} / {@see self::catch()} callbacks fire. Repeated
 * `wait()` calls replay the cached outcome without re-driving the transfer.
 *
 * The callbacks are settle notifications, not a monadic chain — they observe the
 * outcome and return nothing, so no `mixed` result type ever propagates.
 *
 * **Worker safety.** The settle state is intentionally mutable — a promise
 * transitions exactly once — but a promise is a *transient, per-request value*:
 * {@see \Waffle\Commons\HttpClient\Client::promise()} mints a fresh one for each
 * call and it is discarded once settled. It is never a resident service, so its
 * mutation leaks nothing across requests; the {@see WorkerSafe} marker tells the
 * worker-safety audit (`wfl igor`) to treat this object as transient by design.
 */
#[WorkerSafe(
    scope: 'per-request',
    reason: 'transient promise minted fresh per Client::promise() call; settles once then is discarded',
)]
final class Promise implements PromiseInterface
{
    private PromiseState $state = PromiseState::Pending;

    private ?ResponseInterface $response = null;

    private ?ClientExceptionInterface $error = null;

    /** @var list<callable(ResponseInterface): void> */
    private array $onFulfilled = [];

    /** @var list<callable(Throwable): void> */
    private array $onRejected = [];

    /** Whether {@see $cleanup} has already run, so it fires at most once. */
    private bool $cleanedUp = false;

    /**
     * @param Closure(): ResponseInterface $resolver Drives the underlying transfer to completion,
     *        returning its response or throwing a {@see ClientExceptionInterface} on failure.
     * @param Closure(): void              $cleanup  Releases the per-promise cURL handles. Run exactly
     *        once: when the promise settles, or — if {@see self::wait()} is never called — from the
     *        destructor, so an un-waited promise cannot leak handles across requests (ASYNC02-04).
     */
    public function __construct(
        private readonly Closure $resolver,
        private readonly Closure $cleanup,
    ) {}

    /**
     * Free the per-promise cURL handles if the promise was discarded before being
     * waited on, so an abandoned promise never accumulates resident state.
     */
    public function __destruct()
    {
        $this->release();
    }

    #[\Override]
    public function state(): PromiseState
    {
        return $this->state;
    }

    #[\Override]
    public function then(callable $onFulfilled): PromiseInterface
    {
        // Already fulfilled → fire immediately with the cached response. A
        // fulfilled promise always holds a response (set together in fulfil()),
        // so the non-null narrowing is captured in a local.
        $response = $this->response;
        if ($response instanceof ResponseInterface) {
            $onFulfilled($response);

            return $this;
        }

        if ($this->state === PromiseState::Pending) {
            $this->onFulfilled[] = $onFulfilled;
        }

        return $this;
    }

    #[\Override]
    public function catch(callable $onRejected): PromiseInterface
    {
        // Already rejected → fire immediately with the cached failure. A rejected
        // promise always holds an error (set together in reject()), captured in a
        // local for non-null narrowing.
        $error = $this->error;
        if ($error instanceof ClientExceptionInterface) {
            $onRejected($error);

            return $this;
        }

        if ($this->state === PromiseState::Pending) {
            $this->onRejected[] = $onRejected;
        }

        return $this;
    }

    #[\Override]
    public function wait(): ResponseInterface
    {
        $response = $this->response;
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $error = $this->error;
        if ($error instanceof ClientExceptionInterface) {
            throw $error;
        }

        try {
            $response = ($this->resolver)();
        } catch (ClientExceptionInterface $failure) {
            $this->reject($failure);

            throw $failure;
        } finally {
            // Settled (either branch): the handles are no longer needed.
            $this->release();
        }

        $this->fulfil($response);

        return $response;
    }

    private function fulfil(ResponseInterface $response): void
    {
        $this->response = $response;
        $this->state = PromiseState::Fulfilled;

        foreach ($this->onFulfilled as $callback) {
            $callback($response);
        }

        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    private function reject(ClientExceptionInterface $error): void
    {
        $this->error = $error;
        $this->state = PromiseState::Rejected;

        // Every ClientExceptionInterface is a \Throwable, matching the contract's
        // `callable(Throwable): void` rejection-callback signature.
        foreach ($this->onRejected as $callback) {
            $callback($error);
        }

        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    /** Run the handle-cleanup closure at most once (idempotent across settle + destruct). */
    private function release(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;
        ($this->cleanup)();
    }
}
