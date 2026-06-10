<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Security;

use Psr\Http\Message\RequestInterface;
use Waffle\Commons\HttpClient\Exception\SsrfException;
use Waffle\Commons\Utils\Assert;

/**
 * Server-Side Request Forgery guard (SEC-02).
 *
 * For every outbound request the guard resolves the target host, asserts that
 * EVERY resolved address is publicly routable (no loopback / RFC 1918 / RFC
 * 4193 / link-local / CGNAT / multicast / reserved range — see
 * {@see Assert::isPublicIp()}), and returns `CURLOPT_RESOLVE` pinning entries
 * so the transport reuses the exact vetted IPs.
 *
 * Pinning closes the TOCTOU / DNS-rebinding window: validating an address and
 * then letting cURL resolve again could connect to a different (internal) IP
 * on the second lookup. Pre-populating cURL's DNS cache guarantees the
 * connection targets the address we validated — and no other. If ANY resolved
 * address is non-public the whole request is refused (fail-closed), defeating
 * a rebinding record that mixes a public and a private answer.
 *
 * Stateless across requests (FrankenPHP rule): the resolver is injected and the
 * guard holds no per-request state.
 */
final readonly class SsrfGuard
{
    public function __construct(
        private HostResolverInterface $resolver = new SystemHostResolver(),
    ) {}

    /**
     * @return list<string> `CURLOPT_RESOLVE` entries (`host:port:ip[,ip...]`).
     *         Empty for a literal-IP host — there is no DNS step to pin and the
     *         literal has already been validated.
     *
     * @throws SsrfException When the host is missing, unresolvable, or resolves
     *         to a non-public address.
     */
    public function resolvePins(RequestInterface $request): array
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            throw new SsrfException($request, 'Refusing an outbound request with no host.');
        }

        $bareHost = trim($host, '[]');
        $isLiteral = filter_var($bareHost, FILTER_VALIDATE_IP) !== false;

        $addresses = $isLiteral ? [$bareHost] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new SsrfException($request, sprintf('Host "%s" could not be resolved.', $host));
        }

        foreach ($addresses as $address) {
            if (!Assert::isPublicIp($address)) {
                throw new SsrfException($request, sprintf(
                    'Host "%s" resolves to the non-public address %s; refusing (SSRF).',
                    $host,
                    $address,
                ));
            }
        }

        if ($isLiteral) {
            return [];
        }

        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return [sprintf('%s:%d:%s', $host, $port, implode(',', $addresses))];
    }
}
