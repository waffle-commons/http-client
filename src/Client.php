<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient;

use CurlHandle;
use CurlMultiHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Security\SsrfGuard;

/**
 * High-performance PSR-18 HTTP client tuned for FrankenPHP resident-worker proxying.
 *
 * Holds a single persistent `\CurlHandle` plus a `\CurlMultiHandle` that are
 * reused — via `curl_reset()` — across every `sendRequest()` call so libcurl's
 * DNS cache and keep-alive pool stay warm.
 *
 * **Non-blocking transfer.** Rather than calling the blocking `curl_exec()`, the
 * transfer is driven through the multi interface: the worker parks on
 * `curl_multi_select()` (a socket-level wait) between `curl_multi_exec()` ticks
 * instead of busy-spinning a CPU. The multi handle is the building block for
 * future concurrent fan-out, and a slow legacy backend can no longer pin the
 * worker on a blocking syscall beyond the hard timeout ceiling.
 *
 * **Bounded memory, both directions.** Response bodies are streamed in 8 KiB
 * chunks into a PSR-7 stream via `CURLOPT_WRITEFUNCTION`; the full body is never
 * materialised as a string. Request bodies are likewise *pulled* in 8 KiB chunks
 * from the PSR-7 request stream via `CURLOPT_READFUNCTION` (`CURLOPT_UPLOAD`),
 * so proxying a large multipart/chunked upload never buffers the whole payload
 * in worker RAM.
 *
 * **SSRF defence (SEC-02).** When a {@see SsrfGuard} is injected, every request
 * has its target host resolved and validated against private/reserved ranges,
 * and the vetted IP is pinned via `CURLOPT_RESOLVE` before the transfer — the
 * transport can never connect to an internal address, even under DNS rebinding.
 *
 * Connect and total timeouts are hardcoded ceilings (1s / 10s) and cannot be
 * raised by callers — a hung legacy backend must never lock a worker thread.
 */
