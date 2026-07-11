<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Core\Engine\DefaultIdempotencyEngine;
use Shamanzpua\Idempotency\Core\Model\ClaimResult;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\Model\NoPayload;
use Shamanzpua\Idempotency\Core\Policy\DefaultExecutionPolicy;
use Shamanzpua\Idempotency\Core\Service\ExecutionRunner;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;
use Shamanzpua\Idempotency\Exception\OwnershipViolationException;
use Shamanzpua\Idempotency\Exception\FingerprintMismatchException;
use Shamanzpua\Idempotency\Exception\OperationFailedException;
use Shamanzpua\Idempotency\Exception\OperationInProgressException;
use Shamanzpua\Idempotency\Infrastructure\Fingerprint\Sha256FingerprintGenerator;
use Shamanzpua\Idempotency\Infrastructure\Serialization\JsonResultSerializer;
use Shamanzpua\Idempotency\Infrastructure\Store\InMemory\InMemoryIdempotencyStore;
use Shamanzpua\Idempotency\Support\Clock\Clock;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

final class DefaultIdempotencyEngineTest extends TestCase
{
    public function testExecutesOperationOnceAndReplaysResult(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        $first = $engine->execute(
            'op-1',
            function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
        );

        $second = $engine->execute(
            'op-1',
            function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
        );

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testThrowsOnFingerprintMismatch(): void
    {
        $engine = $this->createEngine();

        $engine->execute(
            key: 'op-2',
            operation: static fn (): string => 'done',
            options: new ExecutionOptions(fingerprint: Fingerprint::fromString('fp-1')),
        );

        $this->expectException(FingerprintMismatchException::class);

        $engine->execute(
            key: 'op-2',
            operation: static fn (): string => 'done-again',
            options: new ExecutionOptions(fingerprint: Fingerprint::fromString('fp-2')),
        );
    }

    public function testDetectsMismatchWhenPayloadDiffersForSameKey(): void
    {
        $engine = $this->createEngine();

        $engine->execute(
            key: 'op-payload-mismatch',
            operation: static fn (): string => 'done',
            options: new ExecutionOptions(
                payload: ['amount' => 100, 'currency' => 'USD'],
            ),
        );

        $this->expectException(FingerprintMismatchException::class);

        $engine->execute(
            key: 'op-payload-mismatch',
            operation: static fn (): string => 'done-again',
            options: new ExecutionOptions(
                payload: ['amount' => 200, 'currency' => 'USD'],
            ),
        );
    }

    public function testExplicitFingerprintOverridesPayloadFingerprint(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        $first = $engine->execute(
            key: 'op-explicit-fingerprint',
            operation: function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
            options: new ExecutionOptions(
                payload: ['payload-version' => 1],
                fingerprint: Fingerprint::fromString('fp-explicit'),
            ),
        );

        $second = $engine->execute(
            key: 'op-explicit-fingerprint',
            operation: function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
            options: new ExecutionOptions(
                payload: ['payload-version' => 2],
                fingerprint: Fingerprint::fromString('fp-explicit'),
            ),
        );

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testFallsBackToKeyFingerprintWhenPayloadMissing(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        $first = $engine->execute(
            key: 'op-key-fallback',
            operation: function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
            options: new ExecutionOptions(
                payload: new NoPayload(),
            ),
        );

        $second = $engine->execute(
            key: 'op-key-fallback',
            operation: function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'seq' => $calls];
            },
            options: new ExecutionOptions(
                payload: new NoPayload(),
            ),
        );

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testThrowsWhenOperationAlreadyInProgressAndStrategyIsThrow(): void
    {
        $store = new InMemoryIdempotencyStore(new FixedClock());
        $clock = new FrozenClock();
        $fingerprintGenerator = new Sha256FingerprintGenerator();
        $serializer = new JsonResultSerializer();
        $policy = new DefaultExecutionPolicy();
        $runner = new ExecutionRunner();

        $executionId = \Shamanzpua\Idempotency\Core\ValueObject\ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-lock');
        $ttl = Ttl::fromSeconds(60);
        $now = $clock->now();

        $store->claim('op-3', 'default', $fingerprint, $executionId, $ttl, $now);

        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: $fingerprintGenerator,
            serializer: $serializer,
            policy: $policy,
            runner: $runner,
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        $this->expectException(OperationInProgressException::class);

        $engine->execute(
            key: 'op-3',
            operation: static fn (): string => 'will-not-run',
            options: new ExecutionOptions(
                fingerprint: $fingerprint,
                inProgressStrategy: InProgressStrategy::THROW,
            ),
        );
    }

    public function testWaitStrategyReturnsReplayedResultWhenOperationCompletes(): void
    {
        $clock = new TickingClock();
        $fingerprint = Fingerprint::fromString('fp-wait');
        $delegate = new InMemoryIdempotencyStore(new FixedClock());
        $owner = ExecutionId::generate();

        $delegate->claim('op-4', 'default', $fingerprint, $owner, Ttl::fromSeconds(60), $clock->now());
        $store = new EventuallyCompletedStore($delegate, $owner, $clock);

        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        $result = $engine->execute(
            key: 'op-4',
            operation: static fn (): string => 'will-not-run',
            options: new ExecutionOptions(
                fingerprint: $fingerprint,
                inProgressStrategy: InProgressStrategy::WAIT,
                waitTimeoutMs: 1_000,
                initialBackoffMs: 1,
                maxBackoffMs: 10,
                jitterRatio: 0,
            ),
        );

        self::assertSame(['ok' => true, 'source' => 'external'], $result);
    }

    public function testWaitStrategyHonorsTimeoutOptions(): void
    {
        $store = new InMemoryIdempotencyStore(new FixedClock());
        $clock = new TickingClock(stepMs: 10);
        $fingerprint = Fingerprint::fromString('fp-timeout');

        $store->claim(
            key: 'op-timeout',
            scope: 'default',
            fingerprint: $fingerprint,
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $clock->now(),
        );

        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        $this->expectException(OperationInProgressException::class);

        $engine->execute(
            key: 'op-timeout',
            operation: static fn (): string => 'will-not-run',
            options: new ExecutionOptions(
                fingerprint: $fingerprint,
                inProgressStrategy: InProgressStrategy::WAIT,
                waitTimeoutMs: 5,
                initialBackoffMs: 1,
                maxBackoffMs: 2,
                jitterRatio: 0,
            ),
        );
    }

    public function testFreshCallDoesNotReExecuteFailedOperationWhenStrategyIsThrow(): void
    {
        $engine = $this->createEngine();
        $calls = 0;
        $options = new ExecutionOptions(
            failedStrategy: FailedStrategy::THROW,
            fingerprint: Fingerprint::fromString('fp-failed-throw'),
        );

        try {
            $engine->execute(
                key: 'op-failed-throw',
                operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException('boom');
                },
                options: $options,
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(1, $calls);

        // A fresh call for the same key with THROW must surface the previous
        // failure instead of silently re-running the operation.
        try {
            $engine->execute(
                key: 'op-failed-throw',
                operation: function () use (&$calls): string {
                    $calls++;

                    return 'should-not-run';
                },
                options: $options,
            );
            self::fail('Expected OperationFailedException was not thrown.');
        } catch (OperationFailedException) {
            // expected
        }

        self::assertSame(1, $calls);
    }

    public function testWaitTakesOverWhenExpiryAwareStoreHidesDeadHolder(): void
    {
        $clock = new FrozenClock();
        $store = new ExpiredHolderThenReclaimStore($clock);
        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        $calls = 0;
        $result = $engine->execute(
            key: 'op-takeover',
            operation: function () use (&$calls): array {
                $calls++;

                return ['taken' => 'over', 'seq' => $calls];
            },
            options: new ExecutionOptions(
                fingerprint: Fingerprint::fromString('fp-takeover'),
                inProgressStrategy: InProgressStrategy::WAIT,
                waitTimeoutMs: 1_000,
                initialBackoffMs: 1,
                maxBackoffMs: 5,
                jitterRatio: 0,
            ),
        );

        // The holder's lease expired (get() returns null); the waiter takes over
        // and runs the operation instead of spinning to the timeout.
        self::assertSame(1, $calls);
        self::assertSame(['taken' => 'over', 'seq' => 1], $result);
    }

    public function testRetriesOnceAfterFailureWhenStrategyIsRetry(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        $result = $engine->execute(
            key: 'op-5',
            operation: function () use (&$calls): array {
                $calls++;

                if ($calls === 1) {
                    throw new \RuntimeException('first fail');
                }

                return ['ok' => true, 'attempt' => $calls];
            },
            options: new ExecutionOptions(
                failedStrategy: FailedStrategy::RETRY,
                fingerprint: Fingerprint::fromString('fp-retry'),
            ),
        );

        self::assertSame(2, $calls);
        self::assertSame(['ok' => true, 'attempt' => 2], $result);
    }

    public function testDoesNotRetryWhenMaxRetriesIsZero(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        try {
            $engine->execute(
                key: 'op-retry-0',
                operation: function () use (&$calls): array {
                    $calls++;

                    throw new \RuntimeException('fail-0');
                },
                options: new ExecutionOptions(
                    failedStrategy: FailedStrategy::RETRY,
                    maxRetries: 0,
                    fingerprint: Fingerprint::fromString('fp-retry-0'),
                ),
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('fail-0', $exception->getMessage());
        }

        self::assertSame(1, $calls);
    }

    public function testReclaimingPreexistingFailedRecordHonoursRetryBudget(): void
    {
        $engine = $this->createEngine();
        $fingerprint = Fingerprint::fromString('fp-reclaim-budget');

        // Leave a FAILED record behind (one attempt, no retry budget).
        try {
            $engine->execute(
                key: 'op-reclaim-budget',
                operation: static fn (): never => throw new \RuntimeException('seed failure'),
                options: new ExecutionOptions(
                    failedStrategy: FailedStrategy::RETRY,
                    maxRetries: 0,
                    fingerprint: $fingerprint,
                ),
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
            // expected — record is now FAILED
        }

        // A fresh call reclaiming that FAILED record must honour maxRetries, not
        // collapse to a single attempt.
        $calls = 0;
        $result = $engine->execute(
            key: 'op-reclaim-budget',
            operation: function () use (&$calls): array {
                $calls++;

                if ($calls <= 3) {
                    throw new \RuntimeException('retry me');
                }

                return ['ok' => true, 'attempt' => $calls];
            },
            options: new ExecutionOptions(
                failedStrategy: FailedStrategy::RETRY,
                maxRetries: 3,
                fingerprint: $fingerprint,
            ),
        );

        self::assertSame(4, $calls);
        self::assertSame(['ok' => true, 'attempt' => 4], $result);
    }

    public function testWaitRetryDoesNotReArmBudgetWhenContendingOverFailedRecord(): void
    {
        // Regression: a WAIT+RETRY caller that observes another worker's FAILED
        // record must carry its *residual* retry budget through the wait+reclaim
        // path. If handleFailedRecord re-armed a fresh maxRetries on every handoff,
        // the operation could run past maxRetries + 1 for a single execute() call.
        $clock = new FrozenClock();
        $maxRetries = 2;
        $store = new ContendedFailedRecordStore(new InMemoryIdempotencyStore($clock), interceptClaimAsInProgress: 2);
        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        $calls = 0;
        try {
            $engine->execute(
                key: 'op-contended',
                operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException('always fails');
                },
                options: new ExecutionOptions(
                    fingerprint: Fingerprint::fromString('fp-contended'),
                    inProgressStrategy: InProgressStrategy::WAIT,
                    failedStrategy: FailedStrategy::RETRY,
                    maxRetries: $maxRetries,
                    waitTimeoutMs: 1_000,
                    initialBackoffMs: 1,
                    maxBackoffMs: 5,
                    jitterRatio: 0,
                ),
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('always fails', $exception->getMessage());
        }

        // maxRetries + 1 is the hard ceiling on executions for one execute() call,
        // even across the in-progress → failed → reclaim handoff.
        self::assertLessThanOrEqual($maxRetries + 1, $calls);
        self::assertSame($maxRetries + 1, $calls);
    }

    public function testRetriesUpToConfiguredBudget(): void
    {
        $engine = $this->createEngine();
        $calls = 0;

        $result = $engine->execute(
            key: 'op-retry-3',
            operation: function () use (&$calls): array {
                $calls++;

                if ($calls <= 3) {
                    throw new \RuntimeException('retry me');
                }

                return ['ok' => true, 'attempt' => $calls];
            },
            options: new ExecutionOptions(
                failedStrategy: FailedStrategy::RETRY,
                maxRetries: 3,
                fingerprint: Fingerprint::fromString('fp-retry-3'),
            ),
        );

        self::assertSame(4, $calls);
        self::assertSame(['ok' => true, 'attempt' => 4], $result);
    }

    public function testThrowsWhenMaxRetriesIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be greater than or equal to 0.');

        new ExecutionOptions(maxRetries: -1);
    }

    public function testThrowsForEmptyKey(): void
    {
        $engine = $this->createEngine();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key cannot be empty.');

        $engine->execute('', static fn (): string => 'x');
    }

    public function testThrowsForKeyExceedingMaxLength(): void
    {
        $engine = $this->createEngine();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        $engine->execute(str_repeat('k', ExecutionOptions::MAX_KEY_LENGTH + 1), static fn (): string => 'x');
    }

    public function testThrowsForScopeExceedingMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Scope cannot exceed');

        new ExecutionOptions(scope: str_repeat('s', ExecutionOptions::MAX_KEY_LENGTH + 1));
    }

    public function testStoreThrowsOwnershipViolationOnCompleteByAnotherOwner(): void
    {
        $store = new InMemoryIdempotencyStore(new FixedClock());
        $clock = new FrozenClock();
        $fingerprint = Fingerprint::fromString('fp-own');

        $store->claim(
            key: 'op-6',
            scope: 'default',
            fingerprint: $fingerprint,
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $clock->now(),
        );

        $this->expectException(OwnershipViolationException::class);

        $store->complete(
            key: 'op-6',
            scope: 'default',
            executionId: ExecutionId::generate(),
            serializedResult: '{"ok":true}',
            now: $clock->now(),
        );
    }

    public function testMarksRecordAsFailedWhenCompletePersistenceThrows(): void
    {
        $delegate = new InMemoryIdempotencyStore(new FixedClock());
        $store = new CompleteThrowingStore($delegate);
        $clock = new FrozenClock();
        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );

        try {
            $engine->execute(
                key: 'op-complete-fail',
                operation: static fn (): array => ['ok' => true],
                options: new ExecutionOptions(
                    fingerprint: Fingerprint::fromString('fp-complete-fail'),
                ),
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('complete failed', $exception->getMessage());
        }

        $record = $delegate->get('op-complete-fail', 'default');
        self::assertNotNull($record);
        self::assertSame(\Shamanzpua\Idempotency\Enum\RecordStatus::FAILED, $record->status);
        self::assertNotNull($record->errorDetails);
    }

    public function testMarksRecordAsFailedWhenOperationThrowsStringCodeException(): void
    {
        $store = new InMemoryIdempotencyStore(new FixedClock());
        $clock = new FrozenClock();
        $engine = new DefaultIdempotencyEngine(
            store: $store,
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: $clock,
            defaultTtl: Ttl::fromSeconds(60),
        );
        $exception = new \PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $codeProperty = new \ReflectionProperty(\Exception::class, 'code');
        $codeProperty->setValue($exception, '23000');

        try {
            $engine->execute(
                key: 'op-string-code',
                operation: static fn (): never => throw $exception,
                options: new ExecutionOptions(
                    fingerprint: Fingerprint::fromString('fp-string-code'),
                ),
            );
            self::fail('Expected PDOException was not thrown.');
        } catch (\PDOException $caught) {
            self::assertSame($exception, $caught);
        }

        $record = $store->get('op-string-code', 'default');
        self::assertNotNull($record);
        self::assertSame(\Shamanzpua\Idempotency\Enum\RecordStatus::FAILED, $record->status);
        self::assertNotNull($record->errorDetails);
        self::assertSame(23000, $record->errorDetails->code);
    }

    private function createEngine(): DefaultIdempotencyEngine
    {
        return new DefaultIdempotencyEngine(
            store: new InMemoryIdempotencyStore(new FixedClock()),
            fingerprintGenerator: new Sha256FingerprintGenerator(),
            serializer: new JsonResultSerializer(),
            policy: new DefaultExecutionPolicy(),
            runner: new ExecutionRunner(),
            clock: new FrozenClock(),
            defaultTtl: Ttl::fromSeconds(60),
        );
    }
}

final class FrozenClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }
}

