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
        // A literal IP must NOT trigger a DNS lookup.
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->never());

        self::assertSame(['203.0.113.7'], new SystemHostResolver()->resolve('203.0.113.7'));
    }

    public function testLiteralIpv6ShortCircuitsWithoutLookup(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->never());

        self::assertSame(['2606:4700:4700::1111'], new SystemHostResolver()->resolve('2606:4700:4700::1111'));
    }

    public function testHostnameResolvesViaGethostbynamel(): void
    {
        $this
            ->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())
            ->with('api.example.com')
            ->willReturn(['93.184.216.34', '93.184.216.35']);

        self::assertSame(['93.184.216.34', '93.184.216.35'], new SystemHostResolver()->resolve('api.example.com'));
    }

    public function testUnresolvableHostReturnsEmptyList(): void
    {
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->once())->willReturn(false);

        self::assertSame([], new SystemHostResolver()->resolve('ghost.invalid'));
    }
}