final readonly class Client implements ClientInterface
{
    /** Maximum time to establish a TCP/TLS connection (ms). */
    public const int CONNECT_TIMEOUT_MS = 1000;

    /** Maximum total time for a single request, including transfer (ms). */
    public const int TIMEOUT_MS = 10_000;

    /** Body streaming chunk size (bytes), used for both upload and download. */
    public const int CHUNK_SIZE = 8_192;

    /** Upper bound (seconds) the worker parks on `curl_multi_select()` per tick. */
    private const float SELECT_TIMEOUT = 1.0;

    private CurlHandle $handle;

    private CurlMultiHandle $multiHandle;

    /**
     * @throws HttpClientException If the underlying cURL handle(s) cannot be allocated.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?SsrfGuard $ssrfGuard = null,
    ) {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new HttpClientException('Failed to initialise the underlying cURL handle.');
        }
        $this->handle = $handle;
        // curl_multi_init() never fails (returns a CurlMultiHandle), so no guard.
        $this->multiHandle = curl_multi_init();
    }

    /**
     * @throws NetworkException On transport-layer failure (DNS, connect/read timeout, TLS, reset).
     * @throws RequestException On protocol-level failure or empty response.
     * @throws \Waffle\Commons\HttpClient\Exception\SsrfException When the guard rejects the target host.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        curl_reset($this->handle);

        // SEC-02: resolve + validate the target host (and obtain CURLOPT_RESOLVE
        // pins) BEFORE any allocation or transfer. A non-public resolution
        // throws SsrfException here — fail-closed, no socket is ever opened.
        $resolvePins = $this->ssrfGuard?->resolvePins($request) ?? [];

        $body = $this->streamFactory->createStream('');
        $statusLine = '';
        /** @var array<string, list<string>> $headers */
        $headers = [];

        $this->applyRequest($request, $resolvePins, $body, $statusLine, $headers);

        [$errno, $errorMessage] = $this->execute();
        if ($errno !== CURLE_OK) {
            throw $this->mapCurlError($request, $errno, $errorMessage);
        }

        if ($statusLine === '') {
            throw new RequestException($request, 'No HTTP status line received from the remote endpoint.');
        }

        return $this->buildResponse($statusLine, $headers, $body);
    }

    /**
     * Drives the transfer through the persistent multi handle, parking on
     * `curl_multi_select()` between ticks so the worker never busy-waits. The
     * easy handle is added and removed each call but the underlying connection
     * pool persists, so keep-alive survives across requests.
     *
     * @return array{0: int, 1: string} cURL error number (`CURLE_OK` on success) and message.
     */
    private function execute(): array
    {
        curl_multi_add_handle($this->multiHandle, $this->handle);

        try {
            $running = 0;
            do {
                $status = curl_multi_exec($this->multiHandle, $running);
                if ($running > 0) {
                    // Block on the socket set (or up to SELECT_TIMEOUT); never spin.
                    curl_multi_select($this->multiHandle, self::SELECT_TIMEOUT);
                }
            } while ($running > 0 && $status === CURLM_OK);

            if ($status !== CURLM_OK) {
                return [CURLE_RECV_ERROR, curl_multi_strerror($status) ?? 'cURL multi handle failure.'];
            }

            // Drain the message queue for this transfer's terminal result code.
            $result = CURLE_OK;
            // $queued is a mandatory by-ref out-param (remaining messages); we
            // don't use it, and cURL's stub types it as mixed.
            // @mago-ignore analysis:mixed-assignment
            $queued = 0;
            while (($info = curl_multi_info_read($this->multiHandle, $queued)) !== false) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                $result = (int) $info['result'];
            }

            if ($result !== CURLE_OK) {
                return [$result, curl_error($this->handle)];
            }

            return [CURLE_OK, ''];
        } finally {
            curl_multi_remove_handle($this->multiHandle, $this->handle);
        }
    }

    /**
     * @param list<string>                $resolvePins SEC-02 `CURLOPT_RESOLVE` pins (`host:port:ip`).
     * @param array<string, list<string>> $headers
     */
    private function applyRequest(
        RequestInterface $request,
        array $resolvePins,
        StreamInterface $body,
        string &$statusLine,
        array &$headers,
    ): void {
        $options = [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTP_VERSION => $this->resolveHttpVersion($request->getProtocolVersion()),
            CURLOPT_HTTPHEADER => $this->buildHeaderLines($request),
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // SEC-03: hard-restrict scheme to http(s). Blocks SSRF pivots via
            // file://, gopher://, dict://, ldap://, etc., even when a caller-
            // supplied URL or a server-supplied Location header tries to switch
            // protocols mid-flight.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_BUFFERSIZE => self::CHUNK_SIZE,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $_handle, string $line) use (
                &$statusLine,
                &$headers,
            ): int {
                return self::onHeader($line, $statusLine, $headers);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $_handle, string $chunk) use ($body): int {
                return $body->write($chunk);
            },
        ];

        // SEC-02: pin the validated IP(s) so the transport connects to exactly
        // the address the guard vetted — closing the DNS-rebinding / TOCTOU gap.
        if ($resolvePins !== []) {
            $options[CURLOPT_RESOLVE] = $resolvePins;
        }

        curl_setopt_array($this->handle, $options);

        $this->applyRequestBody($request);
    }

    /**
     * Configures the request body as a *streamed* upload when one is present.
     *
     * libcurl pulls the body in `CHUNK_SIZE` increments through the read
     * callback, so a large multipart/chunked payload is forwarded without ever
     * being buffered whole in worker memory — unlike `CURLOPT_POSTFIELDS`, which
     * requires the entire body as a single string. The request method set via
     * `CURLOPT_CUSTOMREQUEST` is preserved; `CURLOPT_UPLOAD` only switches on the
     * read-callback transfer mode, it does not override the method line.
     *
     * When the body length is known it is advertised via `CURLOPT_INFILESIZE`
     * (Content-Length); when it is not (`getSize() === null`), libcurl falls back
     * to `Transfer-Encoding: chunked`.
     */
    private function applyRequestBody(RequestInterface $request): void
    {
        $stream = $request->getBody();
        $size = $stream->getSize();

        // No body to forward (typical GET/DELETE/HEAD): leave the handle as-is.
        if ($size === 0) {
            return;
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        curl_setopt($this->handle, CURLOPT_UPLOAD, true);
        curl_setopt(
            $this->handle,
            CURLOPT_READFUNCTION,
            /**
             * @param resource $_stream
             */
            static function (CurlHandle $_handle, $_stream, int $length) use ($stream): string {
                if ($stream->eof()) {
                    return '';
                }
                return $stream->read($length);
            },
        );

        if ($size !== null) {
            curl_setopt($this->handle, CURLOPT_INFILESIZE, $size);
        }
    }

    /**
     * Header callback invoked by libcurl, one line at a time.
     *
     * @param array<string, list<string>> $headers
     */
    private static function onHeader(string $line, string &$statusLine, array &$headers): int
    {
        $length = strlen($line);
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === '') {
            return $length;
        }

        if (str_starts_with($trimmed, 'HTTP/')) {
            $statusLine = $trimmed;
            $headers = [];
            return $length;
        }

        $colon = strpos($trimmed, ':');
        if ($colon === false) {
            return $length;
        }

        $name = strtolower(trim(substr($trimmed, 0, $colon)));
        $value = trim(substr($trimmed, $colon + 1));

        $headers[$name] ??= [];
        $headers[$name][] = $value;

        return $length;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function buildResponse(string $statusLine, array $headers, StreamInterface $body): ResponseInterface
    {
        [$protocolVersion, $statusCode, $reason] = $this->parseStatusLine($statusLine);

        $response = $this->responseFactory->createResponse($statusCode, $reason)->withProtocolVersion($protocolVersion);

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        $body->rewind();

        return $response->withBody($body);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function parseStatusLine(string $line): array
    {
        $parts = explode(' ', $line, 3);
        $protocol = $parts[0];
        $version = str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1';
        $code = (int) ($parts[1] ?? 0);
        $reason = $parts[2] ?? '';

        return [$version, $code, $reason];
    }

    /**
     * @return list<string>
     */
    private function buildHeaderLines(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }
        return $lines;
    }

    private function resolveHttpVersion(string $protocolVersion): int
    {
        return match ($protocolVersion) {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '2.0', '2' => CURL_HTTP_VERSION_2_0,
            default => CURL_HTTP_VERSION_1_1,
        };
    }

    private function mapCurlError(RequestInterface $request, int $errno, string $message): HttpClientException
    {
        $networkErrnos = [
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_PARTIAL_FILE,
        ];

        $description = $message !== '' ? $message : 'cURL error #' . $errno;

        if (in_array($errno, $networkErrnos, true)) {
            return new NetworkException($request, $description, $errno);
        }

        return new RequestException($request, $description, $errno);
    }
}
