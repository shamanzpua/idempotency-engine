<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect;

final class PostgreSqlDialect implements PdoDialect
{
    public function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        // Persist a canonical UTC wall-clock value (with microseconds). The schema
        // column is TIMESTAMP (without time zone), so writing an offset would be
        // silently dropped by PostgreSQL; normalizing to UTC keeps expires_at
        // comparisons correct across workers in different timezones.
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public function parseDateTime(string $value): \DateTimeImmutable
    {
        // Stored values are UTC wall-clock (see formatDateTime); interpret them as
        // UTC instead of relying on the process default timezone.
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
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
