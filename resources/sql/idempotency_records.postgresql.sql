-- All *_at columns store UTC wall-clock values (the store normalizes to UTC).
-- Keep application workers and cleanup jobs on UTC when querying this table.
CREATE TABLE IF NOT EXISTS idempotency_records (
    id BIGSERIAL PRIMARY KEY,
    scope VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    fingerprint VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    execution_id CHAR(32) DEFAULT NULL,
    result_payload TEXT DEFAULT NULL,
    error_json TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE (scope, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_idempotency_expires_at ON idempotency_records (expires_at);
CREATE INDEX IF NOT EXISTS idx_idempotency_status ON idempotency_records (status);
