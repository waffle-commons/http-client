<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Exception;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\RequestExceptionInterface as PsrRequestExceptionInterface;
use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\Exception\RequestExceptionInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(RequestException::class)]
final class RequestExceptionTest extends AbstractTestCase
{
    public function testGetRequestReturnsTheStoredRequest(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('PATCH', 'https://api.example.com/orders/1');
        $previous = new RuntimeException('cause');

        $exception = new RequestException($request, 'bad protocol', 42, $previous);

        self::assertSame($request, $exception->getRequest());
        self::assertSame('bad protocol', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testItImplementsBothPsr18AndWaffleMarkers(): void
    {
        $psr17 = new Psr17Factory();
        $exception = new RequestException($psr17->createRequest('GET', 'https://example.com/'));

        self::assertInstanceOf(HttpClientException::class, $exception);
        self::assertInstanceOf(PsrRequestExceptionInterface::class, $exception);
        self::assertInstanceOf(RequestExceptionInterface::class, $exception);
    }
}
