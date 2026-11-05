<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient;

use Psr\Http\Message\RequestInterface;

/**
 * How {@see Client} frames a request body on the wire, and which of the caller's headers go with it.
 *
 * The length is the stream's own size, else the request's single well-formed `Content-Length`, else
 * unknown, and libcurl streams the body chunked. A stream may not know its size when the message does:
 * `php://input` in a FrankenPHP worker reports none, while the client that sent it declared one.
 * Streaming such a body chunked while the caller's `Content-Length` went out as well put two framings
 * in one request — RFC 9112 §6.1 forbids it, and nginx answers 400 (found by the EcoShield gateway,
 * `[GATE-02]`). When the body is uploaded, libcurl writes the framing itself, so the caller's
 * `Content-Length` and `Transfer-Encoding` are left out; a request that sends no body keeps its headers.
 *
 * Stateless: two functions of the request.
 *
 * @internal
 */
final class RequestFraming
{
    /** The headers libcurl writes itself when it uploads a body. */
    private const array FRAMING_HEADERS = ['content-length', 'transfer-encoding'];

    /** The body length to send: 0 sends no body, null streams it chunked. */
    public static function bodyLength(RequestInterface $request): ?int
    {
        $size = $request->getBody()->getSize();
        if ($size !== null) {
            return $size;
        }

        // A repeated header joins with ", " and fails validation: no single declared length, so chunked.
        $declared = filter_var($request->getHeaderLine('Content-Length'), FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
        ]]);

        return is_int($declared) ? $declared : null;
    }

    /**
     * The `Name: value` lines that go out with `$request` when its body is `$length` long.
     *
     * @return list<string>
     */
    public static function headerLines(RequestInterface $request, ?int $length): array
    {
        $uploads = $length !== 0;
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            if ($uploads && in_array(strtolower((string) $name), self::FRAMING_HEADERS, true)) {
                continue;
            }

            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }
}
