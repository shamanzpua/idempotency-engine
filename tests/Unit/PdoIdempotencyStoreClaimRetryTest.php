<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Core\Model\ClaimResult;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Exception\StoreException;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;

final class PdoIdempotencyStoreClaimRetryTest extends TestCase
{
    public function testClaimThrowsStoreExceptionWhenConflictingRowKeepsVanishing(): void
    {
        $store = new AlwaysVanishingClaimStore(new \PDO('sqlite::memory:'));

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('vanished');

        $store->claim(
            key: 'k',
            scope: 'default',
            fingerprint: Fingerprint::fromString('fp'),
            executionId: ExecutionId::generate(),
            ttl: Ttl::fromSeconds(60),
            now: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    public function testClaimRetriesUpToTheConfiguredBound(): void
    {
        $store = new AlwaysVanishingClaimStore(new \PDO('sqlite::memory:'));

        try {
            $store->claim(
                key: 'k',
                scope: 'default',
                fingerprint: Fingerprint::fromString('fp'),
                executionId: ExecutionId::generate(),
                ttl: Ttl::fromSeconds(60),
                now: new \DateTimeImmutable('2026-01-01 00:00:00'),
            );
            self::fail('Expected StoreException was not thrown.');
        } catch (StoreException) {
            // expected
        }

        self::assertSame(3, $store->attempts);
    }
}

final class AlwaysVanishingClaimStore extends PdoIdempotencyStore
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

        return null;
    }
}
