# Changelog — waffle-commons/http-client

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased] — targeting `0.1.0-beta2`

### Changed
- Lockstep version bump only. No behavioural changes since `0.1.0-beta1`.
- `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — first release of this component: PSR-18 client for outbound proxying with non-blocking `curl_multi` transfer, bidirectional 8 KiB streaming (`CURLOPT_READFUNCTION` / `CURLOPT_WRITEFUNCTION`), SEC-03 SSRF protocol allowlist (`CURLOPT_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS`), hardcoded 1s/10s timeouts.
