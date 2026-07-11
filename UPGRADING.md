# Upgrading

## From `1.0.0-rc1` to `1.0.0`

This release hardens correctness and locks the cross-store contract before the stable
tag. It contains schema changes and a few behaviour changes. Read this before deploying.

### 1. Database schema

Apply to existing tables (new installs just use the updated files in `resources/sql/`).

**MySQL / MariaDB**

```sql
ALTER TABLE idempotency_records
    MODIFY fingerprint VARCHAR(80) NOT NULL,
    MODIFY created_at DATETIME(6) NOT NULL,
    MODIFY updated_at DATETIME(6) NOT NULL,
    MODIFY expires_at DATETIME(6) NOT NULL,
    MODIFY scope           VARBINARY(191) NOT NULL,
    MODIFY idempotency_key VARBINARY(191) NOT NULL;
```

- `fingerprint` widens to hold the version-prefixed value (`v1:` + 64 hex).
- `*_at` become `DATETIME(6)` for microsecond precision, matching PostgreSQL.
- `scope` / `idempotency_key` switch to `VARBINARY` so keys are **case-sensitive**,
  byte-exact and NOT PAD (trailing spaces are significant) on both MySQL and MariaDB.
  Previously `utf8mb4_unicode_ci` treated `ABC`/`abc` (and trailing-space variants) as one
  key; if you relied on that, reconcile duplicates before the migration.

**PostgreSQL**

```sql
ALTER TABLE idempotency_records
    ALTER COLUMN fingerprint TYPE VARCHAR(80);
```

### 2. Timestamps are UTC

The PDO stores now write and read all timestamps as UTC wall-clock values. Existing rows
written by rc1 were stored in the process timezone. If every writer already ran in UTC (the
documented recommendation), nothing changes. Otherwise, either let old records expire before
relying on the new comparisons, or normalise existing `*_at` columns to UTC.

### 3. Fingerprint format (opt-in)

`CanonicalJsonFingerprintGenerator` is now recommended and produces version-prefixed,
order-independent fingerprints (`v1:<sha256>`). Switching from `Sha256FingerprintGenerator`
changes the fingerprint of every payload, so **in-flight rc1 records will read as a payload
mismatch**. Adopt it during a quiet window or after existing records have expired.
`Sha256FingerprintGenerator` remains available if you need the old raw behaviour.

### 4. Behaviour changes

- **`get()` is expiry-aware.** Every store now returns `null` for a logically expired record
  instead of a stale one; a stale COMPLETED result is never replayed.
- **`FailedStrategy::THROW` no longer re-runs a failed operation.** A fresh `execute()` for a
  key whose previous attempt FAILED now raises `OperationFailedException` (previously it
  silently re-executed). Use `FailedStrategy::RETRY` to keep the reclaim-and-retry behaviour.
- **PDO connection must be in exception mode.** `PdoIdempotencyStore` no longer sets
  `PDO::ATTR_ERRMODE`; pass a connection already configured with `PDO::ERRMODE_EXCEPTION` or
  the constructor throws `InvalidArgumentException`.
- **phpredis options are validated.** A `\Redis` client with `OPT_SERIALIZER` or `OPT_PREFIX`
  set is rejected; use a dedicated, unconfigured connection.
- **Redis key format changed** to a length-prefixed encoding (`prefix:<len>:scope:<len>:key`).
  rc1 keys will not be found under the new format; let them expire via TTL or flush the
  namespace during deployment.
- **Redis timestamp format changed.** Records now store timestamps as fixed-width UTC with
  microseconds (`Y-m-d\TH:i:s.u\Z`) instead of rc1's second-resolution, process-timezone
  ISO-8601. Combined with the key-format change above, flushing the idempotency namespace on
  deploy is the clean path; a value written by rc1 would otherwise be compared against the new
  format. New installs are unaffected.
- **Key/scope validation.** `execute()` now rejects an empty key, and both the key and scope
  are capped at 191 bytes (`ExecutionOptions::MAX_KEY_LENGTH`) with `InvalidArgumentException`.
  rc1 did no length validation; PostgreSQL columns allowed up to 255 bytes. If you used keys or
  scopes of 192–255 bytes on PostgreSQL, shorten them (or hash long inputs before passing them).

### 5. Custom implementations

- `IdempotencyStore::claim()` gained an optional `bool $reclaimFailed = false` parameter.
  Custom stores must add it (FAILED records must be left untouched unless it is `true`); an
  implementer with the old 6-parameter signature fatals at class-load.
- `ClaimStatus` has a new case, `ALREADY_FAILED`. If you consume `ClaimStatus` in an exhaustive
  `match` (e.g. a custom engine driving a store directly), add an arm or a `default` to avoid
  `\UnhandledMatchError`.
- The store constructors accept an optional `Clock` (defaulting to `SystemClock`); custom
  wiring may inject a shared clock for testable expiry.
- **`OperationStalledException` and `ExecutionPolicy::isStale()` were removed.** A crashed
  holder's lease is now recovered automatically once it expires (a `WAIT` caller takes the
  claim over and re-executes), so the dedicated stalled signal no longer exists. Delete any
  `catch (OperationStalledException)` and any `isStale()` method from a custom `ExecutionPolicy`
  (leaving the method is harmless, but the interface no longer declares it).

### 6. Guarantee wording

The library no longer claims "exactly once". It provides single-flight execution, duplicate
suppression and result replay. For critical, non-idempotent side effects, combine it with a
provider-side idempotency key, a unique database constraint and/or a transactional outbox.
