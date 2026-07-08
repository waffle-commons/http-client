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
use Throwable;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Contracts\Telemetry\NullTextMapPropagator;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TextMapPropagatorInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Promise\Promise;
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
 * concurrent fan-out (see {@see self::sendRequests()} / {@see self::promise()}),
 * and a slow legacy backend can no longer pin the worker on a blocking syscall
 * beyond the hard timeout ceiling.
 *
 * **Concurrent fan-out (ASYNC-02).** {@see self::sendRequests()} allocates one
 * dedicated easy handle per request, registers them all on a single multi
 * handle and drives one shared `curl_multi_exec()` loop, so N requests complete
 * in roughly the wall-clock of the slowest one. {@see self::promise()} hands the
 * caller a non-blocking {@see PromiseInterface} over a single in-flight request.
 * Per-request handles are created and closed within the call — the only resident
 * state is the persistent easy/multi handle, so the client stays worker-safe.
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
final readonly class Client implements ClientInterface, ConcurrentClientInterface
{
    /** Maximum time to establish a TCP/TLS connection (ms). */
    public const int CONNECT_TIMEOUT_MS = 1000;

    /** Maximum total time for a single request, including transfer (ms). */
    public const int TIMEOUT_MS = 10_000;

    /** Body streaming chunk size (bytes), used for both upload and download. */
    public const int CHUNK_SIZE = 8_192;

    /** Upper bound (seconds) the worker parks on `curl_multi_select()` per tick. */
    private const float SELECT_TIMEOUT = 1.0;

    /**
     * Back-off (microseconds) when `curl_multi_select()` returns -1 (no file
     * descriptors to wait on) so the drive loop cannot busy-spin a CPU. This is
     * the back-off the cURL manual recommends for the no-fds case.
     */
    private const int SELECT_BACKOFF_US = 100;

    private CurlHandle $handle;

    private CurlMultiHandle $multiHandle;

    /**
     * @throws HttpClientException If the underlying cURL handle(s) cannot be allocated.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?SsrfGuard $ssrfGuard = null,
        private TracerInterface $tracer = new NullTracer(),
        private TextMapPropagatorInterface $propagator = new NullTextMapPropagator(),
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
     * Wraps the transfer in a CLIENT span and injects W3C trace context onto the
     * outbound request, so a distributed trace continues across the service boundary.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $span = $this->tracer->startSpan('http.client.request', SpanKind::Client);
        $span->setAttribute('http.request.method', $request->getMethod());
        $span->setAttribute('url.full', (string) $request->getUri());

        try {
            $response = $this->send($this->injectTraceContext($request));
            $span->setAttribute('http.response.status_code', $response->getStatusCode());

            return $response;
        } catch (Throwable $error) {
            $span->recordException($error);
            $span->setStatus(SpanStatus::Error);

            throw $error;
        } finally {
            $span->end();
        }
    }

    /**
     * Resolve several requests concurrently and return their responses.
     *
     * Allocates one dedicated easy handle per request (the persistent
     * single-request handle is left untouched), registers every handle on a
     * single multi handle and drives one shared `curl_multi_exec()` loop, so the
     * whole batch settles in roughly the wall-clock of the slowest request.
     * Array keys are preserved, so the result lines up 1:1 with the input. Each
     * per-request handle is removed and closed in a `finally`, leaving no
     * resident cross-request state.
     *
     * On any failure (transport, protocol, or SSRF refusal) the offending
     * request's mapped exception is thrown — fail-fast, so partial results are
     * never silently returned.
     *
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface>
     *
     * @throws NetworkException On transport-layer failure (DNS, connect/read timeout, TLS, reset).
     * @throws RequestException On protocol-level failure or empty response.
     * @throws \Waffle\Commons\HttpClient\Exception\SsrfException When the guard rejects a target host.
     */
    #[\Override]
    public function sendRequests(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $multiHandle = curl_multi_init();

        $firstRequest = $requests[array_key_first($requests)];

        /** @var array<array-key, Transfer> $transfers */
        $transfers = [];
        try {
            foreach ($requests as $key => $request) {
                $transfer = $this->prepareTransfer($this->injectTraceContext($request));
                curl_multi_add_handle($multiHandle, $transfer->handle);
                $transfers[$key] = $transfer;
            }

            $status = $this->drive($multiHandle);
            // A CURLM_* loop failure is surfaced against the FIRST request rather
            // than silently proceeding with half-built transfers (ASYNC02-01).
            if ($status !== CURLM_OK) {
                throw $this->mapMultiError($firstRequest, $status);
            }

            $responses = [];
            foreach ($transfers as $key => $transfer) {
                $responses[$key] = $this->finishTransfer($transfer);
            }

            return $responses;
        } finally {
            // Detach every per-request handle from the multi handle and free it.
            // In PHP 8.x a CurlHandle is a GC-managed object (curl_close is a
            // deprecated no-op), so dropping the last reference releases it — no
            // per-request state survives the call.
            foreach ($transfers as $transfer) {
                curl_multi_remove_handle($multiHandle, $transfer->handle);
            }
            curl_multi_close($multiHandle);
        }
    }

    /**
     * Start a single request and return a non-blocking {@see PromiseInterface}.
     *
     * A dedicated easy handle is allocated and registered on a per-promise multi
     * handle; the transfer is driven only when the caller invokes
     * {@see PromiseInterface::wait()}, which builds and caches the response (or
     * captures the failure), settles the state exactly once and fires any
     * registered `then`/`catch` callbacks.
     *
     * The per-promise easy + multi handle are allocated eagerly so the transfer
     * can start, but they are freed by a dedicated cleanup closure handed to the
     * {@see Promise}: the promise runs it exactly once — when it settles, or, if
     * {@see PromiseInterface::wait()} is never called, from its destructor — so an
     * un-waited promise can never accumulate handles across requests (ASYNC02-04).
     *
     * @throws \Waffle\Commons\HttpClient\Exception\SsrfException When the guard rejects the target host.
     */
    #[\Override]
    public function promise(RequestInterface $request): PromiseInterface
    {
        $multiHandle = curl_multi_init();
        $transfer = $this->prepareTransfer($this->injectTraceContext($request));
        curl_multi_add_handle($multiHandle, $transfer->handle);

        $resolver = function () use ($multiHandle, $transfer): ResponseInterface {
            $status = $this->drive($multiHandle);
            // Surface a CURLM_* loop failure instead of reading a half-driven
            // transfer's terminal result as if it had succeeded (ASYNC02-01).
            if ($status !== CURLM_OK) {
                throw $this->mapMultiError($transfer->request, $status);
            }

            return $this->finishTransfer($transfer);
        };

        // Detach and free the per-promise handle (PHP 8.x GC-frees the CurlHandle
        // once its last reference drops; curl_close is a deprecated no-op), so the
        // promise leaves no resident state. The Promise invokes this once — on
        // settle OR from its destructor if it is never waited on.
        $cleanup = static function () use ($multiHandle, $transfer): void {
            curl_multi_remove_handle($multiHandle, $transfer->handle);
            curl_multi_close($multiHandle);
        };

        return new Promise($resolver, $cleanup);
    }

    /** Inject the active trace context (`traceparent`/`tracestate`) onto the outbound request. */
    private function injectTraceContext(RequestInterface $request): RequestInterface
    {
        $context = $this->tracer->currentContext();
        if ($context === null) {
            return $request;
        }

        $carrier = [];
        $this->propagator->inject($context, $carrier);
        foreach ($carrier as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @throws NetworkException On transport-layer failure (DNS, connect/read timeout, TLS, reset).
     * @throws RequestException On protocol-level failure or empty response.
     * @throws \Waffle\Commons\HttpClient\Exception\SsrfException When the guard rejects the target host.
     */
    private function send(RequestInterface $request): ResponseInterface
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

        $this->applyRequest($this->handle, $request, $resolvePins, $body, $statusLine, $headers);

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
     * Allocate and fully configure a dedicated easy handle for one request,
     * returning the {@see Transfer} that bundles the handle with the mutable
     * capture buffers libcurl fills as the transfer progresses.
     *
     * Used by the concurrent fan-out and promise paths — the blocking
     * single-request path reuses the persistent handle instead.
     *
     * @throws HttpClientException If the easy handle cannot be allocated.
     * @throws \Waffle\Commons\HttpClient\Exception\SsrfException When the guard rejects the target host.
     */
    private function prepareTransfer(RequestInterface $request): Transfer
    {
        // SEC-02: resolve + validate the target host BEFORE allocating the handle.
        $resolvePins = $this->ssrfGuard?->resolvePins($request) ?? [];

        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new HttpClientException('Failed to initialise a per-request cURL handle.');
        }

        $transfer = new Transfer($handle, $request, $this->streamFactory->createStream(''));
        $this->applyRequest(
            $handle,
            $request,
            $resolvePins,
            $transfer->body,
            $transfer->statusLine,
            $transfer->headers,
        );

        return $transfer;
    }

    /**
     * Read a settled transfer's terminal result and build its PSR-7 response.
     *
     * @throws NetworkException On transport-layer failure (DNS, connect/read timeout, TLS, reset).
     * @throws RequestException On protocol-level failure or empty response.
     */
    private function finishTransfer(Transfer $transfer): ResponseInterface
    {
        // Each easy handle carries its own terminal CURLcode, so read it straight
        // off the handle — no curl_multi_info_read() drain (and none of its
        // mixed-typed by-ref out-param) needed (POLICY-05).
        $errno = curl_errno($transfer->handle);
        if ($errno !== CURLE_OK) {
            throw $this->mapCurlError($transfer->request, $errno, curl_error($transfer->handle));
        }

        if ($transfer->statusLine === '') {
            throw new RequestException($transfer->request, 'No HTTP status line received from the remote endpoint.');
        }

        return $this->buildResponse($transfer->statusLine, $transfer->headers, $transfer->body);
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
            $status = $this->drive($this->multiHandle);
            if ($status !== CURLM_OK) {
                return [CURLE_RECV_ERROR, curl_multi_strerror($status) ?? 'cURL multi handle failure.'];
            }

            // Single easy handle: its terminal CURLcode IS the transfer result, so
            // read it straight off the handle — no curl_multi_info_read() drain (and
            // none of its mixed-typed by-ref out-param) needed (POLICY-05).
            $result = curl_errno($this->handle);
            if ($result !== CURLE_OK) {
                return [$result, curl_error($this->handle)];
            }

            return [CURLE_OK, ''];
        } finally {
            curl_multi_remove_handle($this->multiHandle, $this->handle);
        }
    }

    /**
     * Pumps a multi handle until every registered easy handle has completed,
     * parking on `curl_multi_select()` between ticks so the worker never spins.
     *
     * @return int The terminal `curl_multi_exec()` status (`CURLM_OK` on success).
     */
    private function drive(CurlMultiHandle $multiHandle): int
    {
        $running = 0;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                // Block on the socket set (or up to SELECT_TIMEOUT); never spin.
                // curl_multi_select() returns -1 when there are no file
                // descriptors to wait on (e.g. before the first connection is
                // established); without a back-off the loop would busy-spin a CPU
                // at 100% (ASYNC02-05). Sleep a small fixed interval, exactly as
                // the cURL manual recommends, then tick again.
                if (curl_multi_select($multiHandle, self::SELECT_TIMEOUT) === -1) {
                    usleep(self::SELECT_BACKOFF_US);
                }
            }
        } while ($running > 0 && $status === CURLM_OK);

        return $status;
    }

    /**
     * @param list<string>                $resolvePins SEC-02 `CURLOPT_RESOLVE` pins (`host:port:ip`).
     * @param array<string, list<string>> $headers
     */
    private function applyRequest(
        CurlHandle $handle,
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

        curl_setopt_array($handle, $options);

        $this->applyRequestBody($handle, $request);
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
    private function applyRequestBody(CurlHandle $handle, RequestInterface $request): void
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

        curl_setopt($handle, CURLOPT_UPLOAD, true);
        curl_setopt(
            $handle,
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
            curl_setopt($handle, CURLOPT_INFILESIZE, $size);
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

        $name = strtolower(mb_trim(substr($trimmed, 0, $colon)));
        $value = mb_trim(substr($trimmed, $colon + 1));

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

    /**
     * Map a non-OK `curl_multi_exec()` terminal status to a transport-layer
     * failure. A `CURLM_*` code means the multi loop itself broke down (out of
     * memory, bad handle, internal error) — there is no usable per-handle result,
     * so the whole batch fails as a {@see NetworkException} rather than being read
     * as if the transfers had completed (ASYNC02-01).
     */
    private function mapMultiError(RequestInterface $request, int $status): NetworkException
    {
        $message = curl_multi_strerror($status) ?? 'cURL multi handle failure #' . $status;

        return new NetworkException($request, $message, CURLE_RECV_ERROR);
    }
}
