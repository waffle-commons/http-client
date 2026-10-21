<?php

declare(strict_types=1);

namespace Waffle\Commons\HttpClient\Resilience;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\HttpClient\Exception\RequestException;
use Waffle\Commons\HttpClient\Promise\Promise;
use Waffle\Commons\HttpClient\SettlingClientInterface;

/**
 * How the resilience decorators settle requests ([NET-02], [NET-03], RFC-024 §3.3): every request ends with
 * its response or with the client exception it failed with, so the breaker counts each outcome and the retry
 * policy sends again only what failed.
 *
 * Pure and stateless: every outcome lives in the arrays it returns.
 */
final class Settlement
{
    /**
     * Sealed: a static-only helper that must never be instantiated.
     *
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * Settles a batch through `$client`: concurrently when it is a {@see SettlingClientInterface}, one request
     * at a time otherwise. A batch of one goes through `sendRequest()`, whose persistent handle keeps the
     * connection warm.
     *
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface|ClientExceptionInterface> One outcome per request, in the
     *         batch's key order.
     */
    public static function through(ClientInterface $client, array $requests): array
    {
        if ($client instanceof SettlingClientInterface && count($requests) > 1) {
            return $client->settleRequests($requests);
        }

        $outcomes = [];
        foreach ($requests as $key => $request) {
            $outcomes[$key] = self::one($client, $request);
        }

        return $outcomes;
    }

    /** Sends one request and settles it: its response, or the client exception it failed with. */
    public static function one(
        ClientInterface $client,
        RequestInterface $request,
    ): ResponseInterface|ClientExceptionInterface {
        try {
            return $client->sendRequest($request);
        } catch (ClientExceptionInterface $failure) {
            return $failure;
        }
    }

    /**
     * The fail-fast view of a settled batch, as {@see \Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface::sendRequests()}
     * answers it.
     *
     * @param array<array-key, ResponseInterface|ClientExceptionInterface> $outcomes
     *
     * @return array<array-key, ResponseInterface>
     *
     * @throws ClientExceptionInterface The first failure, in key order.
     */
    public static function responsesOrThrow(array $outcomes): array
    {
        $responses = [];
        foreach ($outcomes as $key => $outcome) {
            $responses[$key] = self::unwrap($outcome);
        }

        return $responses;
    }

    /**
     * The response of a settled request.
     *
     * @throws ClientExceptionInterface The failure the request settled with.
     */
    public static function unwrap(ResponseInterface|ClientExceptionInterface $outcome): ResponseInterface
    {
        if ($outcome instanceof ClientExceptionInterface) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * The outcome of a request a settling client returned nothing for: a failure, so no response is ever
     * invented for it.
     */
    public static function unsettled(RequestInterface $request): ClientExceptionInterface
    {
        return new RequestException($request, 'The settling client returned no outcome for this request.');
    }

    /**
     * A promise that sends `$request` through `$client` once it is waited on, so every check the client runs
     * (circuit, retries, SSRF guard) runs then, before any handle is allocated.
     */
    public static function deferred(ClientInterface $client, RequestInterface $request): PromiseInterface
    {
        return new Promise(
            static fn(): ResponseInterface => $client->sendRequest($request),
            static function (): void {},
        );
    }
}
