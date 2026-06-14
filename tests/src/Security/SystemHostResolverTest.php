<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Security;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\HttpClient\Security\SystemHostResolver;

#[CoversClass(SystemHostResolver::class)]
final class SystemHostResolverTest extends TestCase
{
    use PHPMock;

    private const string NS = 'Waffle\\Commons\\HttpClient\\Security';

    public function testLiteralIpv4ShortCircuitsWithoutLookup(): void
    {
        // A literal IP must NOT trigger any DNS lookup (neither family).
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->never());
        $this->getFunctionMock(self::NS, 'dns_get_record')->expects($this->never());

        self::assertSame(['203.0.113.7'], new SystemHostResolver()->resolve('203.0.113.7'));
    }

    public function testLiteralIpv6ShortCircuitsWithoutLookup(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->never());
        $this->getFunctionMock(self::NS, 'dns_get_record')->expects($this->never());

        self::assertSame(['2606:4700:4700::1111'], new SystemHostResolver()->resolve('2606:4700:4700::1111'));
    }

    public function testHostnameResolvesIpv4ViaGethostbynamel(): void
    {
        $this
            ->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())
            ->with('api.example.com')
            ->willReturn(['93.184.216.34', '93.184.216.35']);
        // No AAAA record for this host.
        $this->getFunctionMock(self::NS, 'dns_get_record')->expects($this->once())->willReturn(false);

        self::assertSame(['93.184.216.34', '93.184.216.35'], new SystemHostResolver()->resolve('api.example.com'));
    }

    public function testHostnameResolvesIpv6ViaDnsGetRecord(): void
    {
        // IPv6-only host: no A-record, AAAA present — now reachable through the guard.
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->once())->willReturn(false);
        $this
            ->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())
            ->with('v6.example.com', DNS_AAAA)
            ->willReturn([
                ['host' => 'v6.example.com', 'type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
            ]);

        self::assertSame(['2606:4700:4700::1111'], new SystemHostResolver()->resolve('v6.example.com'));
    }

    public function testDualStackUnionsBothFamilies(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->once())->willReturn(['93.184.216.34']);
        $this
            ->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())
            ->willReturn([
                ['ipv6' => '2606:4700:4700::1111'],
                ['ipv6' => '2606:4700:4700::1001'],
            ]);

        self::assertSame(
            ['93.184.216.34', '2606:4700:4700::1111', '2606:4700:4700::1001'],
            new SystemHostResolver()->resolve('dual.example.com'),
        );
    }

    public function testUnresolvableHostReturnsEmptyList(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->once())->willReturn(false);
        $this->getFunctionMock(self::NS, 'dns_get_record')->expects($this->once())->willReturn(false);

        self::assertSame([], new SystemHostResolver()->resolve('ghost.invalid'));
    }

    public function testAaaaRecordsWithoutIpv6KeyAreIgnored(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->once())->willReturn(['93.184.216.34']);
        // A malformed/foreign record shape contributes nothing (fail-soft).
        $this
            ->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())
            ->willReturn([['host' => 'x.example.com', 'type' => 'AAAA']]);

        self::assertSame(['93.184.216.34'], new SystemHostResolver()->resolve('x.example.com'));
    }
}
