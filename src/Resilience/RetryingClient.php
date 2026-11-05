<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Resilience;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\SettlingClientInterface;

/**
 * Bounded retries around any PSR-18 client ([NET-02], RFC-024 §3.1, §3.3). Stateless.
 *
 * A transport failure or a `429`/`502`/`503`/`504` is retried with exponential backoff and full jitter, for
 * idempotent methods only unless the policy opts in. A numeric `Retry-After` may exceed the backoff cap; no
 * wait starts that would overrun the policy's budget, and the last response or failure is returned then.
 * Every attempt goes through the inner client again — its SSRF guard and, when the inner client is a
 * {@see CircuitBreakerClient}, its breaker, whose refusal is never retried.
 *
 * A concurrent batch retries in waves: each request keeps its own attempts, backoff, `Retry-After` and
 * budget, and the requests to retry go out again together once the longest of their waits has passed. A
 * promise runs the retries when it is waited on.
 */
final readonly class RetryingClient implements ClientInterface, SettlingClientInterface
{
    private const array IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /**
     * @param (Closure(int): void)|null $sleep Waits the given milliseconds; `usleep` when null.
     */
    public function __construct(
        private ClientInterface $inner,
        private RetryPolicy $policy = new RetryPolicy(),
        private ?Closure $sleep = null,
    ) {}

    /** A copy with another policy: the per-request override is an immutable wither. */
    public function withPolicy(RetryPolicy $policy): self
    {
        return clone($this, ['policy' => $policy]);
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        $attempts = $this->attemptsFor($request);

        for ($attempt = 1; $attempt < $attempts; $attempt++) {
            $outcome = Settlement::one($this->inner, $request);
            $delay = $this->delayAfter($outcome, $attempt, $started);
            if ($delay === null) {
                return Settlement::unwrap($outcome);
            }

            $this->wait($delay);
        }

        // The last allowed attempt: its response or its failure is final.
        return $this->inner->sendRequest($request);
    }

    /**
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface>
     *
     * @throws ClientExceptionInterface The first failure in key order, once every request has settled.
     */
    #[\Override]
    public function sendRequests(array $requests): array
    {
        return Settlement::responsesOrThrow($this->settleRequests($requests));
    }

    /**
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface|ClientExceptionInterface>
     */
    #[\Override]
    public function settleRequests(array $requests): array
    {
        $started = hrtime(true);
        $outcomes = [];
        $pending = $requests;

        for ($attempt = 1; $pending !== []; $attempt++) {
            $settled = Settlement::through($this->inner, $pending);
            $retries = [];
            $wait = 0;
            foreach ($pending as $key => $request) {
                // The first wave fixes the batch's key order; a retry overwrites its slot in place.
                $outcome = $settled[$key] ?? Settlement::unsettled($request);
                $outcomes[$key] = $outcome;

                $delay = $attempt < $this->attemptsFor($request)
                    ? $this->delayAfter($outcome, $attempt, $started)
                    : null;
                if ($delay !== null) {
                    $retries[$key] = $request;
                    $wait = max($wait, $delay);
                }
            }

            if ($retries !== []) {
                $this->wait($wait);
            }

            $pending = $retries;
        }

        return $outcomes;
    }

    #[\Override]
    public function promise(RequestInterface $request): PromiseInterface
    {
        return Settlement::deferred($this, $request);
    }

    private function attemptsFor(RequestInterface $request): int
    {
        $retryable =
            $this->policy->retryNonIdempotent || in_array(strtoupper($request->getMethod()), self::IDEMPOTENT, true);

        return $retryable ? $this->policy->maxAttempts : 1;
    }

    /** The wait before `$outcome` is retried, or null when it is final. */
    private function delayAfter(
        ResponseInterface|ClientExceptionInterface $outcome,
        int $attempt,
        int|float $started,
    ): ?int {
        if ($outcome instanceof NetworkExceptionInterface) {
            $delay = $this->backoff($attempt);
        } elseif (
            $outcome instanceof ResponseInterface
            && in_array($outcome->getStatusCode(), RetryPolicy::RETRYABLE_STATUSES, true)
        ) {
            $delay = self::retryAfterMs($outcome) ?? $this->backoff($attempt);
        } else {
            return null;
        }

        return $this->overruns($started, $delay) ? null : $delay;
    }

    private function backoff(int $attempt): int
    {
        $ceiling = min($this->policy->maxDelayMs, $this->policy->baseDelayMs * (2 ** ($attempt - 1)));

        return mt_rand(0, $ceiling);
    }

    private function overruns(int|float $started, int $delayMs): bool
    {
        return (((hrtime(true) - $started) / 1_000_000) + $delayMs) > $this->policy->budgetMs;
    }

    private function wait(int $milliseconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($milliseconds);

            return;
        }

        if ($milliseconds < 1) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    private static function retryAfterMs(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value * 1000;
    }
}
