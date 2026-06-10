<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Concurrency;

final class MySqlClaimConcurrencyTest extends AbstractClaimConcurrencyTestCase
{
    protected function backend(): string
    {
        return 'mysql';
    }

    protected function isConfigured(): bool
    {
        return (getenv('DB_DSN') ?: '') !== '' && (getenv('DB_USER') ?: '') !== '';
    }

    protected function prepareStorage(): void
    {
        $pdo = new \PDO(
            (string) getenv('DB_DSN'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $schema = file_get_contents(dirname(__DIR__, 3) . '/resources/sql/idempotency_records.mysql.sql');
        if ($schema === false) {
            throw new \RuntimeException('Cannot read SQL schema file.');
        }

        $pdo->exec($schema);
        $pdo->exec('TRUNCATE TABLE idempotency_records');
    }
}
