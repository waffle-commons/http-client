<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Security;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\HttpClient\Exception\SsrfException;
use Waffle\Commons\HttpClient\Security\HostResolverInterface;
use Waffle\Commons\HttpClient\Security\SsrfGuard;

#[CoversClass(SsrfGuard::class)]
#[CoversClass(SsrfException::class)]
final class SsrfGuardTest extends TestCase
{
    private Psr17Factory $psr17;

    #[\Override]
    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    public function testPublicHostIsPinnedToResolvedIp(): void
    {
        $guard = $this->guardResolving(['api.example.com' => ['93.184.216.34']]);
        $request = $this->psr17->createRequest('GET', 'https://api.example.com/v1/orders');

        self::assertSame(['api.example.com:443:93.184.216.34'], $guard->resolvePins($request));
    }

    public function testDefaultHttpPortDerivedFromScheme(): void
    {
        $guard = $this->guardResolving(['api.example.com' => ['93.184.216.34', '8.8.8.8']]);
        $request = $this->psr17->createRequest('GET', 'http://api.example.com/');

        self::assertSame(['api.example.com:80:93.184.216.34,8.8.8.8'], $guard->resolvePins($request));
    }

    public function testExplicitPortIsPreserved(): void
    {
        $guard = $this->guardResolving(['svc.example.com' => ['1.1.1.1']]);
        $request = $this->psr17->createRequest('GET', 'https://svc.example.com:8443/health');

        self::assertSame(['svc.example.com:8443:1.1.1.1'], $guard->resolvePins($request));
    }

    public function testPrivateResolutionIsRejected(): void
    {
        // Classic cloud-metadata SSRF: a benign-looking host resolving to 169.254.
        $guard = $this->guardResolving(['evil.example.com' => ['169.254.169.254']]);
        $request = $this->psr17->createRequest('GET', 'https://evil.example.com/latest/meta-data');

        try {
            $guard->resolvePins($request);
            self::fail('Expected SsrfException');
        } catch (SsrfException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertStringContainsString('non-public address 169.254.169.254', $exception->getMessage());
        }
    }

    public function testAnyPrivateAddressInResolvedSetIsRejected(): void
    {
        // DNS rebinding: one public + one private answer ⇒ refuse outright.
        $guard = $this->guardResolving(['mixed.example.com' => ['8.8.8.8', '10.0.0.5']]);
        $request = $this->psr17->createRequest('GET', 'https://mixed.example.com/');

        $this->expectException(SsrfException::class);
        $guard->resolvePins($request);
    }

    public function testUnresolvableHostIsRejected(): void
    {
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'https://ghost.example.com/');

        $this->expectException(SsrfException::class);
        $this->expectExceptionMessage('could not be resolved');
        $guard->resolvePins($request);
    }

    public function testMissingHostIsRejected(): void
    {
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'file:///etc/passwd');

        $this->expectException(SsrfException::class);
        $this->expectExceptionMessage('no host');
        $guard->resolvePins($request);
    }

    public function testPublicLiteralIpv4HostNeedsNoPinning(): void
    {
        // Literal IPs have no DNS step to rebind; validation alone suffices.
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'https://93.184.216.34/');

        self::assertSame([], $guard->resolvePins($request));
    }

    public function testPublicLiteralIpv6HostNeedsNoPinning(): void
    {
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'https://[2606:4700:4700::1111]/');

        self::assertSame([], $guard->resolvePins($request));
    }

    public function testPrivateLiteralIpv4HostIsRejected(): void
    {
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'http://127.0.0.1/');

        $this->expectException(SsrfException::class);
        $guard->resolvePins($request);
    }

    public function testPrivateLiteralIpv6HostIsRejected(): void
    {
        $guard = $this->guardResolving([]);
        $request = $this->psr17->createRequest('GET', 'https://[::1]/');

        $this->expectException(SsrfException::class);
        $guard->resolvePins($request);
    }

    public function testAllowlistedHostNameBypassesPublicAssertion(): void
    {
        // A trusted internal backend resolving to a private IP is allowed through
        // (no pins) when explicitly allow-listed — this is what lets the guard be
        // wired on by default without breaking internal calls.
        $guard = $this->guardResolving(['legacy-backend' => ['10.0.0.5']], ['legacy-backend']);
        $request = $this->psr17->createRequest('GET', 'http://legacy-backend/api/users');

        self::assertSame([], $guard->resolvePins($request));
    }

    public function testAllowlistMatchIsCaseInsensitive(): void
    {
        $guard = $this->guardResolving(['legacy-backend' => ['10.0.0.5']], ['legacy-backend']);
        $request = $this->psr17->createRequest('GET', 'http://Legacy-Backend/health');

        self::assertSame([], $guard->resolvePins($request));
    }

    public function testAllowlistedLiteralIpWithinCidrBypasses(): void
    {
        $guard = $this->guardResolving([], ['10.0.0.0/8']);
        $request = $this->psr17->createRequest('GET', 'http://10.1.2.3:8080/internal');

        self::assertSame([], $guard->resolvePins($request));
    }

    public function testNonAllowlistedPrivateHostIsStillRejected(): void
    {
        // The allow-list is exact: a different internal host is not exempt.
        $guard = $this->guardResolving(['other-internal' => ['10.0.0.9']], ['legacy-backend']);
        $request = $this->psr17->createRequest('GET', 'http://other-internal/');

        $this->expectException(SsrfException::class);
        $guard->resolvePins($request);
    }

    /**
     * @param array<string, list<string>> $map          host => resolved IPs
     * @param list<string>                 $allowedHosts trusted host names / CIDRs
     */
    private function guardResolving(array $map, array $allowedHosts = []): SsrfGuard
    {
        $resolver = new class($map) implements HostResolverInterface {
            /**
             * @param array<string, list<string>> $map
             */
            public function __construct(
                private array $map,
            ) {}

            #[\Override]
            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        };

        return new SsrfGuard($resolver, $allowedHosts);
    }
}
