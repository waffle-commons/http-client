<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Security;

/**
 * Resolves a host name to the IP addresses it currently points at.
 *
 * Abstracted so {@see SsrfGuard} can be unit-tested deterministically and so an
 * application may substitute a caching or DNS-over-HTTPS resolver.
 * Implementations MUST be stateless across requests (FrankenPHP worker rule).
 */
interface HostResolverInterface
{
    /**
     * @return list<string> Resolved IP addresses (IPv4 and/or IPv6). An empty
     *         list signals an unresolvable host. A literal-IP host resolves to
     *         itself.
     */
    public function resolve(string $host): array;
}
