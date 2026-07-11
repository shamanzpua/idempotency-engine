<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

final class MySqlStoreContractTest extends AbstractStoreContractTestCase
{
    protected function createStore(FixedClock $clock): ?IdempotencyStore
    {
        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';

        if ($dsn === '' || $user === '') {
            return null;
        }

        $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(dirname(__DIR__, 3) . '/resources/sql/idempotency_records.mysql.sql');
        if ($schema === false) {
            throw new \RuntimeException('Cannot read MySQL schema.');
        }
        $pdo->exec($schema);
        $pdo->exec('TRUNCATE TABLE idempotency_records');

        return new PdoIdempotencyStore($pdo, dialect: new MySqlDialect(), clock: $clock);
    }
}
