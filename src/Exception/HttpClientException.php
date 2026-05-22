<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Exception;

use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\Exception\HttpClientExceptionInterface;

class HttpClientException extends RuntimeException implements HttpClientExceptionInterface {}
