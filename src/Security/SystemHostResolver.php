<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Security;

/**
 * Default {@see HostResolverInterface} backed by the system resolver.
 *
 * A literal-IP host short-circuits to itself (no lookup). Otherwise BOTH address
 * families are resolved — IPv4 A-records via `gethostbynamel()` and IPv6
 * AAAA-records via `dns_get_record()` — and the union is pinned into cURL's
 * connection cache (`CURLOPT_RESOLVE`). Resolving AAAA as well means IPv6-only
 * and dual-stack hosts are reachable through the guarded client while every
 * returned address is still vetted by {@see SsrfGuard}; cURL performs NO further
 * DNS for the `host:port`, so an unqueried record cannot reopen an unvalidated
 * path.
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

        $addresses = [];

        $v4 = gethostbynamel($host);
        if ($v4 !== false) {
            foreach ($v4 as $ip) {
                $addresses[] = $ip;
            }
        }

        foreach ($this->resolveIpv6($host) as $ip) {
            $addresses[] = $ip;
        }

        return $addresses;
    }

    /**
     * Resolve the host's AAAA (IPv6) records.
     *
     * `dns_get_record()` emits an `E_WARNING` on a transient/failed lookup; a
     * scoped error handler keeps the fail-closed empty-result path clean without
     * resorting to the forbidden `@` silence operator. An unresolvable family
     * simply contributes no addresses.
     *
     * @return list<string>
     */
    private function resolveIpv6(string $host): array
    {
        set_error_handler(static fn(): bool => true);

        try {
            $records = dns_get_record($host, DNS_AAAA);
        } finally {
            restore_error_handler();
        }

        if ($records === false) {
            return [];
        }

        // `dns_get_record()` is natively typed as a loose `array` (inherent mixed,
        // like `json_decode`): narrow the AAAA record shape once so the column
        // extraction stays strictly typed.
        /** @var list<array<string, mixed>> $records */

        // Keep only well-formed AAAA answers; `array_column` drops records that
        // carry no `ipv6` key, and the string filter discards any foreign shape.
        return array_values(array_filter(array_column($records, 'ipv6'), is_string(...)));
    }
}
