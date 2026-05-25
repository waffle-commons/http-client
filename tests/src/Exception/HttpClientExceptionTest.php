<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\Exception\HttpClientExceptionInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use WaffleTests\Commons\HttpClient\AbstractTestCase;

#[CoversClass(HttpClientException::class)]
final class HttpClientExceptionTest extends AbstractTestCase
{
    public function testItExtendsRuntimeException(): void
    {
        $exception = new HttpClientException('boom');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('boom', $exception->getMessage());
    }

    public function testItImplementsBothPsr18AndWaffleMarkers(): void
    {
        $exception = new HttpClientException();

        self::assertInstanceOf(ClientExceptionInterface::class, $exception);
        self::assertInstanceOf(HttpClientExceptionInterface::class, $exception);
    }
}
