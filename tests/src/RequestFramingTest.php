<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\HttpClient\RequestFraming;

/**
 * One framing per message (RFC 9112 §6.1): the body length comes from the stream, else from a single
 * declared Content-Length, and the caller's framing headers never go out beside libcurl's own.
 */
#[CoversClass(RequestFraming::class)]
final class RequestFramingTest extends AbstractTestCase
{
    private Psr17Factory $psr17;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->psr17 = new Psr17Factory();
    }

    public function testASizedStreamGivesItsOwnLength(): void
    {
        $request = $this->psr17
            ->createRequest('POST', 'https://example.com/orders')
            ->withHeader('Content-Length', '999')
            ->withBody($this->psr17->createStream('{"order":42}'));

        // The stream is what goes out: a declared length that disagrees with it never wins.
        self::assertSame(12, RequestFraming::bodyLength($request));
    }

    public function testAnUnsizedStreamTakesTheDeclaredLength(): void
    {
        // php://input in a FrankenPHP worker: the stream cannot tell, the client that sent it did.
        self::assertSame(11, RequestFraming::bodyLength($this->unsized('POST')->withHeader('Content-Length', '11')));
        self::assertSame(0, RequestFraming::bodyLength($this->unsized('POST')->withHeader('Content-Length', '0')));
    }

    public function testAnUnsizedStreamWithoutAUsableDeclaredLengthIsChunked(): void
    {
        self::assertNull(RequestFraming::bodyLength($this->unsized('POST')));

        foreach (['', 'eleven', '-1', '1.5'] as $declared) {
            self::assertNull(
                RequestFraming::bodyLength($this->unsized('POST')->withHeader('Content-Length', $declared)),
                sprintf('"%s" is not a length', $declared),
            );
        }

        // Two values (even equal ones) are no single framing.
        self::assertNull(RequestFraming::bodyLength($this->unsized('POST')->withHeader('Content-Length', [
            '11',
            '11',
        ])));
    }

    public function testAnUploadLeavesTheFramingHeadersToLibcurl(): void
    {
        $request = $this
            ->unsized('PUT')
            ->withHeader('Content-Length', '11')
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Content-Type', 'application/json');

        foreach ([11, null] as $length) {
            self::assertSame(
                ['Host: example.com', 'Content-Type: application/json'],
                RequestFraming::headerLines($request, $length),
                'the caller must not frame a body libcurl frames',
            );
        }
    }

    public function testARequestWithoutABodyKeepsItsHeaders(): void
    {
        $request = $this->unsized('POST')->withHeader('Content-Length', '0')->withHeader('Accept', 'text/plain');

        self::assertSame(
            ['Host: example.com', 'Content-Length: 0', 'Accept: text/plain'],
            RequestFraming::headerLines($request, 0),
        );
    }

    public function testRepeatedValuesStayOnTheirOwnLines(): void
    {
        $request = $this->psr17->createRequest('GET', 'https://example.com/')->withHeader('X-Tag', ['a', 'b']);

        self::assertSame(['Host: example.com', 'X-Tag: a', 'X-Tag: b'], RequestFraming::headerLines($request, 0));
    }

    private function unsized(string $method): RequestInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);

        return $this->psr17->createRequest($method, 'https://example.com/legacy')->withBody($stream);
    }
}
