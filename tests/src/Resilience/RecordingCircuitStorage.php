<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitDecision;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;

/**
 * Circuit storage that refuses the circuits it is told are open, admits every other one, and records each
 * circuit asked for and each outcome recorded, in order.
 */
final class RecordingCircuitStorage implements CircuitStateStorageInterface
{
    /** @var list<string> */
    public private(set) array $acquired = [];

    /** @var list<array{string, bool}> */
    public private(set) array $recorded = [];

    /**
     * @param list<string> $open
     */
    public function __construct(
        private readonly array $open = [],
    ) {}

    #[\Override]
    public function acquire(string $circuit, CircuitBreakerPolicy $policy): CircuitDecision
    {
        $this->acquired[] = $circuit;
        $refused = in_array($circuit, $this->open, true);

        return new CircuitDecision(!$refused, $refused ? CircuitState::Open : CircuitState::Closed);
    }

    #[\Override]
    public function record(string $circuit, CircuitBreakerPolicy $policy, bool $success): CircuitDecision
    {
        $this->recorded[] = [$circuit, $success];

        return new CircuitDecision(true, CircuitState::Closed);
    }
}
