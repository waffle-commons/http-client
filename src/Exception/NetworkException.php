<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Exception;

use Psr\Http\Message\RequestInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Exception\NetworkExceptionInterface;

final class NetworkException extends HttpClientException implements NetworkExceptionInterface
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
