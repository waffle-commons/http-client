<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Exception\HttpClientException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Exception\SsrfException;
use Waffle\Commons\HttpClient\Security\HostResolverInterface;
use Waffle\Commons\HttpClient\Security\SsrfGuard;
use Waffle\Commons\HttpClient\Transfer;

/**
 * Exercises the ASYNC-02 concurrent fan-out ({@see Client::sendRequests()}) and
 * the non-blocking promise ({@see Client::promise()}) against the scripted
 * multi-transfer harness.
 */
#[CoversClass(Client::class)]
#[CoversClass(Transfer::class)]
final class ClientConcurrencyTest extends ConcurrencyTestCase
{
    public function testSendRequestsReturnsEmptyForNoRequests(): void
    {
        // No transfer surface primed: an empty batch must short-circuit before
        // touching cURL at all.
        $client = new Client($this->psr17, $this->psr17);

        self::assertSame([], $client->sendRequests([]));
    }

    public function testSendRequestsFanOutPreservesKeysAndBodies(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "Content-Type: text/plain\r\n", "\r\n"], ['first-body']);
        $this->queueResponse(["HTTP/1.1 201 Created\r\n", "\r\n"], ['second-body']);
        $this->queueResponse(["HTTP/1.1 202 Accepted\r\n", "\r\n"], ['third-body']);

        $client = new Client($this->psr17, $this->psr17);
        $responses = $client->sendRequests([
            'alpha' => $this->psr17->createRequest('GET', 'https://a.example.com/'),
            'beta' => $this->psr17->createRequest('GET', 'https://b.example.com/'),
            7 => $this->psr17->createRequest('GET', 'https://c.example.com/'),
        ]);

        self::assertSame(['alpha', 'beta', 7], array_keys($responses));

        $alpha = $this->responseAt($responses, 'alpha');
        self::assertSame(200, $alpha->getStatusCode());
        self::assertSame('text/plain', $alpha->getHeaderLine('content-type'));
        self::assertSame('first-body', (string) $alpha->getBody());

        $beta = $this->responseAt($responses, 'beta');
        self::assertSame(201, $beta->getStatusCode());
        self::assertSame('second-body', (string) $beta->getBody());

        $third = $this->responseAt($responses, 7);
        self::assertSame(202, $third->getStatusCode());
        self::assertSame('third-body', (string) $third->getBody());
    }

    public function testSendRequestsForwardsRequestBodyPerHandle(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['ok']);

        $client = new Client($this->psr17, $this->psr17);
        $body = '{"order":1}';
        $request = $this->psr17
            ->createRequest('POST', 'https://example.com/orders')
            ->withBody($this->psr17->createStream($body));

        $responses = $client->sendRequests([$request]);

        self::assertSame(200, $this->responseAt($responses, 0)->getStatusCode());

        // ASYNC02-03: the streamed-upload options are wired on the request's OWN
        // easy handle, not the persistent single-request handle.
        self::assertCount(1, $this->recorder->scalarOptionsByHandle);
        $scalar = array_values($this->recorder->scalarOptionsByHandle)[0] ?? null;
        self::assertIsArray($scalar);
        self::assertArrayHasKey(CURLOPT_UPLOAD, $scalar);
        self::assertTrue($scalar[CURLOPT_UPLOAD]);
        self::assertArrayHasKey(CURLOPT_READFUNCTION, $scalar);
        self::assertArrayHasKey(CURLOPT_INFILESIZE, $scalar);
        self::assertSame(strlen($body), $scalar[CURLOPT_INFILESIZE]);
    }

    public function testSendRequestsFailFastOnNetworkError(): void
    {
        $this->primeMultiTransfer();
        // Two requests, DISTINCT per-handle outcomes: the first succeeds, the
        // second times out. The component must read each handle's own terminal
        // result (curl_errno/curl_error) and surface the SECOND request's failure
        // — proving the result is read per handle, not globally (ASYNC02-06).
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['ok']);
        $this->queueResponse([], [], CURLE_OPERATION_TIMEOUTED, 'Operation timed out');

        $client = new Client($this->psr17, $this->psr17);
        $requests = [
            $this->psr17->createRequest('GET', 'https://ok.example.com/'),
            $this->psr17->createRequest('GET', 'https://slow.example.com/'),
        ];

        try {
            $client->sendRequests($requests);
            self::fail('Expected NetworkException');
        } catch (NetworkException $exception) {
            self::assertSame($requests[1], $exception->getRequest());
            self::assertSame(CURLE_OPERATION_TIMEOUTED, $exception->getCode());
            self::assertStringContainsString('Operation timed out', $exception->getMessage());
        }
    }

    public function testSendRequestsMissingStatusLineThrowsRequestException(): void
    {
        $this->primeMultiTransfer();
        // Transfer succeeds at the transport layer but no status line is parsed.
        $this->queueResponse([], []);

        $client = new Client($this->psr17, $this->psr17);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        try {
            $client->sendRequests([$request]);
            self::fail('Expected RequestException');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertStringContainsString('No HTTP status line', $exception->getMessage());
        }
    }

    public function testSendRequestsThrowsWhenPerRequestHandleCannotBeAllocated(): void
    {
        // The constructor's curl_init() succeeds; the per-request allocation fails.
        $calls = 0;
        $this
            ->getFunctionMock(self::NS, 'curl_init')
            ->expects($this->any())
            ->willReturnCallback(static function () use (&$calls): \CurlHandle|false {
                $calls++;
                return $calls === 1 ? \curl_init() : false;
            });
        $this->primeMultiTransferWithoutInit();

        $client = new Client($this->psr17, $this->psr17);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Failed to initialise a per-request cURL handle.');
        $client->sendRequests([$this->psr17->createRequest('GET', 'https://example.com/')]);
    }

    public function testSendRequestsSsrfGuardBlocksPrivateResolution(): void
    {
        $this->primeMultiTransfer();
        $guard = new SsrfGuard(new class implements HostResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        });

        $client = new Client($this->psr17, $this->psr17, $guard);

        $blocked = false;
        try {
            $client->sendRequests([$this->psr17->createRequest('GET', 'https://internal.example.com/')]);
        } catch (SsrfException) {
            $blocked = true;
        }

        self::assertTrue($blocked, 'A private-resolving host must be rejected.');
        // Fail-closed PER handle: the guard refuses BEFORE the easy handle is ever
        // configured, so no transfer options were captured for the blocked host.
        self::assertSame([], $this->recorder->optionsByHandle, 'No handle should be configured for a blocked host.');
    }

    public function testSendRequestsSsrfGuardPinsResolvedHostPerHandle(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['first']);
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['second']);
        $guard = new SsrfGuard(new class implements HostResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                // Distinct vetted IP per host so the per-handle pin is unambiguous.
                return [$host === 'a.example.com' ? '93.184.216.34' : '93.184.216.35'];
            }
        });

        $client = new Client($this->psr17, $this->psr17, $guard);
        $responses = $client->sendRequests([
            $this->psr17->createRequest('GET', 'https://a.example.com/'),
            $this->psr17->createRequest('GET', 'https://b.example.com/'),
        ]);

        self::assertSame(200, $this->responseAt($responses, 0)->getStatusCode());
        self::assertSame(200, $this->responseAt($responses, 1)->getStatusCode());

        // ASYNC02-03: EVERY easy handle must carry its own CURLOPT_RESOLVE pin.
        $pins = $this->capturedResolvePins();
        self::assertCount(2, $pins, 'Each request gets its own configured handle.');
        self::assertContains(['a.example.com:443:93.184.216.34'], $pins);
        self::assertContains(['b.example.com:443:93.184.216.35'], $pins);
    }

    public function testSendRequestsWithoutGuardSetsNoResolvePin(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['ok']);

        $client = new Client($this->psr17, $this->psr17);
        $client->sendRequests([$this->psr17->createRequest('GET', 'https://example.com/')]);

        // No guard injected → no CURLOPT_RESOLVE pin is ever applied.
        foreach ($this->recorder->optionsByHandle as $options) {
            self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        }
        self::assertCount(1, $this->recorder->optionsByHandle);
    }

    public function testPromiseResolvesFulfilled(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "\r\n"], ['{"ok":true}']);

        $client = new Client($this->psr17, $this->psr17);
        $promise = $client->promise($this->psr17->createRequest('GET', 'https://example.com/'));

        self::assertSame(PromiseState::Pending, $promise->state());

        $response = $promise->wait();

        self::assertSame(PromiseState::Fulfilled, $promise->state());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testPromiseThenCallbackFiresWithResponse(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 204 No Content\r\n", "\r\n"], []);

        $client = new Client($this->psr17, $this->psr17);
        $seen = null;
        $promise = $client
            ->promise($this->psr17->createRequest('GET', 'https://example.com/'))
            ->then(static function (ResponseInterface $response) use (&$seen): void {
                $seen = $response->getStatusCode();
            });

        $promise->wait();

        self::assertSame(204, $seen);
    }

    public function testPromiseRejectsAndFiresCatch(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse([], [], CURLE_COULDNT_CONNECT, 'Connection refused');

        $client = new Client($this->psr17, $this->psr17);
        $seenCode = null;
        $seenClass = null;
        $promise = $client
            ->promise($this->psr17->createRequest('GET', 'https://example.com/'))
            ->catch(static function (Throwable $error) use (&$seenCode, &$seenClass): void {
                $seenCode = $error->getCode();
                $seenClass = $error::class;
            });

        try {
            $promise->wait();
            self::fail('Expected NetworkException');
        } catch (NetworkException $exception) {
            self::assertSame(CURLE_COULDNT_CONNECT, $exception->getCode());
        }

        self::assertSame(PromiseState::Rejected, $promise->state());
        // The catch callback observed the rejection.
        self::assertSame(NetworkException::class, $seenClass);
        self::assertSame(CURLE_COULDNT_CONNECT, $seenCode);
    }

    public function testPromiseSsrfGuardBlocksBeforeTransfer(): void
    {
        $this->primeMultiTransfer();
        $guard = new SsrfGuard(new class implements HostResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                return ['10.0.0.1'];
            }
        });

        $client = new Client($this->psr17, $this->psr17, $guard);

        $this->expectException(SsrfException::class);
        $client->promise($this->psr17->createRequest('GET', 'https://internal.example.com/'));
    }

    public function testPromiseSsrfGuardPinsResolvedHostOnItsHandle(): void
    {
        $this->primeMultiTransfer();
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['ok']);
        $guard = new SsrfGuard(new class implements HostResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $client = new Client($this->psr17, $this->psr17, $guard);
        $promise = $client->promise($this->psr17->createRequest('GET', 'https://example.com/'));
        $response = $promise->wait();

        self::assertSame(200, $response->getStatusCode());
        // ASYNC02-03: the promise's single easy handle carries the SSRF pin.
        self::assertSame([['example.com:443:93.184.216.34']], $this->capturedResolvePins());
    }

    public function testSendRequestsSurfacesMultiLoopFailure(): void
    {
        // ASYNC02-01: a non-OK curl_multi_exec() terminal status must NOT be
        // swallowed — it is surfaced as a transport-layer NetworkException rather
        // than reading a half-driven transfer's (CURLE_OK) result as success.
        $this->primeMultiTransfer(CURLM_INTERNAL_ERROR);
        $requests = [
            $this->psr17->createRequest('GET', 'https://a.example.com/'),
            $this->psr17->createRequest('GET', 'https://b.example.com/'),
        ];

        try {
            $client = new Client($this->psr17, $this->psr17);
            $client->sendRequests($requests);
            self::fail('Expected a NetworkException for the multi-loop failure.');
        } catch (NetworkException $exception) {
            self::assertSame($requests[0], $exception->getRequest());
            self::assertSame(CURLE_RECV_ERROR, $exception->getCode());
        }
    }

    public function testPromiseSurfacesMultiLoopFailure(): void
    {
        // ASYNC02-01: the promise resolver surfaces a CURLM_* failure too, instead
        // of treating the (never-driven) transfer as if it had completed.
        $this->primeMultiTransfer(CURLM_INTERNAL_ERROR);
        $request = $this->psr17->createRequest('GET', 'https://example.com/');

        $client = new Client($this->psr17, $this->psr17);
        $promise = $client->promise($request);

        try {
            $promise->wait();
            self::fail('Expected a NetworkException for the multi-loop failure.');
        } catch (NetworkException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame(CURLE_RECV_ERROR, $exception->getCode());
        }

        self::assertSame(PromiseState::Rejected, $promise->state());
    }

    public function testDriveBacksOffWhenSelectReportsNoFileDescriptors(): void
    {
        // ASYNC02-05: when curl_multi_select() returns -1 (no fds to wait on) the
        // drive loop must usleep a small fixed interval instead of busy-spinning a
        // CPU at 100%. The harness scripts a single -1 and counts the back-off.
        $this->primeMultiTransfer();
        $this->scriptSelectReturns([-1]);
        $this->queueResponse(["HTTP/1.1 200 OK\r\n", "\r\n"], ['ok']);

        $client = new Client($this->psr17, $this->psr17);
        $responses = $client->sendRequests([$this->psr17->createRequest('GET', 'https://example.com/')]);

        self::assertSame(200, $this->responseAt($responses, 0)->getStatusCode());
        self::assertGreaterThan(
            0,
            $this->recorder->sleptMicros,
            'The -1 select must trigger a back-off sleep, not a busy-spin.',
        );
    }

    public function testUnwaitedPromiseFreesItsHandles(): void
    {
        // ASYNC02-04: a promise whose wait() is never called must still detach +
        // close its eagerly-allocated handles when discarded. The harness counts
        // curl_multi_remove_handle / curl_multi_close to prove cleanup ran.
        $this->primeMultiTransfer();

        $client = new Client($this->psr17, $this->psr17);
        $promise = $client->promise($this->psr17->createRequest('GET', 'https://example.com/'));
        self::assertSame(PromiseState::Pending, $promise->state());

        $removeBefore = $this->recorder->multiRemoveCalls;
        $closeBefore = $this->recorder->multiCloseCalls;

        // Discard without waiting → the destructor must release the handles.
        unset($promise);

        self::assertSame(
            $removeBefore + 1,
            $this->recorder->multiRemoveCalls,
            'The per-promise easy handle must be detached.',
        );
        self::assertSame(
            $closeBefore + 1,
            $this->recorder->multiCloseCalls,
            'The per-promise multi handle must be closed.',
        );
    }

    /**
     * Assert a response exists at the given key and return it narrowed to a
     * non-null {@see ResponseInterface}, so subsequent accessors are type-clean.
     *
     * @param array<array-key, ResponseInterface> $responses
     */
    private function responseAt(array $responses, string|int $key): ResponseInterface
    {
        self::assertArrayHasKey($key, $responses);
        $response = $responses[$key] ?? null;
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * The `CURLOPT_RESOLVE` pin captured on each per-request handle, in dispatch
     * order. A handle with no pin is omitted, so the result proves which handles
     * were actually pinned (ASYNC02-03).
     *
     * @return list<list<string>>
     */
    private function capturedResolvePins(): array
    {
        $pins = [];
        foreach ($this->recorder->optionsByHandle as $options) {
            $resolve = $options[CURLOPT_RESOLVE] ?? null;
            if (is_array($resolve)) {
                /** @var list<string> $resolve */
                $pins[] = array_values($resolve);
            }
        }

        return $pins;
    }
}
