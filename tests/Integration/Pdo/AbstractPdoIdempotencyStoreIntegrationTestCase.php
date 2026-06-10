<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Pdo;

use PHPUnit\Framework\TestCase;
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

abstract class AbstractPdoIdempotencyStoreIntegrationTestCase extends TestCase
{
    protected ?\PDO $pdo = null;
    protected ?PdoIdempotencyStore $store = null;

    abstract protected function createPdo(): ?\PDO;

    abstract protected function applySchema(\PDO $pdo): void;

    abstract protected function truncateTable(\PDO $pdo): void;

    abstract protected function createStore(\PDO $pdo): PdoIdempotencyStore;

    protected function setUp(): void
    {
        parent::setUp();

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
        );

        self::assertSame(ClaimStatus::CLAIMED, $reclaim->status);
        self::assertNotNull($reclaim->record);
        self::assertSame(RecordStatus::IN_PROGRESS, $reclaim->record->status);
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
}
