<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Concurrency;

final class PostgreSqlClaimConcurrencyTest extends AbstractClaimConcurrencyTestCase
{
    protected function backend(): string
    {
        return 'postgres';
    }

    protected function isConfigured(): bool
    {
        return (getenv('PG_DB_DSN') ?: '') !== '' && (getenv('PG_DB_USER') ?: '') !== '';
    }

    protected function prepareStorage(): void
    {
        $pdo = new \PDO(
            (string) getenv('PG_DB_DSN'),
            (string) getenv('PG_DB_USER'),
            (string) getenv('PG_DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $schema = file_get_contents(dirname(__DIR__, 3) . '/resources/sql/idempotency_records.postgresql.sql');
        if ($schema === false) {
            throw new \RuntimeException('Cannot read SQL schema file.');
        }

        $pdo->exec($schema);
        $pdo->exec('TRUNCATE TABLE idempotency_records');
    }
}
