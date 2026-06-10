# Idempotency Engine

Framework-agnostic PHP library for executing an operation **exactly once per idempotency key**
and replaying its stored result on retries. Built for payment operations, webhook handlers,
API idempotency keys and at-least-once queue consumers.

- **Atomic claim** — one writer wins per `(scope, key)`; no check-then-act races
  (single upsert + row lock in SQL, single Lua script in Redis).
- **Ownership tokens** — only the execution that claimed a key can complete or fail it;
  a zombie worker gets `OwnershipViolationException` instead of silently overwriting state.
- **Fingerprint protection** — a retry with the same key but a different payload is rejected
  with `FingerprintMismatchException` (Stripe-style).
- **Result replay** — repeated calls return the stored result without re-executing.
- **Conflict policies** — concurrent execution: throw or wait (polling with backoff + jitter);
  previous failure: throw or retry with a budget.
- Zero runtime dependencies. PHP 8.2+, MIT.

## Storage backend support

| Backend | Status |
|---|---|
| MySQL >= 8.0 | Supported |
| MariaDB >= 10.5 | Supported |
| PostgreSQL >= 13 | Supported |
| Redis >= 6.0 | Supported |
| SQLite | Not supported (locking requires `SELECT ... FOR UPDATE`) |

## Installation

```bash
composer require shamanzpua/idempotency-engine
```

For SQL backends, apply the schema once:

- MySQL/MariaDB: [`resources/sql/idempotency_records.mysql.sql`](resources/sql/idempotency_records.mysql.sql)
- PostgreSQL: [`resources/sql/idempotency_records.postgresql.sql`](resources/sql/idempotency_records.postgresql.sql)

For Redis, install either `ext-redis` (use `PhpRedisClient`) or `predis/predis` (use `PredisClient`).

## Quickstart

```php
use Shamanzpua\Idempotency\Core\Engine\DefaultIdempotencyEngine;
use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;
use Shamanzpua\Idempotency\Core\Policy\DefaultExecutionPolicy;
use Shamanzpua\Idempotency\Core\Service\ExecutionRunner;
use Shamanzpua\Idempotency\Infrastructure\Fingerprint\Sha256FingerprintGenerator;
use Shamanzpua\Idempotency\Infrastructure\Serialization\JsonResultSerializer;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Support\Clock\SystemClock;

$engine = new DefaultIdempotencyEngine(
    store: new PdoIdempotencyStore(new PDO($dsn, $user, $password)),
    fingerprintGenerator: new Sha256FingerprintGenerator(),
    serializer: new JsonResultSerializer(),
    policy: new DefaultExecutionPolicy(),
    runner: new ExecutionRunner(),
    clock: new SystemClock(),
);

$result = $engine->execute(
    key: $idempotencyKey,                      // e.g. the Idempotency-Key request header
    operation: fn () => $payments->charge($order),
    options: new ExecutionOptions(
        scope: 'payments',
        payload: $requestBody,                 // fingerprint source for mismatch detection
    ),
);
```

The first call executes the operation and stores the result. Any subsequent call with the
same `(scope, key)` and the same payload returns the stored result without executing again.

### Execution options

```php
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;

new ExecutionOptions(
    scope: 'webhooks',                          // namespace for keys (default: "default")
    payload: $payload,                          // fingerprint source
    ttl: Ttl::fromSeconds(300),                 // claim TTL; choose >= worst-case operation duration
    resultTtl: Ttl::fromSeconds(86_400),        // optional: how long the result stays replayable
    inProgressStrategy: InProgressStrategy::WAIT, // or THROW (default)
    failedStrategy: FailedStrategy::RETRY,        // or THROW (default)
    maxRetries: 2,
    waitTimeoutMs: 2_000,
);
```

- When `resultTtl` is omitted, the record keeps the expiry set at claim time (Stripe/AWS-style
  fixed window). When provided, `complete()`/`fail()` extend the expiry from completion time.
- `InProgressStrategy::WAIT` polls with exponential backoff and jitter until the concurrent
  execution finishes, then replays its result.

### Exceptions you should handle

