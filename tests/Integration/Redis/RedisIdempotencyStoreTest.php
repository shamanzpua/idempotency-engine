<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Redis;

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
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\PredisClient;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\RedisIdempotencyStore;

final class RedisIdempotencyStoreTest extends TestCase
{
    private ?\Predis\Client $client = null;
    private ?RedisIdempotencyStore $store = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Predis\Client::class)) {
            self::markTestSkipped('predis/predis is not installed.');
        }

        $dsn = getenv('REDIS_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('REDIS_DSN is missing.');
        }

        $this->client = new \Predis\Client($dsn);
        $this->client->flushdb();
        $this->store = new RedisIdempotencyStore(new PredisClient($this->client));
    }

    public function testClaimCreatesAndReturnsInProgressRecord(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $claim = $this->store->claim(
            key: 'redis-op-1',
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
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $executionId = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-2');

        $this->store->claim('redis-op-2', 'orders', $fingerprint, $executionId, Ttl::fromSeconds(60), $now);
        $this->store->complete('redis-op-2', 'orders', $executionId, '{"ok":true}', $now);

        $secondClaim = $this->store->claim(
            key: 'redis-op-2',
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
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $this->store->claim(
            key: 'redis-op-3',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-a'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        $mismatch = $this->store->claim(
            key: 'redis-op-3',
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
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $this->store->claim(
            key: 'redis-op-4',
            scope: 'orders',
            fingerprint: Fingerprint::fromString('fp-owner'),
            executionId: $owner,
            ttl: Ttl::fromSeconds(60),
            now: $now,
        );

        $this->expectException(OwnershipViolationException::class);
        $this->store->complete(
            key: 'redis-op-4',
            scope: 'orders',
            executionId: ExecutionId::generate(),
            serializedResult: '{"ok":true}',
            now: $now,
        );
    }

    public function testCompleteThrowsRecordNotFoundForMissingKey(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->expectException(RecordNotFoundException::class);
        $this->store->complete('never-claimed', 'orders', ExecutionId::generate(), '{}', $now);
    }

    public function testCompleteThrowsIllegalStateTransitionForAlreadyCompleted(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-already-done');

        $this->store->claim('redis-op-done', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->complete('redis-op-done', 'orders', $owner, '{"ok":true}', $now);

        $this->expectException(IllegalStateTransitionException::class);
        $this->store->complete('redis-op-done', 'orders', $owner, '{"ok":true}', $now);
    }

    public function testFailedRecordCanBeReclaimed(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-reclaim');

        $this->store->claim('redis-op-5', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail(
            key: 'redis-op-5',
            scope: 'orders',
            executionId: $owner,
            errorDetails: new ErrorDetails('RuntimeException', 'fail once', 500),
            now: $now,
        );

        $reclaim = $this->store->claim(
            key: 'redis-op-5',
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

    public function testSecondClaimWhileInProgressReturnsAlreadyInProgressWithRecord(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $fingerprint = Fingerprint::fromString('fp-in-progress');
        $ttl = Ttl::fromSeconds(60);

        $first = $this->store->claim('redis-op-dup', 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);
        $second = $this->store->claim('redis-op-dup', 'orders', $fingerprint, ExecutionId::generate(), $ttl, $now);

        self::assertSame(ClaimStatus::CLAIMED, $first->status);
        self::assertNotNull($first->record);
        self::assertSame(ClaimStatus::ALREADY_IN_PROGRESS, $second->status);
        self::assertNotNull($second->record);
    }

    public function testCompleteWithResultTtlUpdatesExpiresAtAndRedisTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-result-ttl-complete');

        $this->store->claim('redis-result-ttl-c', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->complete('redis-result-ttl-c', 'orders', $owner, '{"ok":true}', $now, Ttl::fromSeconds(3600));

        $record = $this->store->get('redis-result-ttl-c', 'orders');
        self::assertNotNull($record);
        self::assertEquals(
            $now->modify('+3600 seconds')->format(\DateTimeInterface::ATOM),
            $record->expiresAt->format(\DateTimeInterface::ATOM),
        );
        $redisTtl = $this->client->ttl('idempotency:orders:redis-result-ttl-c');
        self::assertGreaterThan(3590, $redisTtl);
        self::assertLessThanOrEqual(3600, $redisTtl);
    }

    public function testFailWithResultTtlUpdatesExpiresAtAndRedisTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $fingerprint = Fingerprint::fromString('fp-result-ttl-fail');

        $this->store->claim('redis-result-ttl-f', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $this->store->fail('redis-result-ttl-f', 'orders', $owner, new ErrorDetails('E', 'msg', 0), $now, Ttl::fromSeconds(3600));

        $record = $this->store->get('redis-result-ttl-f', 'orders');
        self::assertNotNull($record);
        self::assertEquals(
            $now->modify('+3600 seconds')->format(\DateTimeInterface::ATOM),
            $record->expiresAt->format(\DateTimeInterface::ATOM),
        );
        $redisTtl = $this->client->ttl('idempotency:orders:redis-result-ttl-f');
        self::assertGreaterThan(3590, $redisTtl);
        self::assertLessThanOrEqual(3600, $redisTtl);
    }

    public function testCompleteWithoutResultTtlPreservesClaimTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();

        $this->store->claim('redis-keep-ttl', 'orders', Fingerprint::fromString('fp-keep-ttl'), $owner, Ttl::fromSeconds(60), $now);
        $this->store->complete('redis-keep-ttl', 'orders', $owner, '{"ok":true}', $now);

        $redisTtl = $this->client->ttl('idempotency:orders:redis-keep-ttl');
        self::assertGreaterThan(55, $redisTtl);
        self::assertLessThanOrEqual(60, $redisTtl);
    }

    public function testCompleteDoesNotMakeKeyImmortalWhenSubSecondTtlRemains(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $redisKey = 'idempotency:orders:redis-ttl-edge';

        $this->store->claim('redis-ttl-edge', 'orders', Fingerprint::fromString('fp-ttl-edge'), $owner, Ttl::fromSeconds(60), $now);
        $this->client->pexpire($redisKey, 400);
        $this->store->complete('redis-ttl-edge', 'orders', $owner, '{"ok":true}', $now);

        $remainingMs = $this->client->pttl($redisKey);
        self::assertGreaterThan(0, $remainingMs);
        self::assertLessThanOrEqual(400, $remainingMs);
    }

    public function testFailDoesNotMakeKeyImmortalWhenSubSecondTtlRemains(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $owner = ExecutionId::generate();
        $redisKey = 'idempotency:orders:redis-ttl-edge-fail';

        $this->store->claim('redis-ttl-edge-fail', 'orders', Fingerprint::fromString('fp-ttl-edge-fail'), $owner, Ttl::fromSeconds(60), $now);
        $this->client->pexpire($redisKey, 400);
        $this->store->fail('redis-ttl-edge-fail', 'orders', $owner, new ErrorDetails('E', 'msg', 0), $now);

        $remainingMs = $this->client->pttl($redisKey);
        self::assertGreaterThan(0, $remainingMs);
        self::assertLessThanOrEqual(400, $remainingMs);
    }

    public function testDeleteExpiredIsNoOpForRedis(): void
    {
        $deleted = $this->store->deleteExpired(new \DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        self::assertSame(0, $deleted);
    }
}
