<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Mutable per-request transfer state for a concurrent / promise-driven request.
 *
 * Bundles a dedicated easy {@see CurlHandle} with the buffers libcurl fills as
 * the transfer progresses: the streamed response {@see $body}, the final HTTP
 * {@see $statusLine}, and the collected {@see $headers}. The status line and
 * header map are written by reference from the cURL header callback, so this
 * holder is deliberately *not* readonly. Instances live only for the duration
 * of one {@see Client::sendRequests()} / {@see Client::promise()} call and never
 * outlive the request, so they introduce no resident cross-request state.
 *
 * @internal
 */
final class Transfer
{
    /** Final HTTP status line, populated by the cURL header callback. */
    public string $statusLine = '';

    /**
     * Collected response headers, populated by the cURL header callback.
     *
     * @var array<string, list<string>>
     */
    public array $headers = [];

    public function __construct(
        public CurlHandle $handle,
        public RequestInterface $request,
        public StreamInterface $body,
    ) {}
}
