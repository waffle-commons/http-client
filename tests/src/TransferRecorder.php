<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Closure;
use CurlHandle;

/**
 * Mutable recorder for everything the concurrent-transfer mock harness captures.
 *
 * Bundling the per-handle capture maps, the cleanup counters and the drive-loop
 * back-off into one holder keeps {@see ConcurrencyTestCase} from sprawling into
 * a dozen loose properties while letting a test read exactly what each transfer
 * did — per-handle options (the SSRF `CURLOPT_RESOLVE` pin, the upload options),
 * per-handle terminal result, handle-cleanup counts and select-loop back-off.
 */
final class TransferRecorder
{
    /**
     * Queued scripts awaiting handle assignment, in dispatch order.
     *
     * @var list<array{headers: list<string>, body: list<string>, errno: int, error: string}>
     */
    public array $pendingScripts = [];

    /**
     * Per-handle terminal cURL result, keyed by handle object id.
     *
     * @var array<int, int>
     */
    public array $errnoByHandle = [];

    /**
     * Per-handle terminal cURL error message, keyed by handle object id.
     *
     * @var array<int, string>
     */
    public array $errorByHandle = [];

    /**
     * Captured header / write callbacks, keyed by handle object id.
     *
     * @var array<int, array{handle: CurlHandle, header: Closure, write: Closure}>
     */
    public array $callbacks = [];

    /**
     * Full `curl_setopt_array` options captured PER handle, keyed by handle
     * object id. Lets a test assert that e.g. `CURLOPT_RESOLVE` was set on each
     * individual easy handle (ASYNC02-03).
     *
     * @var array<int, array<int, mixed>>
     */
    public array $optionsByHandle = [];

    /**
     * Scalar `curl_setopt` calls captured PER handle (the upload/body options are
     * set one at a time, not via the array form), keyed by handle object id then
     * by the cURL option constant — ASYNC02-03.
     *
     * @var array<int, array<int, mixed>>
     */
    public array $scalarOptionsByHandle = [];

    /** Number of curl_multi_remove_handle() calls, so a test can prove handle cleanup ran. */
    public int $multiRemoveCalls = 0;

    /** Number of curl_multi_close() calls, so a test can prove handle cleanup ran. */
    public int $multiCloseCalls = 0;

    /** Accumulated usleep() back-off, in microseconds, so a test can prove the busy-spin guard fired. */
    public int $sleptMicros = 0;
}
