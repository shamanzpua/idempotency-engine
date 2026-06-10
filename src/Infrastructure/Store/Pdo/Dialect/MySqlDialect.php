<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect;

final class MySqlDialect implements PdoDialect
{
    public function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
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
             ON DUPLICATE KEY UPDATE scope = scope',
            $table,
        );
    }

    public function isInsertRowCountReliable(): bool
    {
        return false;
    }
}
