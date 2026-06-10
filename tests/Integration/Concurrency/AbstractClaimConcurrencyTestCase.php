<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Concurrency;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Enum\ClaimStatus;

/**
 * Spawns real OS processes (tests/Integration/Concurrency/worker.php) that
 * contend for the same idempotency key behind a file-based start barrier.
 * Separate processes are used instead of pcntl_fork so every worker owns its
 * connection and PHPUnit internals are never shared across a fork.
 */
abstract class AbstractClaimConcurrencyTestCase extends TestCase
{
    private const WORKERS = 8;
    private const ROUNDS = 5;
    private const BARRIER_TIMEOUT_SECONDS = 15.0;

    /** Backend identifier understood by worker.php: mysql|postgres|redis. */
    abstract protected function backend(): string;

    abstract protected function isConfigured(): bool;

    abstract protected function prepareStorage(): void;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isConfigured()) {
            self::markTestSkipped(sprintf('Stress test config for "%s" is missing.', $this->backend()));
        }

        $this->prepareStorage();
    }

    public function testExactlyOneConcurrentClaimWins(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $key = sprintf('stress-%s-%d-%s', $this->backend(), $round, bin2hex(random_bytes(4)));
            $statuses = $this->runContendedClaims($key);
            $counts = array_count_values($statuses);
            $context = sprintf('round %d, key "%s": %s', $round, $key, json_encode($counts, JSON_THROW_ON_ERROR));

            self::assertSame(1, $counts[ClaimStatus::CLAIMED->value] ?? 0, 'expected exactly one CLAIMED; ' . $context);
            self::assertSame(
                self::WORKERS - 1,
                $counts[ClaimStatus::ALREADY_IN_PROGRESS->value] ?? 0,
                'expected all losers to observe ALREADY_IN_PROGRESS; ' . $context,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function runContendedClaims(string $key): array
    {
        $dir = sys_get_temp_dir() . '/idem-stress-' . bin2hex(random_bytes(6));
        if (!mkdir($dir)) {
            self::fail(sprintf('Cannot create temp dir "%s".', $dir));
        }

        $goFile = $dir . '/go';
        $processes = [];
        $readyFiles = [];
        $resultFiles = [];

        try {
            for ($i = 0; $i < self::WORKERS; $i++) {
                $readyFiles[$i] = sprintf('%s/ready-%d', $dir, $i);
                $resultFiles[$i] = sprintf('%s/result-%d', $dir, $i);

                $process = proc_open(
                    [
                        PHP_BINARY,
                        __DIR__ . '/worker.php',
                        $this->backend(),
                        $key,
                        'stress',
                        $readyFiles[$i],
                        $goFile,
                        $resultFiles[$i],
                    ],
                    [
                        1 => ['file', '/dev/null', 'w'],
                        2 => ['file', '/dev/null', 'w'],
                    ],
                    $pipes,
                );

                if (!is_resource($process)) {
                    self::fail(sprintf('Cannot spawn worker %d.', $i));
                }

                $processes[$i] = $process;
            }

            $this->awaitWorkersReady($readyFiles, $resultFiles);
            touch($goFile);

            foreach ($processes as $process) {
                proc_close($process);
            }
            $processes = [];

            $statuses = [];
            foreach ($resultFiles as $i => $resultFile) {
                $status = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
                $statuses[] = $status !== '' ? $status : sprintf('worker %d produced no result', $i);
            }

            return $statuses;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }

            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    /**
     * @param array<int, string> $readyFiles
     * @param array<int, string> $resultFiles
     */
    private function awaitWorkersReady(array $readyFiles, array $resultFiles): void
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;

        while (true) {
            $pending = array_filter($readyFiles, static fn (string $file): bool => !file_exists($file));
            if ($pending === []) {
                return;
            }

            if (microtime(true) > $deadline) {
                $diagnostics = [];
                foreach ($pending as $i => $file) {
                    $diagnostics[] = sprintf(
                        'worker %d: %s',
                        $i,
                        is_file($resultFiles[$i]) ? (string) file_get_contents($resultFiles[$i]) : 'no output',
                    );
                }
                self::fail('Workers did not reach the barrier: ' . implode('; ', $diagnostics));
            }

            usleep(1_000);
        }
    }
}
