<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient;

use CurlHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;

/**
 * High-performance PSR-18 HTTP client tuned for FrankenPHP resident-worker proxying.
 *
 * Holds a single persistent `\CurlHandle` that is reused — via `curl_reset()` —
 * across every `sendRequest()` call so libcurl's DNS cache and keep-alive pool
 * stay warm. Response bodies are streamed in 8 KiB chunks directly into a PSR-7
 * stream backed by `php://temp`; the full body is never materialised as a string.
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

    /** Body streaming chunk size (bytes). */
    public const int CHUNK_SIZE = 8_192;

    private CurlHandle $handle;

    /**
     * @throws HttpClientException If the underlying cURL handle cannot be allocated.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new HttpClientException('Failed to initialise the underlying cURL handle.');
        }
        $this->handle = $handle;
    }

    /**
     * @throws NetworkException On transport-layer failure (DNS, connect/read timeout, TLS, reset).
     * @throws RequestException On protocol-level failure or empty response.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        curl_reset($this->handle);

        $body = $this->streamFactory->createStream('');
        $statusLine = '';
        /** @var array<string, list<string>> $headers */
        $headers = [];

        $this->applyRequest($request, $body, $statusLine, $headers);

        $ok = curl_exec($this->handle);
        if ($ok === false) {
            throw $this->mapCurlError($request, curl_errno($this->handle), curl_error($this->handle));
        }

        if ($statusLine === '') {
            throw new RequestException($request, 'No HTTP status line received from the remote endpoint.');
        }

        return $this->buildResponse($statusLine, $headers, $body);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function applyRequest(
        RequestInterface $request,
        StreamInterface $body,
        string &$statusLine,
        array &$headers,
    ): void {
        curl_setopt_array($this->handle, [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTP_VERSION => $this->resolveHttpVersion($request->getProtocolVersion()),
            CURLOPT_HTTPHEADER => $this->buildHeaderLines($request),
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
        ]);

        $payload = (string) $request->getBody();
        if ($payload !== '') {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $payload);
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
