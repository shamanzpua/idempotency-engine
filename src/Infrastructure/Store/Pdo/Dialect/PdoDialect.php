<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect;

interface PdoDialect
{
    public function formatDateTime(\DateTimeImmutable $dateTime): string;

    public function parseDateTime(string $value): \DateTimeImmutable;

    public function insertInProgressSql(string $table): string;

    /**
     * Whether the driver's affected-rows count for insertInProgressSql() reliably
     * distinguishes a fresh insert (1) from a duplicate-key no-op (0).
     *
     * MySQL must return false: with PDO::MYSQL_ATTR_FOUND_ROWS the no-op upsert
     * also reports 1 affected row, so the store verifies row ownership instead.
     */
    public function isInsertRowCountReliable(): bool;
}
