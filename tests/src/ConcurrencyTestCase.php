<?php

declare(strict_types=1);

namespace WaffleTests\Commons\HttpClient;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use Nyholm\Psr7\Factory\Psr17Factory;
use phpmock\phpunit\PHPMock;

/**
 * Shared cURL mock harness for the concurrent fan-out / promise paths.
 *
 * Unlike the single-request {@see ClientTest} harness — which drives the one
 * persistent handle — these paths allocate a dedicated easy handle per request
 * on a per-call multi handle. The harness therefore captures the header / write
 * callbacks per handle and, on `curl_multi_exec`, fires every registered
 * handle's callbacks so each in-flight transfer settles with its own scripted
 * response. Scripts are matched to handles by dispatch order (the Nth allocated
 * transfer handle receives the Nth queued script).
 */
abstract class ConcurrencyTestCase extends AbstractTestCase
{
    use PHPMock;

    protected const string NS = 'Waffle\\Commons\\HttpClient';

    protected Psr17Factory $psr17;

    /** Everything the harness captures per transfer (per-handle options, results, cleanup counts). */
    protected TransferRecorder $recorder;

    /** curl_multi_exec status to return (CURLM_OK drives the transfer to completion). */
    private int $multiStatus = CURLM_OK;

    /** Whether the shared curl_multi_exec loop has already fired callbacks. */
    private bool $fired = false;

    /**
     * curl_multi_select() return values to hand out in order; once exhausted the
     * harness falls back to 1 (a ready fd). Lets a test drive the no-fds (-1)
     * back-off branch (ASYNC02-05).
     *
     * @var list<int>
     */
    private array $selectReturns = [];

    /**
     * The cURL functions this harness intercepts.
     *
     * @var list<string>
     */
    private const array MOCKED_FUNCTIONS = [
        'curl_init',
        'curl_multi_init',
        'curl_multi_add_handle',
        'curl_multi_remove_handle',
        'curl_multi_close',
        'curl_multi_select',
        'curl_multi_exec',
        'curl_setopt',
        'curl_setopt_array',
        'curl_errno',
        'curl_error',
        'usleep',
    ];

    /**
     * Pre-define every mocked function's namespaced shadow ONCE per class so the
     * production code always resolves to the mock, regardless of which test runs
     * first (php-mock Bug #68541): when a class re-mocks the same function with
     * different behaviour across tests, defining the shadow up-front is required
     * for the new per-test behaviour to actually take effect.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (self::MOCKED_FUNCTIONS as $function) {
            self::defineFunctionMock(self::NS, $function);
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->psr17 = new Psr17Factory();
        $this->recorder = new TransferRecorder();
        $this->multiStatus = CURLM_OK;
        $this->fired = false;
        $this->selectReturns = [];
    }

    /**
     * Script the next curl_multi_select() return values (consumed in order). A -1
     * simulates the no-file-descriptors case that must trigger the back-off guard.
     *
     * @param list<int> $returns
     */
    protected function scriptSelectReturns(array $returns): void
    {
        $this->selectReturns = $returns;
    }

    /**
     * Wire the full per-request multi-transfer surface so a fan-out / promise
     * call runs end-to-end without touching the network. Per-request handles are
     * real (allocated by the native `curl_init`); only the transfer DRIVE and
     * RESULT functions are scripted.
     *
     * @param int $multiStatus curl_multi_exec status (CURLM_* — non-OK simulates a multi failure).
     */
    protected function primeMultiTransfer(int $multiStatus = CURLM_OK): void
    {
        $this->mockCurlInit();
        $this->primeMultiTransferWithoutInit($multiStatus);
    }

    /**
     * Mock `curl_init` to hand out real handles. Kept separate so a test can
     * instead script `curl_init` (e.g. to simulate an allocation failure) before
     * priming the rest of the surface via {@see self::primeMultiTransferWithoutInit()}.
     */
    protected function mockCurlInit(): void
    {
        $this
            ->getFunctionMock(self::NS, 'curl_init')
            ->expects($this->any())
            ->willReturnCallback(static fn(): CurlHandle|false => \curl_init());
    }

