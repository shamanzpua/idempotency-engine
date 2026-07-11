-- All *_at columns store UTC wall-clock values with microsecond precision
-- (DATETIME(6)); the store normalizes to UTC. Keep application workers and
-- cleanup jobs on UTC when querying this table.
-- scope and idempotency_key are VARBINARY so they are byte-exact, case-sensitive
-- and NOT PAD (trailing spaces are significant), matching the Redis and in-memory
-- stores on both MySQL 8.0+ and MariaDB 10.5+ (utf8mb4_bin is PAD SPACE, and the
-- NO PAD utf8mb4_0900_bin collation is MySQL-only).
CREATE TABLE IF NOT EXISTS idempotency_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope VARBINARY(191) NOT NULL,
    idempotency_key VARBINARY(191) NOT NULL,
    fingerprint VARCHAR(80) NOT NULL,
    status VARCHAR(32) NOT NULL,
    execution_id CHAR(32) DEFAULT NULL,
    result_payload LONGTEXT DEFAULT NULL,
    error_json LONGTEXT DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_scope_key (scope, idempotency_key),
    KEY idx_expires_at (expires_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
