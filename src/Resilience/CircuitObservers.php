<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Resilience;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waffle\Commons\Contracts\EventDispatcher\EventDispatcherInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;

/**
 * Where a circuit breaker reports ([NET-03], RFC-024 §3.2): transitions as PSR-14 events and metrics, and
 * storage outages as a warning and a metric. Every observer is optional.
 */
final readonly class CircuitObservers
{
    public function __construct(
        public ?EventDispatcherInterface $events = null,
        public ?MetricsRegistryInterface $metrics = null,
        public LoggerInterface $logger = new NullLogger(),
    ) {}
}
