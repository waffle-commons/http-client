<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;

/**
 * A concurrent client that settles a whole batch ([NET-02], [NET-03], RFC-024 §3.3): every request ends with
 * its response or with the exception its transfer failed with, and none of them is thrown.
 *
 * The fail-fast {@see ConcurrentClientInterface::sendRequests()} throws the first failure and drops the other
 * outcomes. The resilience decorators need all of them, since the breaker counts each request's success or
 * failure and the retry policy sends again only what failed. They settle through this interface, and send one
 * request at a time to a client that does not implement it.
 */
interface SettlingClientInterface extends ConcurrentClientInterface
{
    /**
     * Resolve a batch concurrently and settle every request.
     *
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface|ClientExceptionInterface> One outcome per request, in the
     *         batch's key order.
     */
    public function settleRequests(array $requests): array;
}
