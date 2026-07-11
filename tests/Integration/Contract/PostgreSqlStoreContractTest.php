<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PostgreSqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

final class PostgreSqlStoreContractTest extends AbstractStoreContractTestCase
{
    protected function createStore(FixedClock $clock): ?IdempotencyStore
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

        $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(dirname(__DIR__, 3) . '/resources/sql/idempotency_records.postgresql.sql');
        if ($schema === false) {
            throw new \RuntimeException('Cannot read PostgreSQL schema.');
        }
        $pdo->exec($schema);
        $pdo->exec('TRUNCATE TABLE idempotency_records RESTART IDENTITY');

        return new PdoIdempotencyStore($pdo, dialect: new PostgreSqlDialect(), clock: $clock);
    }
}
