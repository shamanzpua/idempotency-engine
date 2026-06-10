-- Deletes expired idempotency rows in small batches.
-- Intended for scheduler/cron usage.
DELETE FROM idempotency_records
WHERE expires_at <= NOW()
LIMIT 1000;
