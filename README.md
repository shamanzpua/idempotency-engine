# Idempotency Engine

Framework-agnostic PHP library for **single-flight execution, duplicate suppression and result
replay per idempotency key**: at most one active execution per `(scope, key)`, with retries
returning the stored result. Built for payment operations, webhook handlers, API idempotency
keys and at-least-once queue consumers.

> Not a strict exactly-once guarantee — see [Guarantees and limitations](#guarantees-and-limitations).
> For critical side effects, combine it with a provider-side idempotency key, a unique DB
> constraint and/or a transactional outbox.

- **Atomic claim** — one writer wins per `(scope, key)`; no check-then-act races
  (single upsert + row lock in SQL, single Lua script in Redis).
- **Ownership tokens** — only the execution that claimed a key can complete or fail it;
  a zombie worker gets `OwnershipViolationException` instead of silently overwriting state.
- **Fingerprint protection** — a retry with the same key but a different payload is rejected
  with `FingerprintMismatchException` (Stripe-style) for as long as the record lives
  ([bounded by the TTL](#guarantees-and-limitations)).
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
use Shamanzpua\Idempotency\Infrastructure\Fingerprint\CanonicalJsonFingerprintGenerator;
use Shamanzpua\Idempotency\Infrastructure\Serialization\JsonResultSerializer;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Support\Clock\SystemClock;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$engine = new DefaultIdempotencyEngine(
    store: new PdoIdempotencyStore($pdo),
    fingerprintGenerator: new CanonicalJsonFingerprintGenerator(),
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
| `OperationFailedException` | A prior attempt failed and `failedStrategy` is THROW, or a caller observed an already-failed record under `RETRY` with the budget exhausted. (When a caller exhausts *its own* retries, the operation's original exception is re-thrown instead.) |
| `StoreException` | Backend I/O failure. |

## Record lifecycle

`claim()` creates a record in `IN_PROGRESS`. The owner transitions it to `COMPLETED` (with the
serialized result) or `FAILED` (with error details). A `FAILED` or expired record can be
reclaimed by a new execution. Completed results are replayed until the record expires.

## Store notes

### PDO (MySQL / MariaDB / PostgreSQL)

- The claim does **not** rely on the driver's affected-rows reporting on MySQL, so connections
  with `PDO::MYSQL_ATTR_FOUND_ROWS` are safe (at the cost of one extra indexed `SELECT` per claim).
- The connection **must** already use `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`; the store
  no longer mutates your (possibly shared) connection and throws `InvalidArgumentException` if it
  is configured otherwise.
- `claim()` opens and commits its own transaction, so it **must not** run inside an ambient
  transaction you started (PDO has no true nested transactions). Give the store a dedicated
  connection, or call it outside your own `beginTransaction()`/`commit()` block.
- Timestamps are persisted as canonical UTC wall-clock values regardless of the process
  timezone, so `expires_at` comparisons stay correct across workers in different zones.
- `scope` and `idempotency_key` are case-sensitive, byte-exact and NOT PAD — trailing spaces
  are significant (MySQL uses `VARBINARY`). Keep them under 191 bytes.
- Expired rows are not removed automatically; schedule
  [`resources/sql/cleanup_expired.mysql.sql`](resources/sql/cleanup_expired.mysql.sql) (or an
  equivalent) via cron, or call `deleteExpired()` (`ExpirableStore`) from your scheduler.

### Redis

- All mutations are single atomic Lua scripts; key expiry uses native Redis TTL
  (`deleteExpired()` is a no-op).
- The claim sets the physical key TTL to the claim `ttl`, so a record is evicted exactly at
  its logical expiry (unlike PDO, whose rows linger until garbage-collected). An operation that
  outruns its claim `ttl` therefore loses its record before it can `complete()`. Set claim
  `ttl` above the worst-case operation duration (see Guarantees and limitations).
- Timestamps are normalized to UTC before they reach the Lua expiry comparison, so a fleet
  spread across timezones (or crossing a DST boundary) never mis-orders a live lease as expired.
- Pass a "plain" client: phpredis with `OPT_SERIALIZER` or `OPT_PREFIX` configured is **not**
  supported — Lua scripts write raw JSON which a serializer would corrupt and a prefix would
  split into different keys. Use a dedicated connection if your app configures those options.
- Replay durability depends on Redis persistence settings (`appendonly`/`save`); a restart
  without persistence forgets in-flight and completed records.
- Redis Cluster: scripts are single-key, so any setup works. Keys use a length-prefixed
  `prefix:<len>:scope:<len>:key` encoding so distinct `(scope, key)` pairs never collide.

### InMemory

`InMemoryIdempotencyStore` is for tests and single-process scenarios only; it is not
thread-safe for async/shared-memory runtimes.

## Guarantees and limitations

What the engine guarantees (per supported store):

- At most one execution holds a `(scope, key)` claim at any moment — verified by
  cross-process concurrency stress tests for all three backends.
- Only the claim owner can publish a result or a failure.
- A replayed result is byte-identical to the stored serialization of the first result.
- A logically expired record is never replayed: `get()` reports it as absent across every
  store, and a fresh call re-executes.
- A previously failed operation is not silently re-run: with `FailedStrategy::THROW` a fresh
  call raises `OperationFailedException`; with `RETRY` it is atomically reclaimed.

What it does **not** guarantee:

- **Strict exactly-once.** If the operation succeeds but persisting the result fails, the
  record is marked `FAILED` and a later retry will re-execute the operation. Design operations
  to be safe under rare re-execution, or keep side effects transactional with your own storage.
- **Rollback of side effects.** A failed operation is recorded, not undone — this is not a saga
  framework.
- **Object round-trips through replay.** `JsonResultSerializer` returns associative arrays for
  any objects in the result. Return JSON-friendly data from operations, or plug in your own
  `ResultSerializer`.
- **Unbounded fingerprint protection.** Expiry is evaluated *before* the fingerprint: a
  logically expired record is indistinguishable from an absent one, so `claim()` reclaims it
  and a same-key call with a different payload runs as fresh work instead of raising
  `FingerprintMismatchException`. Payload-mismatch protection therefore lasts exactly as long
  as the record (claim `ttl`, or `resultTtl` once completed) — the same window Stripe-style
  APIs give an idempotency key. If your own durable state outlives the engine record (an order
  row, a payment intent), compare the payload against *that* state rather than relying on the
  engine to reject it forever.
- **Fingerprint canonicalization is opt-in.** `CanonicalJsonFingerprintGenerator` (recommended)
  makes fingerprints order-independent and version-prefixed (`v1:…`); the older
  `Sha256FingerprintGenerator` hashes raw `json_encode($payload)`, so array key order matters.
  Without `payload` or `fingerprint`, the fingerprint falls back to the key alone and
  payload-mismatch protection is disabled.
- **Non-blocking waits.** `InProgressStrategy::WAIT` polls with `usleep()` and blocks the
  current process; avoid long waits inside synchronous HTTP workers.
- **Heartbeat staleness detection.** There is no mid-flight liveness signal: a crashed holder
  keeps the claim until its lease (claim `ttl`) expires. Once expired, the record is treated
  as absent and the next claim (or a `WAIT` poll) automatically takes it over and re-executes;
  there is no separate "stalled" exception. Size `ttl` accordingly (see below).
- **Claim TTL must exceed the worst-case operation duration.** The claim `ttl` is the lease, and
  completing *after* it has expired is outside the supported contract: another worker may have
  already taken the key over, so the outcome of a late `complete()`/`fail()` is store-specific and
  must not be relied on. On PDO/InMemory a late completion by the last owner may still succeed
  until the row is garbage-collected; on **Redis** the key is evicted at the lease boundary
  (native TTL), so a late `complete()` finds nothing and the successful result is lost with a
  `RuntimeException`. Always set `ttl` comfortably above the slowest expected run. Heartbeat /
  lease-renewal (and a uniform expired-lease rejection) are not provided in 1.0.

## Testing

```bash
composer test:unit     # unit suite, no services required
composer test          # unit + integration (needs MySQL, PostgreSQL, Redis; see env vars in tests)
```

Integration suites read `DB_DSN`/`DB_USER`/`DB_PASSWORD` (MySQL), `PG_DB_DSN`/`PG_DB_USER`/
`PG_DB_PASSWORD` (PostgreSQL) and `REDIS_DSN`. Cross-process claim contention tests live in
`tests/Integration/Concurrency/`, and one behavioural contract is run against every store in
`tests/Integration/Contract/` to keep all supported lifecycle scenarios observably identical
across backends. CI runs the full MySQL/PostgreSQL/Redis integration suite on PHP 8.2–8.5,
including both the Predis and phpredis adapters, plus a MariaDB compatibility job, on every pull
request, on pushes to `main` and release branches, and on version tags.

## License

MIT. See [LICENSE](LICENSE).