final class TickingClock implements Clock
{
    private \DateTimeImmutable $current;

    public function __construct(
        \DateTimeImmutable $start = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        private readonly int $stepMs = 10,
    ) {
        $this->current = $start;
    }

    public function now(): \DateTimeImmutable
    {
        $result = $this->current;
        $this->current = $this->current->modify("+{$this->stepMs} milliseconds");

        return $result;
    }
}

final class EventuallyCompletedStore implements IdempotencyStore
{
    private int $readCount = 0;

    public function __construct(
        private readonly InMemoryIdempotencyStore $delegate,
        private readonly ExecutionId $ownerExecutionId,
        private readonly Clock $clock,
    ) {}

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult {
        return $this->delegate->claim($key, $scope, $fingerprint, $executionId, $ttl, $now);
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        $this->readCount++;

        if ($this->readCount === 2) {
            $this->delegate->complete(
                key: $key,
                scope: $scope,
                executionId: $this->ownerExecutionId,
                serializedResult: '{"ok":true,"source":"external"}',
                now: $this->clock->now(),
            );
        }

        return $this->delegate->get($key, $scope);
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $this->delegate->complete($key, $scope, $executionId, $serializedResult, $now, $resultTtl);
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $this->delegate->fail($key, $scope, $executionId, $errorDetails, $now, $resultTtl);
    }
}

