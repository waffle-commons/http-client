<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Exception;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\NetworkExceptionInterface as PsrNetworkExceptionInterface;
use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\Exception\NetworkExceptionInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(NetworkException::class)]
final class NetworkExceptionTest extends AbstractTestCase
{
    public function testGetRequestReturnsTheStoredRequest(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('GET', 'https://slow.example.com/');
        $previous = new RuntimeException('socket reset');

        $exception = new NetworkException($request, 'timeout', 28, $previous);

        self::assertSame($request, $exception->getRequest());
        self::assertSame('timeout', $exception->getMessage());
        self::assertSame(28, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testItImplementsBothPsr18AndWaffleMarkers(): void
    {
        $psr17 = new Psr17Factory();
        $exception = new NetworkException($psr17->createRequest('GET', 'https://example.com/'));

        self::assertInstanceOf(HttpClientException::class, $exception);
        self::assertInstanceOf(PsrNetworkExceptionInterface::class, $exception);
        self::assertInstanceOf(NetworkExceptionInterface::class, $exception);
    }
}
