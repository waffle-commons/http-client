# Changelog — waffle-commons/http-client

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

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
