CREATE TABLE IF NOT EXISTS idempotency_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(191) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    execution_id CHAR(32) DEFAULT NULL,
    result_payload LONGTEXT DEFAULT NULL,
    error_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_scope_key (scope, idempotency_key),
    KEY idx_expires_at (expires_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
