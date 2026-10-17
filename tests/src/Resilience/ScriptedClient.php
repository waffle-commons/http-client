<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\HttpClient\Exception\NetworkException;

/**
 * A PSR-18 client that plays back scripted outcomes, one per call, and counts the calls.
 */
final class ScriptedClient implements ClientInterface
{
    public private(set) int $calls = 0;

    /**
     * @param list<ResponseInterface|NetworkException> $outcomes
     */
    public function __construct(
        private readonly array $outcomes,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $outcome = $this->outcomes[$this->calls] ?? null;
        $this->calls++;

        if ($outcome instanceof NetworkException) {
            throw $outcome;
        }

        return $outcome ?? throw new LogicException('No scripted outcome left.');
    }
}
