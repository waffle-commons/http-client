<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient\Resilience;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\HttpClient\SettlingClientInterface;

/**
 * A settling client that plays back scripted outcomes per request URI, in order. It records the URIs of
 * every batch it settles and of every request it sends alone, and can forget to settle some keys, as a
 * faulty implementation would.
 */
final class ScriptedSettlingClient implements ClientInterface, SettlingClientInterface
{
    /** @var list<list<string>> */
    public private(set) array $batches = [];

    /** @var list<string> */
    public private(set) array $sent = [];

    /** @var array<string, int> */
    private array $served = [];

    /**
     * @param array<string, list<ResponseInterface|ClientExceptionInterface>> $script
     * @param list<array-key> $forget Keys whose outcome the batch leaves out.
     */
    public function __construct(
        private readonly array $script,
        private readonly array $forget = [],
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();
        $outcome = $this->next($request);
        if ($outcome instanceof ClientExceptionInterface) {
            throw $outcome;
        }

        return $outcome;
    }

    #[\Override]
    public function settleRequests(array $requests): array
    {
        $batch = [];
        $outcomes = [];
        foreach ($requests as $key => $request) {
            $batch[] = (string) $request->getUri();
            if (in_array($key, $this->forget, true)) {
                continue;
            }

            $outcomes[$key] = $this->next($request);
        }

        $this->batches[] = $batch;

        return $outcomes;
    }

    #[\Override]
    public function sendRequests(array $requests): array
    {
        throw new LogicException('The decorators settle a batch; they never send it fail-fast.');
    }

    #[\Override]
    public function promise(RequestInterface $request): PromiseInterface
    {
        throw new LogicException('The decorators defer a promise to their own sendRequest().');
    }

    private function next(RequestInterface $request): ResponseInterface|ClientExceptionInterface
    {
        $uri = (string) $request->getUri();
        $index = $this->served[$uri] ?? 0;
        $this->served[$uri] = $index + 1;

        return $this->script[$uri][$index] ?? throw new LogicException('No scripted outcome left for ' . $uri . '.');
    }
}
