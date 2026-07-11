<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Pdo;

use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PostgreSqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;

final class PostgreSqlIdempotencyStoreTest extends AbstractPdoIdempotencyStoreIntegrationTestCase
{
    protected function createPdo(): ?\PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            return null;
        }

        $dsn = getenv('PG_DB_DSN') ?: '';
        $user = getenv('PG_DB_USER') ?: '';
        $password = getenv('PG_DB_PASSWORD') ?: '';

        if ($dsn === '' || $user === '') {
            return null;
        }

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    protected function applySchema(\PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/resources/sql/idempotency_records.postgresql.sql';
        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read SQL schema file.');
        }

        $pdo->exec($sql);
    }

    protected function truncateTable(\PDO $pdo): void
    {
        $pdo->exec('TRUNCATE TABLE idempotency_records RESTART IDENTITY');
    }

    protected function createStore(\PDO $pdo): PdoIdempotencyStore
    {
        return new PdoIdempotencyStore($pdo, dialect: new PostgreSqlDialect(), clock: $this->clock);
    }

    protected function createProbeStore(\PDO $pdo): VanishOnceClaimStore
    {
        return new VanishOnceClaimStore($pdo, dialect: new PostgreSqlDialect());
    }
}