| Exception | Meaning |
|---|---|
| `FingerprintMismatchException` | Same key, different payload — client bug or key reuse. |
| `OperationInProgressException` | Another execution is active (THROW strategy, or WAIT timed out). |
| `OperationStalledException` | The in-progress record is stale (holder likely crashed); retry after its TTL expires. |
| `OperationFailedException` | Previous attempt failed and `failedStrategy` is THROW. |
| `StoreException` | Backend I/O failure. |

## Record lifecycle

`claim()` creates a record in `IN_PROGRESS`. The owner transitions it to `COMPLETED` (with the
serialized result) or `FAILED` (with error details). A `FAILED` or expired record can be
reclaimed by a new execution. Completed results are replayed until the record expires.

## Store notes

### PDO (MySQL / MariaDB / PostgreSQL)

- The claim does **not** rely on the driver's affected-rows reporting on MySQL, so connections
  with `PDO::MYSQL_ATTR_FOUND_ROWS` are safe (at the cost of one extra indexed `SELECT` per claim).
- The store sets `PDO::ATTR_ERRMODE` to `ERRMODE_EXCEPTION` on the connection you pass in.
- Timestamps are stored without timezone information. Run every process that touches the same
  table with the same PHP timezone — UTC is strongly recommended.
- Expired rows are not removed automatically; schedule
  [`resources/sql/cleanup_expired.mysql.sql`](resources/sql/cleanup_expired.mysql.sql) (or an
  equivalent) via cron, or call `deleteExpired()` (`ExpirableStore`) from your scheduler.

### Redis

- All mutations are single atomic Lua scripts; key expiry uses native Redis TTL
  (`deleteExpired()` is a no-op).
- Pass a "plain" client: phpredis with `OPT_SERIALIZER` or `OPT_PREFIX` configured is **not**
  supported — Lua scripts write raw JSON which a serializer would corrupt and a prefix would
  split into different keys. Use a dedicated connection if your app configures those options.
- Replay durability depends on Redis persistence settings (`appendonly`/`save`); a restart
  without persistence forgets in-flight and completed records.
- Redis Cluster: scripts are single-key, so any setup works; keys follow the
  `prefix:scope:key` format.

### InMemory

`InMemoryIdempotencyStore` is for tests and single-process scenarios only; it is not
thread-safe for async/shared-memory runtimes.

## Guarantees and limitations

What the engine guarantees (per supported store):

- At most one execution holds a `(scope, key)` claim at any moment — verified by
  cross-process concurrency stress tests for all three backends.
- Only the claim owner can publish a result or a failure.
- A replayed result is byte-identical to the stored serialization of the first result.

What it does **not** guarantee:

- **Strict exactly-once.** If the operation succeeds but persisting the result fails, the
  record is marked `FAILED` and a later retry will re-execute the operation. Design operations
  to be safe under rare re-execution, or keep side effects transactional with your own storage.
- **Rollback of side effects.** A failed operation is recorded, not undone — this is not a saga
  framework.
- **Object round-trips through replay.** `JsonResultSerializer` returns associative arrays for
  any objects in the result. Return JSON-friendly data from operations, or plug in your own
  `ResultSerializer`.
- **Canonical fingerprints.** The default fingerprint is `sha256(json_encode($payload))` —
  array key order matters. Normalize payloads before passing them, or provide an explicit
  `fingerprint`. Without `payload` or `fingerprint`, the fingerprint falls back to the key
  alone and payload-mismatch protection is disabled.
- **Non-blocking waits.** `InProgressStrategy::WAIT` polls with `usleep()` and blocks the
  current process; avoid long waits inside synchronous HTTP workers.
- **Heartbeat staleness detection.** A crashed holder blocks the key until the claim TTL
  expires; size `ttl` accordingly.

## Testing

```bash
composer test:unit     # unit suite, no services required
composer test          # unit + integration (needs MySQL, PostgreSQL, Redis; see env vars in tests)
```

Integration suites read `DB_DSN`/`DB_USER`/`DB_PASSWORD` (MySQL), `PG_DB_DSN`/`PG_DB_USER`/
`PG_DB_PASSWORD` (PostgreSQL) and `REDIS_DSN`. Cross-process claim contention tests live in
`tests/Integration/Concurrency/`.

## License

MIT. See [LICENSE](LICENSE).
