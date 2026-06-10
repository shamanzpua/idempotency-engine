<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Exception\IllegalStateTransitionException;
use Shamanzpua\Idempotency\Exception\OwnershipViolationException;
use Shamanzpua\Idempotency\Exception\RecordNotFoundException;
use Shamanzpua\Idempotency\Infrastructure\Store\InMemory\InMemoryIdempotencyStore;

final class InMemoryIdempotencyStoreTest extends TestCase
{
    public function testCompleteThrowsOwnershipViolationForDifferentOwner(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $store->claim('k', 'default', Fingerprint::fromString('fp'), ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        $this->expectException(OwnershipViolationException::class);
        $store->complete('k', 'default', ExecutionId::generate(), '{}', $now);
    }

    public function testCompleteThrowsRecordNotFoundForMissingRecord(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->expectException(RecordNotFoundException::class);
        $store->complete('missing', 'default', ExecutionId::generate(), '{}', $now);
    }

    public function testCompleteThrowsIllegalStateTransitionForAlreadyCompleted(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fp = Fingerprint::fromString('fp');
        $store->claim('k', 'default', $fp, $owner, Ttl::fromSeconds(60), $now);
        $store->complete('k', 'default', $owner, '{}', $now);

        $this->expectException(IllegalStateTransitionException::class);
        $store->complete('k', 'default', $owner, '{}', $now);
    }

    public function testFailThrowsRecordNotFoundForMissingRecord(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->expectException(RecordNotFoundException::class);
        $store->fail('missing', 'default', ExecutionId::generate(), new ErrorDetails('E', 'msg', 0), $now);
    }

    public function testFailThrowsIllegalStateTransitionForAlreadyFailed(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $fp = Fingerprint::fromString('fp');
        $store->claim('k', 'default', $fp, $owner, Ttl::fromSeconds(60), $now);
        $store->fail('k', 'default', $owner, new ErrorDetails('E', 'msg', 0), $now);

        $this->expectException(IllegalStateTransitionException::class);
        $store->fail('k', 'default', $owner, new ErrorDetails('E', 'msg', 0), $now);
    }

    public function testCompleteWithResultTtlOverridesExpiresAt(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $store->claim('k', 'default', Fingerprint::fromString('fp'), $owner, Ttl::fromSeconds(60), $now);
        $store->complete('k', 'default', $owner, '{}', $now, Ttl::fromSeconds(3600));

        $record = $store->get('k', 'default');
        self::assertNotNull($record);
        self::assertEquals($now->modify('+3600 seconds'), $record->expiresAt);
    }

    public function testFailWithResultTtlOverridesExpiresAt(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $owner = ExecutionId::generate();
        $store->claim('k', 'default', Fingerprint::fromString('fp'), $owner, Ttl::fromSeconds(60), $now);
        $store->fail('k', 'default', $owner, new ErrorDetails('E', 'msg', 0), $now, Ttl::fromSeconds(3600));

        $record = $store->get('k', 'default');
        self::assertNotNull($record);
        self::assertEquals($now->modify('+3600 seconds'), $record->expiresAt);
    }

    public function testDeleteExpiredRemovesOnlyExpiredRecords(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $store->claim(
            key: 'expired-record',
            scope: 'default',
            fingerprint: Fingerprint::fromString('fp-expired'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(5),
            now: $now,
        );

        $store->claim(
            key: 'active-record',
            scope: 'default',
            fingerprint: Fingerprint::fromString('fp-active'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(120),
            now: $now,
        );

        $deleted = $store->deleteExpired($now->modify('+10 seconds'));

        self::assertSame(1, $deleted);
        self::assertNull($store->get('expired-record', 'default'));
        self::assertNotNull($store->get('active-record', 'default'));
    }
}
