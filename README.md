[![Discord](https://img.shields.io/discord/755288001592033391?logo=discord)](https://discord.gg/eKgywnfXr2)
[![PHP Version Require](http://poser.pugx.org/waffle-commons/http-client/require/php)](https://packagist.org/packages/waffle-commons/http-client)
[![PHP CI](https://github.com/waffle-commons/http-client/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/http-client/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/http-client/graph/badge.svg)](https://codecov.io/gh/waffle-commons/http-client)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/http-client/v)](https://packagist.org/packages/waffle-commons/http-client)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/http-client/v/unstable)](https://packagist.org/packages/waffle-commons/http-client)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/http-client.svg)](https://packagist.org/packages/waffle-commons/http-client)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/http-client)](https://github.com/waffle-commons/http-client/blob/main/LICENSE.md)

Waffle HTTP Client Component
============================

> **Release:** `0.1.0-beta5` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)
> **PSR Compliance:** PSR-18 (`Psr\Http\Client\ClientInterface`), PSR-7 messages, PSR-17 factories

A high-performance PSR-18 HTTP client tuned for FrankenPHP resident-worker proxying. Holds a single persistent `\CurlHandle` **plus** a `\CurlMultiHandle`, reused via `curl_reset()` across every `sendRequest()` so libcurl's DNS cache and keep-alive pool stay warm. The transfer is driven through the multi interface, so the worker parks on `curl_multi_select()` (a socket-level wait) instead of blocking inside `curl_exec()`. Bodies stream in 8 KiB chunks **in both directions** — request bodies are *pulled* from the PSR-7 request stream and response bodies are *pushed* into a PSR-7 stream — so neither side is ever materialised whole in worker memory.

## Beta-2 status

No behavioural changes since Beta-1 — lockstep version bump only. The client surface, security defaults, and streaming guarantees remain as described below.

## 🆕 Beta-1 foundations (still current)

- **Persistent socket reuse.** A single `\CurlHandle` and a single `\CurlMultiHandle` live for the worker's lifetime; `curl_reset()` between requests keeps the connection pool, DNS cache, and TLS session cache warm. Source: `Client.php` (`// Holds a single persistent CurlHandle plus a CurlMultiHandle that are reused via curl_reset() ...`).
- **Non-blocking transfer.** The transfer runs through the persistent `\CurlMultiHandle` (`curl_multi_exec` + `curl_multi_select`) rather than the blocking `curl_exec()`. The worker never busy-spins, and a slow legacy backend can no longer pin it on a blocking syscall beyond the hard timeout ceiling. The multi handle is also the building block for future concurrent fan-out.
- **Bidirectional bounded streaming (8 KiB chunks).** Request bodies are streamed via `CURLOPT_READFUNCTION` + `CURLOPT_UPLOAD` (pulling `CHUNK_SIZE = 8 * 1024` bytes at a time from the PSR-7 request stream) instead of buffering the whole payload with `CURLOPT_POSTFIELDS`. Known lengths are advertised with `CURLOPT_INFILESIZE`; unknown lengths fall back to `Transfer-Encoding: chunked`. Response bodies stream via `CURLOPT_WRITEFUNCTION`. A multi-gigabyte multipart upload is proxied without a RAM spike.
- **SEC-03 SSRF allowlist.** `Client::applyRequest()` sets both `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` to `CURLPROTO_HTTP | CURLPROTO_HTTPS` (verbatim, `Client.php:178-179`), blocking SSRF pivots via `file://`, `gopher://`, `dict://`, `ldap://`, etc. — even when a caller-supplied URL or a server-supplied `Location` header tries to switch protocols mid-flight.

## 📦 Installation

```bash
composer require waffle-commons/http-client
```

You also need PSR-17 factory implementations for `ResponseFactoryInterface` and `StreamFactoryInterface`. The framework defaults to `waffle-commons/http`; `nyholm/psr7` works equally well.

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\HttpClient\Client` | `final readonly` PSR-18 client. Persistent cURL easy + multi handles, non-blocking transfer, hardcoded 1s connect / 10s total timeouts, request **and** response bodies streamed in 8 KiB chunks. |
| `Waffle\Commons\HttpClient\Exception\HttpClientException` | Base class for client errors (e.g. handle init failure). Implements `Psr\Http\Client\ClientExceptionInterface`. |
| `Waffle\Commons\HttpClient\Exception\NetworkException` | Transport-layer failures (DNS, connect/read timeout, TLS, reset). Implements `Psr\Http\Client\NetworkExceptionInterface`. |
| `Waffle\Commons\HttpClient\Exception\RequestException` | Protocol-level failures or empty responses. Implements `Psr\Http\Client\RequestExceptionInterface`. |

## 🚀 Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Waffle\Commons\HttpClient\Client;

$psr17 = new Psr17Factory();
$client = new Client(
    responseFactory: $psr17,
    streamFactory:   $psr17,
);

$request = $psr17->createRequest('GET', 'https://api.example.com/users');
$response = $client->sendRequest($request);

echo $response->getStatusCode();             // 200
echo (string) $response->getBody();          // streamed body
```

## 🛡️ Security defaults

The client enforces a minimum security baseline that callers cannot lower:

| Option | Value | Why |
| :--- | :--- | :--- |
| `CURLOPT_PROTOCOLS` | `CURLPROTO_HTTP \| CURLPROTO_HTTPS` | SEC-03 SSRF allowlist on the request URL. |
| `CURLOPT_REDIR_PROTOCOLS` | `CURLPROTO_HTTP \| CURLPROTO_HTTPS` | SEC-03 SSRF allowlist on any redirect target. |
| `CURLOPT_SSL_VERIFYPEER` | `true` | Forces full certificate validation. |
| `CURLOPT_SSL_VERIFYHOST` | `2` | Forces hostname match against the certificate. |
| `CURLOPT_FOLLOWLOCATION` | `false` | The client never silently follows redirects — callers must handle them explicitly. |
| `CURLOPT_CONNECTTIMEOUT_MS` | `1_000` | Hard 1-second ceiling. Cannot be raised. |
| `CURLOPT_TIMEOUT_MS` | `10_000` | Hard 10-second ceiling. Cannot be raised. A hung legacy backend must never lock a worker thread. |

## 🐘 PHP 8.5 features used

- **`final readonly class Client`** with promoted constructor properties.
- **Typed integer constants** for every timeout/chunk-size value (`CONNECT_TIMEOUT_MS`, `TIMEOUT_MS`, `CHUNK_SIZE`).
- **`#[\Override]`** on the PSR-18 implementation method.
- **`match`** expression for HTTP-version negotiation.

## 🧭 Architectural boundary (`mago guard`)

An active dependency **perimeter** is enforced on every CI run by `vendor/bin/mago guard` (bundled into `composer mago`; zero baselines). The rules live in [`mago.toml`](./mago.toml) under `[guard.perimeter]` — a forbidden `use` statement fails the build, not a reviewer.

Production code under `Waffle\Commons\HttpClient` may depend **only** on:

- `Waffle\Commons\HttpClient\**` — itself
- `Waffle\Commons\Contracts\**` — the shared contracts package, the **only** Waffle dependency permitted
- `Psr\**` — PSR interfaces (PSR-7 / PSR-17 / PSR-18)
- `@global` + `Psl\**` — PHP core (including `ext-curl`) and the PHP Standard Library

Test code under `WaffleTests\Commons\HttpClient` is unrestricted (`@all`); the `tests/src/ClientTest.php` `php-mock` fixture is listed in `[guard].excludes` because it re-declares the production namespace to stub libcurl. Structural rules are guarded too: interfaces must be named `*Interface`, `Exception\**` classes must end in `*Exception`, and any `Enum\**` namespace may hold only `enum` declarations.

Contract-first, component-agnostic by construction: components compose through `waffle-commons/contracts`, never directly through one another.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/http-client waffle-dev composer tests
```

The test suite uses `php-mock/php-mock-phpunit` to stub libcurl entry points (`curl_init`, `curl_setopt_array`, `curl_multi_exec`, `curl_multi_info_read`, …), so PHPUnit runs hermetically without network I/O. Dedicated tests assert the SEC-03 protocol allowlist, the chunked request-body upload, and the non-blocking multi-handle transfer.

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
