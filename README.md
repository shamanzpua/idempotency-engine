# Idempotency Engine

Framework-agnostic PHP idempotency engine with replay support, fingerprint checks, and store adapters.

## Storage backend support

| Backend | Status |
|---|---|
| MySQL >= 8.0 | Supported |
| MariaDB >= 10.5 | Supported |
| PostgreSQL >= 13 | Supported |
| Redis >= 6.0 | Supported |
| SQLite | Not supported |

## Fingerprint and mismatch protection

Fingerprint priority order:

1. `ExecutionOptions::$fingerprint` (explicit override)
2. Fingerprint generated from `ExecutionOptions::$payload`
3. Legacy fallback: fingerprint generated from operation key

> Warning: if you do not pass `payload` or an explicit `fingerprint`, the engine
> derives fingerprint from key only. In this mode, payload-mismatch protection is
> effectively disabled for requests sharing the same key.

## In-progress outcomes

When `ExecutionOptions::$inProgressStrategy` is set to `WAIT`, the engine may throw:

- `OperationInProgressException` when another execution is currently active.
- `OperationStalledException` when the in-progress record is considered stale by policy.

## Expired records cleanup

Stores that support operational cleanup implement `ExpirableStore` with:

- `deleteExpired(\DateTimeImmutable $before): int`

`PdoIdempotencyStore` and `InMemoryIdempotencyStore` support this capability.
For MySQL scheduler usage, see `resources/sql/cleanup_expired.mysql.sql`.

`RedisIdempotencyStore` intentionally returns `0` from `deleteExpired()` because key expiry
is delegated to native Redis TTL.

## Redis store

`RedisIdempotencyStore` accepts a `RedisClient` abstraction. Two adapters are provided:

- `PhpRedisClient` (requires `ext-redis`)
- `PredisClient` (requires `predis/predis`)

Redis-specific caveats:

- Lua scripts are atomic per command, but multi-key transactional semantics are not provided.
- Persistence depends on Redis server configuration (`appendonly`/`save`).
- For Redis Cluster deployments, use key tags to keep related keys in one slot.

## Known limitations

- `InMemoryIdempotencyStore` is not thread-safe and should only be used for tests or simple single-process environments.
- In sync runtimes, `WAIT` strategy uses `usleep()` and blocks the current process while polling.
- Without payload or explicit fingerprint, fallback fingerprint-from-key mode does not detect payload mismatches.
- SQLite is not supported because the current locking approach depends on `SELECT ... FOR UPDATE`.
