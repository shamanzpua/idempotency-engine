<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Pdo;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Core\Model\ClaimResult;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\ClaimStatus;
use Shamanzpua\Idempotency\Enum\RecordStatus;
use Shamanzpua\Idempotency\Exception\IllegalStateTransitionException;
use Shamanzpua\Idempotency\Exception\OwnershipViolationException;
use Shamanzpua\Idempotency\Exception\RecordNotFoundException;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

abstract class AbstractPdoIdempotencyStoreIntegrationTestCase extends TestCase
{
    protected ?\PDO $pdo = null;
    protected ?PdoIdempotencyStore $store = null;
    protected FixedClock $clock;

    abstract protected function createPdo(): ?\PDO;

    abstract protected function applySchema(\PDO $pdo): void;

    abstract protected function truncateTable(\PDO $pdo): void;

    abstract protected function createStore(\PDO $pdo): PdoIdempotencyStore;

    abstract protected function createProbeStore(\PDO $pdo): VanishOnceClaimStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->pdo = $this->createPdo();
        if ($this->pdo === null) {
            self::markTestSkipped('Integration DB config is missing.');
        }

        $this->applySchema($this->pdo);
        $this->truncateTable($this->pdo);
        $this->store = $this->createStore($this->pdo);
    }

    public function testClaimCreatesAndReturnsInProgressRecord(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $claim = $this->store->claim(
            key: 'pdo-op-1',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-1'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        self::assertSame(ClaimStatus::CLAIMED, $claim->status);
        self::assertNotNull($claim->record);
        self::assertSame(RecordStatus::IN_PROGRESS, $claim->record->status);
    }

    public function testClaimReturnsAlreadyCompletedAfterComplete(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $executionId = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-2');

        $this->store->claim('pdo-op-2', 'orders', $fingerprint, $executionId, Ttl::fromSeconds(60), $now);
        $this->store->complete('pdo-op-2', 'orders', $executionId, '{"ok":true}', $now);

        $secondClaim = $this->store->claim(
            key: 'pdo-op-2',
            scope: 'orders',
            fingerprint: $fingerprint,
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        self::assertSame(ClaimStatus::ALREADY_COMPLETED, $secondClaim->status);
        self::assertNotNull($secondClaim->record);
        self::assertSame('{"ok":true}', $secondClaim->record->serializedResult);
    }

    public function testClaimDetectsFingerprintMismatch(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $this->store->claim(
            key: 'pdo-op-3',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-a'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        $mismatch = $this->store->claim(
            key: 'pdo-op-3',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-b'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        self::assertSame(ClaimStatus::FINGERPRINT_MISMATCH, $mismatch->status);
    }

    public function testCompleteThrowsForDifferentOwner(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $this->store->claim(
            key: 'pdo-op-4',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-owner'),
            executionId: $owner,
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        $this->expectException(OwnershipViolationException::class);
        $this->store->complete(
            key: 'pdo-op-4',
            scope: 'orders',
            executionId: ExecutionId::generate(),
            serializedResult: '{"ok":true}',
            now: $now,
        );
    }

    public function testCompleteThrowsRecordNotFoundForMissingKey(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->expectException(RecordNotFoundException::class);
        $this->store->complete('never-claimed', 'orders', ExecutionId::generate(), '{}', $now);
    }

    public function testCompleteThrowsIllegalStateTransitionForAlreadyCompleted(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-already-done');

        $this->store->claim('pdo-op-done', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->complete('pdo-op-done', 'orders', $owner, '{"ok":true}', $now);

        $this->expectException(IllegalStateTransitionException::class);
        $this->store->complete('pdo-op-done', 'orders', $owner, '{"ok":true}', $now);
    }

    public function testFailedRecordCanBeReclaimed(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-reclaim');

        $this->store->claim('pdo-op-5', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail(
            key: 'pdo-op-5',
            scope: 'orders',
            executionId: $owner,
            errorDetails: new ErrorDetails('RuntimeException', 'fail once', 500),
            now: $now,
        );

        $reclaim = $this->store->claim(
            key: 'pdo-op-5',
            scope: 'orders',
            fingerprint: $fingerprint,
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
            reclaimFailed: true,
        );

        self::assertSame(ClaimStatus::CLAIMED, $reclaim->status);
        self::assertNotNull($reclaim->record);
        self::assertSame(RecordStatus::IN_PROGRESS, $reclaim->record->status);
    }

    public function testClaimReturnsAlreadyFailedWithoutReclaimFlag(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-already-failed');

        $this->store->claim('pdo-op-af', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail('pdo-op-af', 'orders', $owner, new ErrorDetails('RuntimeException', 'boom', 500), $now);

        $claim = $this->store->claim(
            key: 'pdo-op-af',
            scope: 'orders',
            fingerprint: $fingerprint,
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        self::assertSame(ClaimStatus::ALREADY_FAILED, $claim->status);
        self::assertNotNull($claim->record);
        self::assertSame(RecordStatus::FAILED, $claim->record->status);
    }

    public function testSecondClaimWhileInProgressReturnsAlreadyInProgress(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $fingerprint = Fingerprint::fromString('fp-in-progress');
        $ttl = Ttl::fromSeconds(60);

        $first = $this->store->claim('pdo-op-dup', 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);
        $second = $this->store->claim('pdo-op-dup', 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);

        self::assertSame(ClaimStatus::CLAIMED, $first->status);
        self::assertSame(ClaimStatus::ALREADY_IN_PROGRESS, $second->status);
    }

    public function testTwoConnectionsClaimingSameKeyYieldExactlyOneClaimed(): void
    {
        $pdo2 = $this->createPdo();
        if ($pdo2 === null) {
            self::markTestSkipped('Cannot create second PDO connection.');
        }
        $store2 = $this->createStore($pdo2);

        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $fingerprint = Fingerprint::fromString('fp-two-conn');
        $ttl = Ttl::fromSeconds(60);
        $key = 'pdo-op-two-conn';

        $result1 = $this->store->claim($key, 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);
        $result2 = $store2->claim($key, 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);

        $claimedCount = (int) ($result1->status === ClaimStatus::CLAIMED)
            + (int) ($result2->status === ClaimStatus::CLAIMED);

        self::assertSame(1, $claimedCount);
        self::assertNotSame($result1->status, $result2->status);
    }

    public function testCompleteWithResultTtlUpdatesExpiresAt(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-result-ttl-complete');

        $this->store->claim('pdo-result-ttl-c', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->complete('pdo-result-ttl-c', 'orders', $owner, '{"ok":true}', $now, Ttl::fromSeconds(3600));

        $record = $this->store->get('pdo-result-ttl-c', 'orders');
        self::assertNotNull($record);
        self::assertEquals($now->modify('+3600 seconds'), $record->expiresAt);
    }

    public function testFailWithResultTtlUpdatesExpiresAt(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-result-ttl-fail');

        $this->store->claim('pdo-result-ttl-f', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail('pdo-result-ttl-f', 'orders', $owner, new ErrorDetails('E', 'msg', 0), $now, Ttl::fromSeconds(3600));

        $record = $this->store->get('pdo-result-ttl-f', 'orders');
        self::assertNotNull($record);
        self::assertEquals($now->modify('+3600 seconds'), $record->expiresAt);
    }

    public function testClaimPreservesInstantWhenNowIsInNonUtcTimezone(): void
    {
        // Same instant as 2026-01-01 05:00:00 UTC, expressed in a non-UTC zone.
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('America/New_York'));
        $this->store->claim(
            key: 'pdo-tz',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-tz'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(3600),
            now: $now,
        );

        $record = $this->store->get('pdo-tz', 'orders');

        self::assertNotNull($record);
        self::assertSame($now->getTimestamp(), $record->createdAt->getTimestamp());
        self::assertSame($now->modify('+3600 seconds')->getTimestamp(), $record->expiresAt->getTimestamp());
    }

    public function testClaimRetriesWhenConflictingRowVanishesDuringResolution(): void
    {
        $probe = $this->createProbeStore($this->pdo);
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $claim = $probe->claim(
            key: 'pdo-vanish',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-vanish'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        // First attempt simulated a vanished conflicting row; the claim retried
        // instead of failing, and the second attempt inserted the record.
        self::assertSame(2, $probe->attempts);
        self::assertSame(ClaimStatus::CLAIMED, $claim->status);
        self::assertNotNull($claim->record);
        self::assertSame(RecordStatus::IN_PROGRESS, $claim->record->status);
    }

    public function testPreservesMicrosecondPrecisionInTimestamps(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00.123456');
        $this->store->claim('pdo-micro', 'orders', Fingerprint::fromString('fp-micro'), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        $record = $this->store->get('pdo-micro', 'orders');

        self::assertNotNull($record);
        self::assertSame('123456', $record->createdAt->format('u'));
        self::assertSame('123456', $record->expiresAt->format('u'));
    }

    public function testStoresVersionedCanonicalFingerprint(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64)); // 67 chars

        $this->store->claim('pdo-fp', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        $record = $this->store->get('pdo-fp', 'orders');
        self::assertNotNull($record);
        self::assertTrue($record->fingerprint->equals($fingerprint));
    }

    public function testKeysAreCaseSensitive(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $upper = $this->store->claim('CASE-KEY', 'orders', Fingerprint::fromString('fp-u'), ExecutionId::generate(), Ttl::fromSeconds(60), $now);
        $lower = $this->store->claim('case-key', 'orders', Fingerprint::fromString('fp-l'), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        // Distinct keys differing only by case must both be claimable.
        self::assertSame(ClaimStatus::CLAIMED, $upper->status);
        self::assertSame(ClaimStatus::CLAIMED, $lower->status);
    }

    public function testThrowsWhenTableNameIsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PdoIdempotencyStore($this->pdo, table: 'idempotency_records;DROP_TABLE');
    }

    public function testDeleteExpiredRemovesOnlyExpiredRows(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->store->claim(
            key: 'pdo-expired',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-expired-row'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(5),
            now: $now,
        );

        $this->store->claim(
            key: 'pdo-active',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-active-row'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(120),
            now: $now,
        );

        $deleted = $this->store->deleteExpired($now->modify('+10 seconds'));

        self::assertSame(1, $deleted);
        self::assertNull($this->store->get('pdo-expired', 'orders'));
        self::assertNotNull($this->store->get('pdo-active', 'orders'));
    }

    public function testGetReturnsNullForExpiredRecord(): void
    {
        $now = $this->clock->now();
        $this->store->claim(
            key: 'pdo-get-expired',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-get-expired'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(30),
            now: $now,
        );

        self::assertNotNull($this->store->get('pdo-get-expired', 'orders'));

        $this->clock->set($now->modify('+31 seconds'));

        self::assertNull($this->store->get('pdo-get-expired', 'orders'));
    }
}

/**
 * Simulates the conflicting row vanishing between the upsert and the locking read
 * on the first claim attempt, then delegates to the real logic.
 */
final class VanishOnceClaimStore extends PdoIdempotencyStore
{
    public int $attempts = 0;

    protected function attemptClaim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ?ClaimResult {
        $this->attempts++;

        if ($this->attempts === 1) {
            return null;
        }

        return parent::attemptClaim($key, $scope, $fingerprint, $executionId, $ttl, $now, $reclaimFailed);
    }
}
