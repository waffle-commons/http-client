# Changelog — waffle-commons/http-client

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta5] — 2026-07-08

**Theme: distributed tracing & concurrent fan-out.**

### Added
- W3C trace-context propagation on every outbound request (OBS-01): `sendRequest()` now opens a `CLIENT` span and injects the active context's `traceparent`/`tracestate` headers via the injected `TextMapPropagatorInterface`, so a distributed trace continues across the service boundary. The defaults are a `NullTracer` + `NullTextMapPropagator`, so the feature is inert unless a real telemetry SDK is wired in — core never depends on one.
- Concurrent fan-out (ASYNC-02). `Client` now implements `ConcurrentClientInterface`:
  - `sendRequests(array $requests)` allocates one dedicated easy handle per request, registers them all on a single multi handle and drives one shared `curl_multi_exec()` loop, so a batch settles in roughly the wall-clock of its slowest request. Input array keys are preserved 1:1 in the result, and every per-request handle is detached and freed in a `finally` — no resident cross-request state. Fail-fast: the first failure (transport, protocol, or SSRF refusal) throws rather than returning partial results (ASYNC02-01).
  - `promise(RequestInterface $request)` returns a non-blocking `PromiseInterface` over a single in-flight request; the transfer is driven only on `wait()`, which settles once, caches the outcome and fires `then`/`catch` callbacks. A worker-safe, transient `Promise` (marked `#[WorkerSafe]`) frees its per-promise handles on settle, or from its destructor if never waited on, so an abandoned promise cannot leak handles across requests (ASYNC02-04).
- `Transfer` value holder bundling a per-request easy handle with the streamed response body, status line, and header buffers libcurl fills for the concurrent/promise paths.
- `curl_multi_select()` back-off: a fixed `usleep()` when the select returns `-1` (no file descriptors to wait on) prevents the drive loop from busy-spinning a CPU (ASYNC02-05).

### Changed
- The single-request drive loop now reads each transfer's terminal result straight off the easy handle via `curl_errno()` instead of draining the message queue with `curl_multi_info_read()`, removing the last `mixed`-typed by-ref out-param from the hot path (POLICY-05).
- `applyRequest()` / `applyRequestBody()` now take the target `CurlHandle` as an explicit parameter so the persistent handle and the per-request fan-out handles share one configuration path.
- `cyclomatic-complexity` lint re-enabled at a `threshold = 50` ratchet; `IgorPhp\IgorBundle\Attribute\**` permitted in the production guard perimeter as inert dev-only worker-safety metadata.

## [0.1.0-beta4] — 2026-06-13

**Theme: SSRF protection on by default.**

### Added
- `SsrfGuard` is now **default-on**: every outbound request runs resolve → validate (reject private/loopback/reserved CIDRs) → pin (`CURLOPT_RESOLVE`), closing the DNS-rebind TOCTOU window; automatic redirect-following is disabled (SEC-02).
- IPv6/AAAA host resolution in `SystemHostResolver`, plus an internal-host allowlist (exact host or CIDR) so explicitly-trusted private backends still resolve.

### Changed
- Worker-safety migration to igor-php 0.7 (`#[WorkerSafe]`).

## [0.1.0-beta3] — 2026-06-07

**Theme: identity federation & stateless persistence (ecosystem wave).**

### Changed
- Lockstep version bump; `composer.lock` refreshed with the beta-3 dependency wave.

## [0.1.0-beta2.1] — 2026-05-30

### Changed
- Lockstep re-tag of `0.1.0-beta2` (umbrella housekeeping patch) — no source changes in this component.

## [0.1.0-beta2] — 2026-05-29

### Changed
- Lockstep version bump only. No behavioural changes since `0.1.0-beta1`.
- `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — first release of this component: PSR-18 client for outbound proxying with non-blocking `curl_multi` transfer, bidirectional 8 KiB streaming (`CURLOPT_READFUNCTION` / `CURLOPT_WRITEFUNCTION`), SEC-03 SSRF protocol allowlist (`CURLOPT_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS`), hardcoded 1s/10s timeouts.
