<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Security;

/**
 * Default {@see HostResolverInterface} backed by the system resolver.
 *
 * A literal-IP host short-circuits to itself (no lookup). Otherwise the host's
 * IPv4 A-records are returned via `gethostbynamel()`. Pinning these into cURL's
 * connection cache (`CURLOPT_RESOLVE`) means cURL performs NO further DNS for
 * the `host:port`, so an unqueried AAAA record cannot reopen an unvalidated
 * path — the trade-off is that IPv6-only hosts are unreachable through the
 * guarded client, an acceptable posture for a fail-closed SSRF default.
 *
 * Stateless and side-effect-free across requests (FrankenPHP worker rule).
 */
final readonly class SystemHostResolver implements HostResolverInterface
{
    #[\Override]
    public function resolve(string $host): array
    {
        // Literal IPv4/IPv6 (brackets already stripped by the guard) — no lookup.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : $addresses;
    }
}
