# Changelog — waffle-commons/http-client

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased]

### Added
- **Outbound resilience (NET-02, NET-03, RFC-024).** `Client::withTimeouts(TimeoutPolicy)` — per-request timeouts as an immutable wither (defaults = the former ceilings; no infinite timeout can be expressed). `Resilience\RetryingClient` — bounded retries around any PSR-18 client: transport failures and 429/502/503/504, idempotent methods only unless opted in, exponential backoff with full jitter, numeric `Retry-After` honoured beyond the cap within the total budget. `Resilience\CircuitBreakerClient` — a breaker keyed by origin over the shared `CircuitStateStorageInterface`: an open circuit fails fast with `Exception\CircuitOpenException` (503, never retried), transport failures and 5xx count as failures, transitions are published as `CircuitStateChanged` events and metrics (`waffle_circuit_transitions_total`, `waffle_circuit_state`), and an unavailable storage behaves as closed with a warning and `waffle_circuit_storage_errors_total`. Compose as `RetryingClient(CircuitBreakerClient(Client))` so every attempt is checked and re-runs the SSRF guard.
- `SettlingClientInterface` (extends the contracts' `ConcurrentClientInterface`) and `Client::settleRequests()` — the concurrent fan-out, settled: every request of the batch maps to its response or to the exception its transfer failed with, in the batch's key order, and nothing is thrown. A request the SSRF guard refuses settles as its `SsrfException` and is never sent, while the rest of the batch runs; a broken multi loop fails every transfer. It is the building block for the resilience decorators' concurrent path (RFC-024 §3.3), which needs every request's outcome; `sendRequests()` keeps its fail-fast behaviour.
- **The resilience decorators cover the concurrent path (RFC-024 §3.3).** `RetryingClient` and `CircuitBreakerClient` implement `SettlingClientInterface`. The breaker asks the shared storage about every request of a batch before any is sent, sends the admitted ones together and records each outcome; a refused request settles as its `CircuitOpenException` and is never sent. The retry policy retries a batch in waves: each request keeps its own attempts, backoff, `Retry-After` and budget, and the requests to retry go out together once the longest of their waits has passed. `sendRequests()` is the fail-fast view of the settled batch (the first failure in key order, thrown once every outcome is recorded), and `promise()` runs the decorator's whole `sendRequest()` when it is waited on. `Resilience\Settlement` holds the shared steps: an inner client that does not settle is sent one request at a time, a batch of one goes through `sendRequest()` to keep the persistent connection warm, and a request a settling client returns no outcome for settles as a `RequestException`.

### Fixed
- **Transport failures are `NetworkException`s again, on every path.** PHP copies a multi-driven handle's cURL result into the handle only when its completion message is read, and since 0.1.0-beta5 the client never read it: `curl_errno()` answered 0, so a DNS failure, a refused connection, a timeout or a TLS failure surfaced as `RequestException` "No HTTP status line received". The retry policy never retried them and the circuit breaker never counted them. The drive loop now drains the completion messages; a test drives the real transport to a refused port on the single, batch and promise paths.

### Dependencies
- `psr/log` is a direct requirement.

## [0.1.0-beta6] — 2026-09

**Theme: documentation.**

### Documentation
- The README now links into the central Diátaxis documentation tree (DOC-02).

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