    /**
     * Prime every part of the multi-transfer surface EXCEPT `curl_init`.
     *
     * @param int $multiStatus curl_multi_exec status (CURLM_* — non-OK simulates a multi failure).
     */
    protected function primeMultiTransferWithoutInit(int $multiStatus = CURLM_OK): void
    {
        $this->multiStatus = $multiStatus;

        $this
            ->getFunctionMock(self::NS, 'curl_multi_init')
            ->expects($this->any())
            ->willReturnCallback(static fn(): CurlMultiHandle => \curl_multi_init());

        $this->getFunctionMock(self::NS, 'curl_multi_add_handle')->expects($this->any())->willReturn(0);
        $this
            ->getFunctionMock(self::NS, 'curl_multi_remove_handle')
            ->expects($this->any())
            ->willReturnCallback(function (): int {
                $this->recorder->multiRemoveCalls++;
                return 0;
            });
        $this
            ->getFunctionMock(self::NS, 'curl_multi_close')
            ->expects($this->any())
            ->willReturnCallback(function (): void {
                $this->recorder->multiCloseCalls++;
            });
        $this
            ->getFunctionMock(self::NS, 'curl_multi_select')
            ->expects($this->any())
            ->willReturnCallback(function (): int {
                return array_shift($this->selectReturns) ?? 1;
            });
        $this
            ->getFunctionMock(self::NS, 'usleep')
            ->expects($this->any())
            ->willReturnCallback(function (int $microseconds): void {
                $this->recorder->sleptMicros += $microseconds;
            });
        // Body-upload options are set with the scalar curl_setopt; intercept it so
        // the upload path never touches the real handle, and capture each call per
        // handle so a test can assert the per-handle upload wiring (ASYNC02-03).
        $this
            ->getFunctionMock(self::NS, 'curl_setopt')
            ->expects($this->any())
            ->willReturnCallback(function (CurlHandle $handle, int $option, mixed $value): bool {
                $this->recorder->scalarOptionsByHandle[spl_object_id($handle)][$option] = $value;

                return true;
            });

        $this
            ->getFunctionMock(self::NS, 'curl_setopt_array')
            ->expects($this->any())
            ->willReturnCallback(
                /**
                 * @param array<int, mixed> $options
                 */
                function (CurlHandle $handle, array $options): bool {
                    // Capture the FULL option set per handle so tests can assert
                    // per-handle wiring (e.g. the SSRF CURLOPT_RESOLVE pin),
                    // instead of a tautological pass (ASYNC02-03).
                    $this->recorder->optionsByHandle[spl_object_id($handle)] = $options;

                    $header = $options[CURLOPT_HEADERFUNCTION] ?? null;
                    $write = $options[CURLOPT_WRITEFUNCTION] ?? null;
                    if ($header instanceof Closure && $write instanceof Closure) {
                        $this->recorder->callbacks[spl_object_id($handle)] = [
                            'handle' => $handle,
                            'header' => $header,
                            'write' => $write,
                        ];
                    }
                    return true;
                },
            );

        $this
            ->getFunctionMock(self::NS, 'curl_multi_exec')
            ->expects($this->any())
            ->willReturnCallback(function (CurlMultiHandle $_mh, int &$running): int {
                if ($this->multiStatus !== CURLM_OK) {
                    $running = 0;
                    return $this->multiStatus;
                }
                if (!$this->fired) {
                    $this->fired = true;
                    $this->fireAll();
                    $running = 1; // still running → forces a curl_multi_select() + a second tick
                    return CURLM_OK;
                }
                $running = 0;
                return CURLM_OK;
            });

        $this
            ->getFunctionMock(self::NS, 'curl_errno')
            ->expects($this->any())
            ->willReturnCallback(function (CurlHandle $handle): int {
                return $this->recorder->errnoByHandle[spl_object_id($handle)] ?? CURLE_OK;
            });

        // Per-handle terminal error message, scripted alongside the errno so the
        // MULTI case can assert each handle's outcome is read off ITS OWN handle
        // via curl_errno()/curl_error() (ASYNC02-06).
        $this
            ->getFunctionMock(self::NS, 'curl_error')
            ->expects($this->any())
            ->willReturnCallback(function (CurlHandle $handle): string {
                return $this->recorder->errorByHandle[spl_object_id($handle)] ?? '';
            });
    }

    /**
     * Register the scripted response for the next request to be dispatched.
     *
     * @param list<string> $headers CRLF-terminated libcurl header lines (status line included).
     * @param list<string> $body    Body fragments passed to the write callback in order.
     * @param int          $errno   Terminal cURL result for this transfer (CURLE_*).
     * @param string       $error   Terminal cURL error message for this transfer (read via curl_error).
     */
    protected function queueResponse(array $headers, array $body, int $errno = CURLE_OK, string $error = ''): void
    {
        $this->recorder->pendingScripts[] = [
            'headers' => $headers,
            'body' => $body,
            'errno' => $errno,
            'error' => $error,
        ];
    }

    private function fireAll(): void
    {
        // Assign queued scripts to captured handles in capture (= dispatch) order,
        // then fire each handle's header + write callbacks to materialise its
        // response and record its terminal cURL result.
        $index = 0;
        foreach ($this->recorder->callbacks as $id => $entry) {
            $script = $this->recorder->pendingScripts[$index] ?? [
                'headers' => [],
                'body' => [],
                'errno' => CURLE_OK,
                'error' => '',
            ];
            $this->recorder->errnoByHandle[$id] = $script['errno'];
            $this->recorder->errorByHandle[$id] = $script['error'];
            $index++;

            foreach ($script['headers'] as $line) {
                $entry['header']($entry['handle'], $line);
            }
            foreach ($script['body'] as $chunk) {
                $entry['write']($entry['handle'], $chunk);
            }
        }
    }
}
