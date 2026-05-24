<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use Nyholm\Psr7\Factory\Psr17Factory;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;

#[CoversClass(Client::class)]
final class ClientTest extends AbstractTestCase
{
    use PHPMock;

    private const string NS = 'Waffle\\Commons\\HttpClient';

    private Psr17Factory $psr17;

    /** @var array<int, mixed> */
    private array $capturedOptions = [];

    /** @var array<int, mixed> */
    private array $capturedScalarOptions = [];

    private ?Closure $headerCallback = null;

    private ?Closure $writeCallback = null;

    private ?CurlHandle $capturedHandle = null;

    /** Per-request `curl_multi_exec` tick counter (reset on each add_handle). */
    private int $execStage = 0;

    /** Whether the single CURLMSG_DONE message has been consumed this request. */
    private bool $infoRead = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->psr17 = new Psr17Factory();
        $this->capturedOptions = [];
        $this->capturedScalarOptions = [];
        $this->headerCallback = null;
        $this->writeCallback = null;
        $this->capturedHandle = null;
        $this->execStage = 0;
        $this->infoRead = false;
    }

    public function testConstructorThrowsWhenCurlInitFails(): void
    {
        $init = $this->getFunctionMock(self::NS, 'curl_init');
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

    public function testProtocolAllowlistRestrictedToHttpAndHttps(): void
    {
        // SEC-03: cURL must refuse non-http(s) schemes even for redirects, blocking
        // SSRF pivots via file://, gopher://, dict://, ldap://, etc.
        $this->primeSuccessfulExchange(["HTTP/1.1 200 OK\r\n", "\r\n"], []);

        $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        $expected = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        self::assertSame($expected, $this->capturedOptions[CURLOPT_PROTOCOLS]);
        self::assertSame($expected, $this->capturedOptions[CURLOPT_REDIR_PROTOCOLS]);
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

    public function testRequestBodyIsStreamedWithKnownLength(): void
    {
        $payload = '{"order":42}';
        $this->primeSuccessfulExchange(["HTTP/1.1 201 Created\r\n", "\r\n"], []);
        $this->mockCurlSetoptCapturingOption();

        $request = $this->psr17
            ->createRequest('POST', 'https://example.com/orders')
            ->withBody($this->psr17->createStream($payload));
        $this->dispatch($request);

        // Streamed upload — never buffered as a single POSTFIELDS string.
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $this->capturedScalarOptions);
        self::assertTrue($this->capturedScalarOptions[CURLOPT_UPLOAD] ?? null);
        self::assertSame(strlen($payload), $this->capturedScalarOptions[CURLOPT_INFILESIZE] ?? null);

        // The read callback pulls the body in chunks and signals EOF with ''.
        $read = $this->capturedScalarOptions[CURLOPT_READFUNCTION] ?? null;
        self::assertInstanceOf(Closure::class, $read);
        $handle = $this->capturedHandle;
        self::assertInstanceOf(CurlHandle::class, $handle);
        self::assertSame($payload, $read($handle, null, Client::CHUNK_SIZE));
        self::assertSame('', $read($handle, null, Client::CHUNK_SIZE));
        self::assertSame('', $read($handle, null, Client::CHUNK_SIZE));
    }

    public function testRequestBodyWithUnknownLengthUsesChunkedUpload(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.1 200 OK\r\n", "\r\n"], []);
        $this->mockCurlSetoptCapturingOption();

        // A non-seekable stream of unknown size — the gateway must NOT advertise a
        // Content-Length; libcurl falls back to Transfer-Encoding: chunked.
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(false);
        $stream->expects($this->never())->method('rewind');

        $request = $this->psr17->createRequest('POST', 'https://example.com/upload')->withBody($stream);
        $this->dispatch($request);

        self::assertTrue($this->capturedScalarOptions[CURLOPT_UPLOAD] ?? null);
        self::assertArrayNotHasKey(CURLOPT_INFILESIZE, $this->capturedScalarOptions);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $this->capturedScalarOptions);
    }

    public function testEmptyRequestBodyDoesNotStream(): void
    {
        $this->primeSuccessfulExchange(["HTTP/1.1 200 OK\r\n", "\r\n"], []);
        $this->mockCurlSetoptCapturingOption();

        $this->dispatch($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertArrayNotHasKey(CURLOPT_UPLOAD, $this->capturedScalarOptions);
        self::assertArrayNotHasKey(CURLOPT_READFUNCTION, $this->capturedScalarOptions);
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
        $this->mockTransfer([], [], CURLE_OPERATION_TIMEOUTED);
        $this->getFunctionMock(self::NS, 'curl_error')->expects($this->any())->willReturn('Operation timed out');

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
        $this->mockTransfer([], [], CURLE_URL_MALFORMAT);
        $this->getFunctionMock(self::NS, 'curl_error')->expects($this->any())->willReturn('');

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

    public function testMultiHandleFailureMapsToNetworkException(): void
    {
        $this->mockTransfer(["HTTP/1.1 200 OK\r\n", "\r\n"], [], CURLE_OK, CURLM_INTERNAL_ERROR);
        $this
            ->getFunctionMock(self::NS, 'curl_multi_strerror')
            ->expects($this->any())
            ->willReturn('multi handle exploded');

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        try {
            $client->sendRequest($request);
            self::fail('Expected NetworkException');
        } catch (NetworkException $exception) {
            self::assertSame(CURLE_RECV_ERROR, $exception->getCode());
            self::assertStringContainsString('multi handle exploded', $exception->getMessage());
        }
    }

    public function testPersistentHandleIsReusedAcrossRequests(): void
    {
        // curl_init() must run exactly once: the handle is allocated in the
        // constructor and reused (via curl_reset) on every request, never
        // recreated per call — that reuse is what keeps the keep-alive pool warm.
        $init = $this->getFunctionMock(self::NS, 'curl_init');
        $init->expects($this->once())->willReturnCallback(static fn(): CurlHandle|false => \curl_init());

        $this->mockTransfer(["HTTP/1.1 200 OK\r\n", "\r\n"], []);

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        $first = $client->sendRequest($request);
        $second = $client->sendRequest($request);

        // Two consecutive requests over the one persistent handle both succeed.
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
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
        $this->mockTransfer($headerLines, $bodyChunks);
    }

    /**
     * Wires the full `curl_multi_*` transfer surface so a `sendRequest()` call
     * runs end-to-end without touching the network. The simulated transfer ticks
     * twice (still-running → done) to exercise the `curl_multi_select()` branch.
     *
     * @param list<string> $headerLines
     * @param list<string> $bodyChunks
     * @param int          $result      Per-transfer terminal cURL result (CURLE_*).
     * @param int          $multiStatus curl_multi_exec return status (CURLM_*).
     */
    private function mockTransfer(
        array $headerLines,
        array $bodyChunks,
        int $result = CURLE_OK,
        int $multiStatus = CURLM_OK,
    ): void {
        $this->mockCurlSetoptArrayCapturingOptions();

        $this
            ->getFunctionMock(self::NS, 'curl_multi_add_handle')
            ->expects($this->any())
            ->willReturnCallback(function (CurlMultiHandle $_mh, CurlHandle $_h): int {
                // add_handle runs exactly once per execute(); reset per-request state here.
                $this->execStage = 0;
                $this->infoRead = false;
                return 0;
            });

        $this->getFunctionMock(self::NS, 'curl_multi_remove_handle')->expects($this->any())->willReturn(0);
        $this->getFunctionMock(self::NS, 'curl_multi_select')->expects($this->any())->willReturn(1);

        $this
            ->getFunctionMock(self::NS, 'curl_multi_exec')
            ->expects($this->any())
            ->willReturnCallback(function (CurlMultiHandle $_mh, int &$running) use (
                $headerLines,
                $bodyChunks,
                $multiStatus,
            ): int {
                if ($multiStatus !== CURLM_OK) {
                    $running = 0;
                    return $multiStatus;
                }
                if ($this->execStage === 0) {
                    $this->execStage = 1;
                    $this->fireTransferCallbacks($headerLines, $bodyChunks);
                    $running = 1; // still running → forces a curl_multi_select() + a second tick
                    return CURLM_OK;
                }
                $running = 0;
                return CURLM_OK;
            });

        $this
            ->getFunctionMock(self::NS, 'curl_multi_info_read')
            ->expects($this->any())
            ->willReturnCallback(function (CurlMultiHandle $_mh) use ($result): array|false {
                if ($this->infoRead) {
                    return false;
                }
                $this->infoRead = true;
                return ['msg' => CURLMSG_DONE, 'result' => $result, 'handle' => $this->capturedHandle];
            });
    }

    /**
     * @param list<string> $headerLines
     * @param list<string> $bodyChunks
     */
    private function fireTransferCallbacks(array $headerLines, array $bodyChunks): void
    {
        $headerFn = $this->headerCallback;
        $writeFn = $this->writeCallback;
        $handle = $this->capturedHandle;
        if (!$headerFn instanceof Closure || !$writeFn instanceof Closure || !$handle instanceof CurlHandle) {
            self::fail('cURL callbacks/handle were not captured during curl_setopt_array.');
        }
        foreach ($headerLines as $line) {
            $headerFn($handle, $line);
        }
        foreach ($bodyChunks as $chunk) {
            $writeFn($handle, $chunk);
        }
    }

    private function mockCurlSetoptArrayCapturingOptions(): void
    {
        $setoptArray = $this->getFunctionMock(self::NS, 'curl_setopt_array');
        $setoptArray
            ->expects($this->any())
            ->willReturnCallback(
                /**
                 * @param array<int, mixed> $options
                 */
                function (CurlHandle $handle, array $options): bool {
                    $this->capturedHandle = $handle;
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
        $setopt = $this->getFunctionMock(self::NS, 'curl_setopt');
        $setopt
            ->expects($this->any())
            ->willReturnCallback(function (CurlHandle $_h, int $option, mixed $value): bool {
                $this->capturedScalarOptions[$option] = $value;
                return true;
            });
    }
}
