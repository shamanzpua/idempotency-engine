<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\ClaimStatus;
use Shamanzpua\Idempotency\Enum\RecordStatus;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

/**
 * One behavioural contract exercised against every store implementation, so the
 * stores stay observably identical for the invariants that used to differ.
 */
abstract class AbstractStoreContractTestCase extends TestCase
{
    protected FixedClock $clock;
    protected ?IdempotencyStore $store = null;

    /**
     * Returns a clean store bound to $clock, or null to skip (missing service).
     */
    abstract protected function createStore(FixedClock $clock): ?IdempotencyStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock(new \DateTimeImmutable('2026-01-01 00:00:00+00:00'));
        $this->store = $this->createStore($this->clock);

        if ($this->store === null) {
            self::markTestSkipped('Store backend is not available.');
        }
    }

    public function testClaimThenReplayCompletedResult(): void
    {
        $now = $this->clock->now();
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64));

        $first = $this->store->claim('op', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        self::assertSame(ClaimStatus::CLAIMED, $first->status);

        $this->store->complete('op', 'orders', $owner, '{"ok":true}', $now);

        $second = $this->store->claim('op', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(60), $now);
        self::assertSame(ClaimStatus::ALREADY_COMPLETED, $second->status);
        self::assertNotNull($second->record);
        self::assertSame('{"ok":true}', $second->record->serializedResult);
    }

    public function testFingerprintMismatchIsDetected(): void
    {
        $now = $this->clock->now();
        $this->store->claim('op', 'orders', Fingerprint::fromString('v1:' . str_repeat('a', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        $mismatch = $this->store->claim('op', 'orders', Fingerprint::fromString('v1:' . str_repeat('b', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        self::assertSame(ClaimStatus::FINGERPRINT_MISMATCH, $mismatch->status);
    }

    public function testGetReturnsNullForExpiredRecord(): void
    {
        $now = $this->clock->now();
        $this->store->claim('op-exp', 'orders', Fingerprint::fromString('v1:' . str_repeat('a', 64)), ExecutionId::generate(), Ttl::fromSeconds(30), $now);

        self::assertNotNull($this->store->get('op-exp', 'orders'));

        $this->clock->set($now->modify('+31 seconds'));

        self::assertNull($this->store->get('op-exp', 'orders'));
    }

    public function testFailedRecordIsAlreadyFailedButReclaimableWithFlag(): void
    {
        $now = $this->clock->now();
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64));

        $this->store->claim('op-fail', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail('op-fail', 'orders', $owner, new ErrorDetails('RuntimeException', 'boom', 500), $now);

        $withoutFlag = $this->store->claim('op-fail', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(60), $now);
        self::assertSame(ClaimStatus::ALREADY_FAILED, $withoutFlag->status);

        $withFlag = $this->store->claim('op-fail', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(60), $now, reclaimFailed: true);
        self::assertSame(ClaimStatus::CLAIMED, $withFlag->status);
        self::assertNotNull($withFlag->record);
        self::assertSame(RecordStatus::IN_PROGRESS, $withFlag->record->status);
    }

    public function testDistinctScopeKeyPairsDoNotCollide(): void
    {
        $now = $this->clock->now();

        $first = $this->store->claim('c', 'a:b', Fingerprint::fromString('v1:' . str_repeat('1', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);
        $second = $this->store->claim('b:c', 'a', Fingerprint::fromString('v1:' . str_repeat('2', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        self::assertSame(ClaimStatus::CLAIMED, $first->status);
        self::assertSame(ClaimStatus::CLAIMED, $second->status);
    }

    public function testTrailingWhitespaceKeysAreDistinct(): void
    {
        $now = $this->clock->now();

        $a = $this->store->claim('order-1', 'orders', Fingerprint::fromString('v1:' . str_repeat('1', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);
        $b = $this->store->claim('order-1 ', 'orders', Fingerprint::fromString('v1:' . str_repeat('2', 64)), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        // A trailing space must make a distinct key on every backend (MySQL uses
        // VARBINARY to avoid PAD SPACE collapsing them).
        self::assertSame(ClaimStatus::CLAIMED, $a->status);
        self::assertSame(ClaimStatus::CLAIMED, $b->status);
    }

    public function testClaimHonoursLiveLeaseRegardlessOfCallerTimezone(): void
    {
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64));

        // Worker A (UTC) takes a lease valid until 2026-01-01 11:00:00Z.
        $nowUtc = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $first = $this->store->claim('op-tz', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(3600), $nowUtc);
        self::assertSame(ClaimStatus::CLAIMED, $first->status);

        // Worker B in Asia/Tokyo calls 30 real minutes later: 10:30:00Z == 19:30:00+09:00,
        // still well inside the lease. A store that compares wall-clock strings lexically
        // instead of instants reads the "+09:00" offset as later than "11:00:00+00:00" and
        // steals the live lease, double-executing the operation. Every backend must instead
        // recognise the lease as active and report it in progress.
        $nowTokyo = new \DateTimeImmutable('2026-01-01 19:30:00', new \DateTimeZone('Asia/Tokyo'));
        $second = $this->store->claim('op-tz', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(3600), $nowTokyo);

        self::assertSame(ClaimStatus::ALREADY_IN_PROGRESS, $second->status);
    }

    public function testClaimPreservesSubSecondLeasePrecision(): void
    {
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64));

        // Lease taken at .900 with a 1s TTL is alive until 00:00:01.900.
        $start = new \DateTimeImmutable('2026-01-01 00:00:00.900000+00:00');
        $first = $this->store->claim('op-subsecond', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(1), $start);
        self::assertSame(ClaimStatus::CLAIMED, $first->status);

        // A claim 200ms later is still inside the lease. A store that truncates the
        // expiry to whole seconds (00:00:01) would treat it as expired and reclaim,
        // double-executing; every store must keep sub-second precision and report it
        // in progress.
        $within = new \DateTimeImmutable('2026-01-01 00:00:01.100000+00:00');
        $second = $this->store->claim('op-subsecond', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(1), $within);

        self::assertSame(ClaimStatus::ALREADY_IN_PROGRESS, $second->status);
    }

    public function testExpiredRecordIsReclaimedOnNextClaim(): void
    {
        $now = $this->clock->now();
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('v1:' . str_repeat('a', 64));

        $this->store->claim('op-reclaim-exp', 'orders', $fingerprint, $owner, Ttl::fromSeconds(30), $now);
        $this->store->complete('op-reclaim-exp', 'orders', $owner, '{"ok":true}', $now);

        // A claim after the record has logically expired must reclaim it (the hot
        // path the engine uses to decide replay), uniformly across stores.
        $later = $now->modify('+31 seconds');
        $reclaim = $this->store->claim('op-reclaim-exp', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(30), $later);

        self::assertSame(ClaimStatus::CLAIMED, $reclaim->status);
    }
}
