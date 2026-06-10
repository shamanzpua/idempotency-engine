<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect;

final class PostgreSqlDialect implements PdoDialect
{
    public function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format(\DateTimeInterface::ATOM);
    }

    public function parseDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    public function insertInProgressSql(string $table): string
    {
        return sprintf(
            'INSERT INTO %s
             (scope, idempotency_key, fingerprint, status, execution_id, result_payload, error_json, created_at, updated_at, expires_at)
             VALUES
             (:scope, :key, :fingerprint, :status, :execution_id, NULL, NULL, :created_at, :updated_at, :expires_at)
             ON CONFLICT (scope, idempotency_key) DO NOTHING',
            $table,
        );
    }

    public function isInsertRowCountReliable(): bool
    {
        return true;
    }
}
