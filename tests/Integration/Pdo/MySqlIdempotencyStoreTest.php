<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Pdo;

use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\ClaimStatus;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;

final class MySqlIdempotencyStoreTest extends AbstractPdoIdempotencyStoreIntegrationTestCase
{
    protected function createPdo(): ?\PDO
    {
        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';

        if ($dsn === '' || $user === '') {
            return null;
        }

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    public function testDuplicateClaimIsNotClaimedWhenFoundRowsAttributeEnabled(): void
    {
        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ]);
        $store = new PdoIdempotencyStore($pdo, dialect: new MySqlDialect());
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $fingerprint = Fingerprint::fromString('fp-found-rows');
        $owner = ExecutionId::generate();

        $first = $store->claim('pdo-found-rows', 'orders', $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $second = $store->claim('pdo-found-rows', 'orders', $fingerprint, ExecutionId::generate(), Ttl::fromSeconds(60), $now);

        self::assertSame(ClaimStatus::CLAIMED, $first->status);
        self::assertNotNull($first->record);
        self::assertNotNull($first->record->executionId);
        self::assertTrue($first->record->executionId->equals($owner));
        self::assertSame(ClaimStatus::ALREADY_IN_PROGRESS, $second->status);
    }

    protected function applySchema(\PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/resources/sql/idempotency_records.mysql.sql';
        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read SQL schema file.');
        }

        $pdo->exec($sql);
    }

    protected function truncateTable(\PDO $pdo): void
    {
        $pdo->exec('TRUNCATE TABLE idempotency_records');
    }

    protected function createStore(\PDO $pdo): PdoIdempotencyStore
    {
        return new PdoIdempotencyStore($pdo, dialect: new MySqlDialect(), clock: $this->clock);
    }

    protected function createProbeStore(\PDO $pdo): VanishOnceClaimStore
    {
        return new VanishOnceClaimStore($pdo, dialect: new MySqlDialect());
    }
}
