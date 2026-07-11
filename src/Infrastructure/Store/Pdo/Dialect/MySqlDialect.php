<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect;

final class MySqlDialect implements PdoDialect
{
    public function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        // Persist a canonical UTC wall-clock value with microseconds: DATETIME(6)
        // carries no timezone, so normalizing here keeps expires_at comparisons
        // correct across timezones, and sub-second precision matches PostgreSQL.
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
             ON DUPLICATE KEY UPDATE scope = scope',
            $table,
        );
    }

    public function isInsertRowCountReliable(): bool
    {
        return false;
    }
}
