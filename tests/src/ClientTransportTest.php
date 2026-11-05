<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Transfer;

/**
 * The real transport, with no function mock: a refused connection is a NetworkException carrying its cURL
 * code on every path. PHP reports a multi-driven handle's result only once its completion message is read,
 * which the suites that mock curl_errno() cannot see.
 */
#[CoversClass(Client::class)]
#[CoversClass(Transfer::class)]
final class ClientTransportTest extends AbstractTestCase
{
    /** Nothing listens on port 1: the connection is refused at once, with no DNS involved. */
    private const string REFUSED = 'http://127.0.0.1:1/';

    public function testASingleRequestToARefusedPortIsANetworkFailure(): void
    {
        try {
            self::client()->sendRequest(self::request());
            static::fail('A refused connection must fail.');
        } catch (NetworkException $e) {
            static::assertSame(CURLE_COULDNT_CONNECT, $e->getCode());
        }
    }

    public function testABatchSettlesARefusedPortAsANetworkFailure(): void
    {
        $outcomes = self::client()->settleRequests(['a' => self::request(), 'b' => self::request()]);

        static::assertCount(2, $outcomes);
        foreach ($outcomes as $outcome) {
            static::assertInstanceOf(NetworkException::class, $outcome);
            static::assertSame(CURLE_COULDNT_CONNECT, $outcome->getCode());
        }
    }

    public function testAPromiseToARefusedPortRejectsWithANetworkFailure(): void
    {
        $this->expectException(NetworkException::class);
        $this->expectExceptionCode(CURLE_COULDNT_CONNECT);

        self::client()->promise(self::request())->wait();
    }

    private static function client(): Client
    {
        $factory = new Psr17Factory();

        return new Client($factory, $factory);
    }

    private static function request(): RequestInterface
    {
        return new Psr17Factory()->createRequest('GET', self::REFUSED);
    }
}
