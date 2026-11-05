<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Exception;

use Waffle\Commons\Contracts\CircuitBreaker\Exception\CircuitOpenExceptionInterface;

/**
 * The circuit of the target origin is open ([NET-03]): the call failed fast, before any connection. The
 * code is the HTTP status the error handler renders.
 */
final class CircuitOpenException extends HttpClientException implements CircuitOpenExceptionInterface
{
    public static function for(string $circuit): self
    {
        return new self('The circuit for ' . $circuit . ' is open: the call was not attempted.', 503);
    }
}
