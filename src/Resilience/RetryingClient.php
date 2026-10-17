<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Resilience;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;

/**
 * Bounded retries around any PSR-18 client ([NET-02], RFC-024 §3.1, §3.3). Stateless.
 *
 * A transport failure or a `429`/`502`/`503`/`504` is retried with exponential backoff and full jitter, for
 * idempotent methods only unless the policy opts in. A numeric `Retry-After` may exceed the backoff cap; no
 * wait starts that would overrun the policy's budget, and the last response or failure is returned then.
 * Every attempt goes through the inner client again — its SSRF guard and, when the inner client is a
 * {@see CircuitBreakerClient}, its breaker, whose refusal is never retried.
 */
final readonly class RetryingClient implements ClientInterface
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
        $retryable =
            $this->policy->retryNonIdempotent || in_array(strtoupper($request->getMethod()), self::IDEMPOTENT, true);
        $started = hrtime(true);
        $attempts = $retryable ? $this->policy->maxAttempts : 1;

        for ($attempt = 1; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->inner->sendRequest($request);
            } catch (NetworkExceptionInterface $failure) {
                $delay = $this->backoff($attempt);
                if ($this->overruns($started, $delay)) {
                    throw $failure;
                }

                $this->wait($delay);
                continue;
            }

            if (!in_array($response->getStatusCode(), RetryPolicy::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $delay = self::retryAfterMs($response) ?? $this->backoff($attempt);
            if ($this->overruns($started, $delay)) {
                return $response;
            }

            $this->wait($delay);
        }

        // The last allowed attempt: its response or its failure is final.
        return $this->inner->sendRequest($request);
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