final class CompleteThrowingStore implements IdempotencyStore
{
    public function __construct(private readonly InMemoryIdempotencyStore $delegate) {}

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult {
        return $this->delegate->claim($key, $scope, $fingerprint, $executionId, $ttl, $now);
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        return $this->delegate->get($key, $scope);
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        throw new \RuntimeException('complete failed');
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $this->delegate->fail($key, $scope, $executionId, $errorDetails, $now, $resultTtl);
    }
}

/**
 * First claim reports the key as in progress but its lease is already expired;
 * get() hides it (as an expiry-aware store does), and the second claim (takeover)
 * succeeds.
 */
final class ExpiredHolderThenReclaimStore implements IdempotencyStore
{
    private int $claimCount = 0;

    private readonly IdempotencyRecord $expiredHolder;

    public function __construct(private readonly Clock $clock)
    {
        $now = $this->clock->now();
        $this->expiredHolder = new IdempotencyRecord(
            key: 'op-takeover',
            scope: 'default',
            fingerprint: Fingerprint::fromString('fp-takeover'),
            status: \Shamanzpua\Idempotency\Enum\RecordStatus::IN_PROGRESS,
            executionId: ExecutionId::generate(),
            serializedResult: null,
            errorDetails: null,
            createdAt: $now->modify('-2 minutes'),
            updatedAt: $now->modify('-2 minutes'),
            expiresAt: $now->modify('-1 second'),
        );
    }

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult {
        $this->claimCount++;

        if ($this->claimCount === 1) {
            return new ClaimResult(
                status: \Shamanzpua\Idempotency\Enum\ClaimStatus::ALREADY_IN_PROGRESS,
                record: $this->expiredHolder,
                executionId: null,
            );
        }

        return new ClaimResult(
            status: \Shamanzpua\Idempotency\Enum\ClaimStatus::CLAIMED,
            record: new IdempotencyRecord(
                key: $key,
                scope: $scope,
                fingerprint: $fingerprint,
                status: \Shamanzpua\Idempotency\Enum\RecordStatus::IN_PROGRESS,
                executionId: $executionId,
                serializedResult: null,
                errorDetails: null,
                createdAt: $now,
                updatedAt: $now,
                expiresAt: $now->modify(sprintf('+%d seconds', $ttl->inSeconds())),
            ),
            executionId: $executionId,
        );
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        // Expiry-aware store: the expired holder is reported as absent.
        return null;
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {}

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {}
}

/**
 * Models a concurrent WAIT+RETRY race: every operation fails, and on the given
 * claim attempt the store pretends another worker holds the lease
 * (ALREADY_IN_PROGRESS) while the delegate still exposes the earlier FAILED
 * record through get(). This drives the engine down the in-progress → failed →
 * reclaim handoff, where a re-armed retry budget would over-execute.
 */
final class ContendedFailedRecordStore implements IdempotencyStore
{
    private int $claimCount = 0;

