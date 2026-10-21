<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Resilience;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitDecision;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Contracts\CircuitBreaker\Event\CircuitStateChanged;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\Contracts\RateLimit\Exception\StateStorageExceptionInterface;
use Waffle\Commons\HttpClient\Exception\CircuitOpenException;
use Waffle\Commons\HttpClient\SettlingClientInterface;

/**
 * A circuit breaker around any PSR-18 client ([NET-03], RFC-024 §3.2). Stateless: the circuit lives in the
 * shared storage, keyed by the target origin (scheme, host, port).
 *
 * An open circuit fails fast with a {@see CircuitOpenException} (503) before any connection. A transport
 * failure or a 5xx response counts as a failure. When the storage itself is unavailable, the breaker
 * behaves as closed, with a warning and a metric (RFC-024 §5.2). Put it inside a {@see RetryingClient} so
 * every attempt is checked.
 *
 * The concurrent path checks every request's circuit before any of them is sent, then sends the admitted
 * ones together (RFC-024 §3.3): a refused request settles as its {@see CircuitOpenException}, and a promise
 * runs the whole check when it is waited on.
 */
final readonly class CircuitBreakerClient implements ClientInterface, SettlingClientInterface
{
    public const string TRANSITIONS_METRIC = 'waffle_circuit_transitions_total';

    public const string STATE_METRIC = 'waffle_circuit_state';

    public const string STORAGE_ERRORS_METRIC = 'waffle_circuit_storage_errors_total';

    public function __construct(
        private ClientInterface $inner,
        private CircuitStateStorageInterface $storage,
        private CircuitBreakerPolicy $policy = new CircuitBreakerPolicy(),
        private CircuitObservers $observers = new CircuitObservers(),
    ) {}

    /**
     * @throws CircuitOpenException When the circuit of the target origin is open.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $circuit = self::circuitOf($request);
        if (!$this->admits($circuit)) {
            throw CircuitOpenException::for($circuit);
        }

        $outcome = Settlement::one($this->inner, $request);
        $this->recordOutcome($circuit, $outcome);

        return Settlement::unwrap($outcome);
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
        $admitted = [];
        foreach ($requests as $key => $request) {
            if (!$this->admits(self::circuitOf($request))) {
                continue;
            }

            $admitted[$key] = $request;
        }

        $sent = Settlement::through($this->inner, $admitted);

        $outcomes = [];
        foreach ($requests as $key => $request) {
            $circuit = self::circuitOf($request);
            if (!array_key_exists($key, $admitted)) {
                $outcomes[$key] = CircuitOpenException::for($circuit);
                continue;
            }

            $outcome = $sent[$key] ?? Settlement::unsettled($request);
            $this->recordOutcome($circuit, $outcome);
            $outcomes[$key] = $outcome;
        }

        return $outcomes;
    }

    #[\Override]
    public function promise(RequestInterface $request): PromiseInterface
    {
        return Settlement::deferred($this, $request);
    }

    /** Whether the circuit lets a call through now; an unavailable storage lets it through. */
    private function admits(string $circuit): bool
    {
        try {
            $permission = $this->storage->acquire($circuit, $this->policy);
        } catch (StateStorageExceptionInterface $e) {
            $this->storageUnavailable($circuit, $e);

            return true;
        }

        $this->observe($circuit, $permission);

        return $permission->allowed;
    }

    /**
     * A response below 500 is a success and a transport failure is a failure. Any other client failure (an
     * SSRF refusal, a malformed request) says nothing about the upstream and is not counted.
     */
    private function recordOutcome(string $circuit, ResponseInterface|ClientExceptionInterface $outcome): void
    {
        if ($outcome instanceof ResponseInterface) {
            $this->record($circuit, $outcome->getStatusCode() < 500);
        } elseif ($outcome instanceof NetworkExceptionInterface) {
            $this->record($circuit, false);
        }
    }

    private function record(string $circuit, bool $success): void
    {
        try {
            $this->observe($circuit, $this->storage->record($circuit, $this->policy, $success));
        } catch (StateStorageExceptionInterface $e) {
            $this->storageUnavailable($circuit, $e);
        }
    }

    private function observe(string $circuit, CircuitDecision $decision): void
    {
        $metrics = $this->observers->metrics;
        $metrics?->gauge(self::STATE_METRIC, self::level($decision->state), ['circuit' => $circuit]);

        if (!$decision->isTransition() || $decision->previous === null) {
            return;
        }

        $metrics?->increment(self::TRANSITIONS_METRIC, 1.0, ['circuit' => $circuit, 'to' => $decision->state->value]);
        $this->observers->events?->dispatch(new CircuitStateChanged($circuit, $decision->previous, $decision->state));
    }

    private function storageUnavailable(string $circuit, StateStorageExceptionInterface $e): void
    {
        $this->observers->metrics?->increment(self::STORAGE_ERRORS_METRIC, 1.0, ['circuit' => $circuit]);
        $this->observers->logger->warning('Circuit storage unavailable for {circuit}; the breaker behaves as closed.', [
            'circuit' => $circuit,
            'exception' => $e,
        ]);
    }

    private static function level(CircuitState $state): float
    {
        return match ($state) {
            CircuitState::Closed => 0.0,
            CircuitState::HalfOpen => 1.0,
            CircuitState::Open => 2.0,
        };
    }

    private static function circuitOf(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() === '' ? 'http' : $uri->getScheme();
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . $uri->getHost() . ':' . $port;
    }
}
