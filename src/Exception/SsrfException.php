<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Exception;

use Psr\Http\Message\RequestInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Exception\RequestExceptionInterface;

/**
 * Raised when the SSRF guard refuses an outbound request because the target
 * host is missing, unresolvable, or resolves to a non-public IP address
 * (SEC-02). A PSR-18 request exception — `getRequest()` returns the offending
 * request — so callers catch it through the standard client-exception surface.
 */
final class SsrfException extends HttpClientException implements RequestExceptionInterface
{
    public function __construct(
        public private(set) RequestInterface $request,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