    public function __construct(
        private readonly InMemoryIdempotencyStore $delegate,
        private readonly int $interceptClaimAsInProgress,
    ) {}

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult {
        $this->claimCount++;

        if ($this->claimCount === $this->interceptClaimAsInProgress) {
            return new ClaimResult(
                status: \Shamanzpua\Idempotency\Enum\ClaimStatus::ALREADY_IN_PROGRESS,
                record: new IdempotencyRecord(
                    key: $key,
                    scope: $scope,
                    fingerprint: $fingerprint,
                    status: \Shamanzpua\Idempotency\Enum\RecordStatus::IN_PROGRESS,
                    executionId: ExecutionId::generate(),
                    serializedResult: null,
                    errorDetails: null,
                    createdAt: $now,
                    updatedAt: $now,
                    expiresAt: $now->modify(sprintf('+%d seconds', $ttl->inSeconds())),
                ),
                executionId: null,
            );
        }

        return $this->delegate->claim($key, $scope, $fingerprint, $executionId, $ttl, $now, $reclaimFailed);
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        return $this->delegate->get($key, $scope);
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $this->delegate->complete($key, $scope, $executionId, $serializedResult, $now, $resultTtl);
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $this->delegate->fail($key, $scope, $executionId, $errorDetails, $now, $resultTtl);
    }
}
