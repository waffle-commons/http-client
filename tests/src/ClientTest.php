<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Closure;
use CurlHandle;
use Nyholm\Psr7\Factory\Psr17Factory;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;

#[CoversClass(Client::class)]
final class ClientTest extends AbstractTestCase
{
    use PHPMock;

    private Psr17Factory $psr17;

    /** @var array<int, mixed> */
    private array $capturedOptions = [];

    /** @var array<int, mixed> */
    private array $capturedScalarOptions = [];

    private ?Closure $headerCallback = null;

    private ?Closure $writeCallback = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->psr17 = new Psr17Factory();
        $this->capturedOptions = [];
        $this->capturedScalarOptions = [];
        $this->headerCallback = null;
        $this->writeCallback = null;
    }

    public function testConstructorThrowsWhenCurlInitFails(): void
    {
        $init = $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_init');
        $init->expects($this->once())->willReturn(false);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Failed to initialise the underlying cURL handle.');

        new Client($this->psr17, $this->psr17);
    }

    public function testSendRequestProducesPsr7Response(): void
    {
        $this->primeSuccessfulExchange(headerLines: [
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: text/plain\r\n",
            "Server: legacy\r\n",
            "\r\n",
        ], bodyChunks: ['Hello, ', 'World!']);

        $response = $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('text/plain', $response->getHeaderLine('content-type'));
        self::assertSame('legacy', $response->getHeaderLine('server'));
        self::assertSame('Hello, World!', (string) $response->getBody());
    }

    public function testMultiValueSetCookieHeadersArePreserved(): void
    {
        $this->primeSuccessfulExchange(headerLines: [
            "HTTP/1.1 200 OK\r\n",
            "Set-Cookie: a=1; Path=/\r\n",
            "Set-Cookie: b=2; Secure\r\n",
            "\r\n",
        ], bodyChunks: []);

        $response = $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertSame(['a=1; Path=/', 'b=2; Secure'], $response->getHeader('set-cookie'));
    }

    public function testResponseBodyIsStreamedInChunks(): void
    {
        $chunk = str_repeat('A', Client::CHUNK_SIZE);
        $this->primeSuccessfulExchange(headerLines: ["HTTP/1.1 200 OK\r\n", "\r\n"], bodyChunks: [
            $chunk,
            $chunk,
            'tail',
        ]);

        $response = $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertSame((Client::CHUNK_SIZE * 2) + 4, $response->getBody()->getSize());
        self::assertSame($chunk . $chunk . 'tail', (string) $response->getBody());
        self::assertSame(Client::CHUNK_SIZE, $this->capturedOptions[CURLOPT_BUFFERSIZE]);
    }

    public function testHardcodedTimeoutsAndTlsDefaultsAreApplied(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.1 204 No Content\r\n", "\r\n"], []);

        $this->dispatch($this->psr17->createRequest('DELETE', 'https://example.com/x'));

        self::assertSame(1_000, $this->capturedOptions[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(10_000, $this->capturedOptions[CURLOPT_TIMEOUT_MS]);
        self::assertTrue($this->capturedOptions[CURLOPT_NOSIGNAL]);
        self::assertTrue($this->capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $this->capturedOptions[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($this->capturedOptions[CURLOPT_FOLLOWLOCATION]);
        self::assertFalse($this->capturedOptions[CURLOPT_RETURNTRANSFER]);
        self::assertFalse($this->capturedOptions[CURLOPT_HEADER]);
    }

    public function testHttp2ProtocolVersionIsApplied(): void
    {
        $this->primeSuccessfulExchange(["HTTP/2 200 \r\n", "\r\n"], []);

        $request = $this->psr17->createRequest('GET', 'https://example.com/')->withProtocolVersion('2.0');
        $this->dispatch($request);

        self::assertSame(CURL_HTTP_VERSION_2_0, $this->capturedOptions[CURLOPT_HTTP_VERSION]);
    }

    public function testHttp10ProtocolVersionIsApplied(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.0 200 OK\r\n", "\r\n"], []);

        $request = $this->psr17->createRequest('GET', 'https://example.com/')->withProtocolVersion('1.0');
        $response = $this->dispatch($request);

        self::assertSame(CURL_HTTP_VERSION_1_0, $this->capturedOptions[CURLOPT_HTTP_VERSION]);
        self::assertSame('1.0', $response->getProtocolVersion());
    }

    public function testRequestHeadersAreForwarded(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.1 200 OK\r\n", "\r\n"], []);

        $request = $this->psr17
            ->createRequest('GET', 'https://example.com/')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Trace', 'abc-123');

        $this->dispatch($request);

        /** @var list<string> $forwarded */
        $forwarded = $this->capturedOptions[CURLOPT_HTTPHEADER];
        self::assertContains('Accept: application/json', $forwarded);
        self::assertContains('X-Trace: abc-123', $forwarded);
    }

    public function testRequestBodyIsForwardedAsPostFields(): void
    {
        $payload = '{"order":42}';
        $this->primeSuccessfulExchange(["HTTP/1.1 201 Created\r\n", "\r\n"], []);
        $this->mockCurlSetoptCapturingOption();

        $request = $this->psr17
            ->createRequest('POST', 'https://example.com/orders')
            ->withBody($this->psr17->createStream($payload));
        $this->dispatch($request);

        self::assertSame($payload, $this->capturedScalarOptions[CURLOPT_POSTFIELDS] ?? null);
    }

    public function testEmptyRequestBodyDoesNotSetPostFields(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.1 200 OK\r\n", "\r\n"], []);
        $this->mockCurlSetoptCapturingOption();

        $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $this->capturedScalarOptions);
    }

    public function testNoStatusLineThrowsRequestException(): void
    {
        $this->primeSuccessfulExchange(headerLines: [], bodyChunks: []);

        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        try {
            $this->dispatch($request);
            self::fail('Expected RequestException');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertStringContainsString('No HTTP status line', $exception->getMessage());
        }
    }

    public function testNetworkErrorMapsToNetworkException(): void
    {
        $this->mockCurlSetoptArrayCapturingOptions();
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_reset')->expects($this->once());
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_exec')->expects($this->once())->willReturn(false);
        $this
            ->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_errno')
            ->expects($this->once())
            ->willReturn(CURLE_OPERATION_TIMEOUTED);
        $this
            ->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_error')
            ->expects($this->once())
            ->willReturn('Operation timed out');

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://slow.example.com/');

        try {
            $client->sendRequest($request);
            self::fail('Expected NetworkException');
        } catch (NetworkException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame(CURLE_OPERATION_TIMEOUTED, $exception->getCode());
            self::assertSame('Operation timed out', $exception->getMessage());
        }
    }

    public function testRequestErrorMapsToRequestException(): void
    {
        $this->mockCurlSetoptArrayCapturingOptions();
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_reset')->expects($this->once());
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_exec')->expects($this->once())->willReturn(false);
        $this
            ->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_errno')
            ->expects($this->once())
            ->willReturn(CURLE_URL_MALFORMAT);
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_error')->expects($this->once())->willReturn('');

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        try {
            $client->sendRequest($request);
            self::fail('Expected RequestException');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame(CURLE_URL_MALFORMAT, $exception->getCode());
            self::assertStringContainsString('cURL error #' . CURLE_URL_MALFORMAT, $exception->getMessage());
        }
    }

    public function testPersistentHandleIsReusedAcrossRequests(): void
    {
        $init = $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_init');
        $init->expects($this->once())->willReturnCallback(static fn(): CurlHandle|false => \curl_init());

        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_reset')->expects($this->exactly(2));

        $this->mockCurlSetoptArrayCapturingOptions();
        $this->primeCurlExec(["HTTP/1.1 200 OK\r\n", "\r\n"], []);

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        $client->sendRequest($request);
        $client->sendRequest($request);
    }

    public function testHeaderLineWithoutColonIsIgnored(): void
    {
        $this->primeSuccessfulExchange(headerLines: [
            "HTTP/1.1 200 OK\r\n",
            "Garbage-Without-Colon\r\n",
            "Content-Type: text/plain\r\n",
            "\r\n",
        ], bodyChunks: []);

        $response = $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertFalse($response->hasHeader('Garbage-Without-Colon'));
        self::assertSame('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testRedirectStatusLinesRetainOnlyFinal(): void
    {
        $this->primeSuccessfulExchange(headerLines: [
            "HTTP/1.1 301 Moved Permanently\r\n",
            "Location: https://example.com/final\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: text/plain\r\n",
            "\r\n",
        ], bodyChunks: ['final-body']);

        $response = $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('content-type'));
        self::assertFalse($response->hasHeader('location'));
        self::assertSame('final-body', (string) $response->getBody());
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $client = new Client($this->psr17, $this->psr17);
        return $client->sendRequest($request);
    }

    /**
     * @param list<string> $headerLines Raw libcurl header lines, status lines included, CRLF-terminated.
     * @param list<string> $bodyChunks  Body fragments passed to the write callback in order.
     */
    private function primeSuccessfulExchange(array $headerLines, array $bodyChunks): void
    {
        $this->mockCurlSetoptArrayCapturingOptions();
        $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_reset')->expects($this->any());
        $this->primeCurlExec($headerLines, $bodyChunks);
    }

    private function mockCurlSetoptArrayCapturingOptions(): void
    {
        $setoptArray = $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_setopt_array');
        $setoptArray
            ->expects($this->any())
            ->willReturnCallback(
                /**
                 * @param array<int, mixed> $options
                 */
                function (CurlHandle $_h, array $options): bool {
                    foreach ($options as $option => $value) {
                        $this->capturedOptions[$option] = $value;
                    }
                    $header = $options[CURLOPT_HEADERFUNCTION] ?? null;
                    $write = $options[CURLOPT_WRITEFUNCTION] ?? null;
                    if ($header instanceof Closure) {
                        $this->headerCallback = $header;
                    }
                    if ($write instanceof Closure) {
                        $this->writeCallback = $write;
                    }
                    return true;
                },
            );
    }

    private function mockCurlSetoptCapturingOption(): void
    {
        $setopt = $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_setopt');
        $setopt
            ->expects($this->any())
            ->willReturnCallback(function (CurlHandle $_h, int $option, mixed $value): bool {
                $this->capturedScalarOptions[$option] = $value;
                return true;
            });
    }

    /**
     * @param list<string> $headerLines
     * @param list<string> $bodyChunks
     */
    private function primeCurlExec(array $headerLines, array $bodyChunks): void
    {
        $exec = $this->getFunctionMock('Waffle\\Commons\\HttpClient', 'curl_exec');
        $exec->expects($this->any())->willReturnCallback(function (CurlHandle $handle) use (
            $headerLines,
            $bodyChunks,
        ): bool {
            $headerFn = $this->headerCallback;
            $writeFn = $this->writeCallback;
            if (!$headerFn instanceof Closure || !$writeFn instanceof Closure) {
                self::fail('cURL header/write callbacks were not captured during curl_setopt_array.');
            }
            foreach ($headerLines as $line) {
                $headerFn($handle, $line);
            }
            foreach ($bodyChunks as $chunk) {
                $writeFn($handle, $chunk);
            }
            return true;
        });
    }
}
