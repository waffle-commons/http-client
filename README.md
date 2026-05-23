[![PHP Version Require](http://poser.pugx.org/waffle-commons/http-client/require/php)](https://packagist.org/packages/waffle-commons/http-client)
[![PHP CI](https://github.com/waffle-commons/http-client/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/http-client/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/http-client/graph/badge.svg)](https://codecov.io/gh/waffle-commons/http-client)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/http-client/v)](https://packagist.org/packages/waffle-commons/http-client)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/http-client/v/unstable)](https://packagist.org/packages/waffle-commons/http-client)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/http-client.svg)](https://packagist.org/packages/waffle-commons/http-client)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/http-client)](https://github.com/waffle-commons/http-client/blob/main/LICENSE.md)

Waffle HTTP Client Component
============================

> **Release:** `v0.1.0-beta1`
> **PSR Compliance:** PSR-18 (`Psr\Http\Client\ClientInterface`), PSR-7 messages, PSR-17 factories

A high-performance PSR-18 HTTP client tuned for FrankenPHP resident-worker proxying. Holds a single persistent `\CurlHandle` reused via `curl_reset()` across every `sendRequest()` so libcurl's DNS cache and keep-alive pool stay warm. Response bodies are streamed in 8 KiB chunks directly into a PSR-7 stream backed by `php://temp`; the full body is never materialised as a string.

## 🆕 Beta-1 highlight — SEC-03 SSRF allowlist

`Client::applyRequest()` now sets both `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` to `CURLPROTO_HTTP | CURLPROTO_HTTPS`. This blocks SSRF pivots via `file://`, `gopher://`, `dict://`, `ldap://`, etc. — even when a caller-supplied URL or a server-supplied `Location` header tries to switch protocols mid-flight.

## 📦 Installation

```bash
composer require waffle-commons/http-client
```

You also need PSR-17 factory implementations for `ResponseFactoryInterface` and `StreamFactoryInterface`. The framework defaults to `waffle-commons/http`; `nyholm/psr7` works equally well.

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\HttpClient\Client` | `final readonly` PSR-18 client. Persistent cURL handle, hardcoded 1s connect / 10s total timeouts, body streamed in 8 KiB chunks. |
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

## 🧪 Testing

```bash
docker exec -w /waffle-commons/http-client waffle-dev composer tests
```

The test suite uses `php-mock/php-mock-phpunit` to stub libcurl entry points (`curl_init`, `curl_setopt_array`, `curl_exec`, …), so PHPUnit runs hermetically without network I/O. A dedicated test asserts the SEC-03 protocol allowlist is set on every request.

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
