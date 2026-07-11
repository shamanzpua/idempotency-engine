# Changelog

All notable changes to this package are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-07-11

Stable release. Hardening pass over `1.0.0-rc1`. See `UPGRADING.md` for migration notes.

### Added

- `CanonicalJsonFingerprintGenerator`: order-independent fingerprints from a
  canonical JSON representation (recursively sorted object keys, list order kept,
  scalar types distinct, NAN/INF rejected). Output is version-prefixed (`v1:…`)
  so the algorithm can evolve without silently mixing incompatible fingerprints.
  Recommended over `Sha256FingerprintGenerator`, which stays for raw inputs.

### Changed

- The `fingerprint` column widens to `VARCHAR(80)` (MySQL/PostgreSQL) to hold the
  version-prefixed fingerprint (`v1:` + 64 hex).
- Documentation no longer claims "exactly once"; the README now describes the real
  guarantee (single-flight execution, duplicate suppression and result replay) and
  when to add provider-side keys / a transactional outbox for critical side effects.
  A cross-store contract test suite and a PHP 8.2–8.5 MySQL/PostgreSQL/Redis CI
  matrix now enforce cross-backend parity, including both Predis and phpredis
  adapters, with an additional MariaDB compatibility job. See `UPGRADING.md`.

- `PdoIdempotencyStore` no longer mutates the caller's connection: instead of
  silently setting `PDO::ATTR_ERRMODE` on a possibly shared `PDO`, the constructor
  now requires `PDO::ERRMODE_EXCEPTION` and throws `InvalidArgumentException`
  otherwise.
- `scope` and `key` are now a documented case-sensitive, byte-exact, NOT PAD contract.
  The MySQL schema uses `VARBINARY` for `scope`/`idempotency_key` (previously
  `utf8mb4_unicode_ci`, which treated `ABC`/`abc` and trailing-space variants as one
  key; `utf8mb4_bin` would still PAD-SPACE-collapse them and is MySQL-only). `execute()`
  validates that the key is non-empty and at most 191 bytes, and `ExecutionOptions`
  validates the scope the same way.
- MySQL `*_at` columns become `DATETIME(6)` for microsecond precision matching PostgreSQL.

### Fixed

- Distinct `(scope, key)` pairs can no longer collide into one physical key. The
  Redis and in-memory stores use a length-prefixed encoding, so e.g. scope `a:b` /
  key `c` and scope `a` / key `b:c` are kept apart. (The PDO store was already
  safe via its composite unique key.)

- `FailedStrategy::THROW` is now honoured for fresh calls: a previously FAILED
  record is reported as the new `ClaimStatus::ALREADY_FAILED` and `execute()`
  raises `OperationFailedException` instead of silently re-running the operation.
  `FailedStrategy::RETRY` still atomically reclaims the failed record. `claim()`
  gained an optional `bool $reclaimFailed` parameter driving this decision.
- `get()` is now expiry-aware and uniform across all stores: a logically expired
  record is reported as absent (`null`) by every store, so a stale result can never
  be replayed. Each store takes an optional `Clock` (defaulting to `SystemClock`)
  that decides expiry; physical removal stays with `deleteExpired()` / Redis TTL.
- `PdoIdempotencyStore::claim()` retries (bounded) instead of throwing when the
  conflicting row is deleted between the upsert and the locking read (e.g. by a
  concurrent `deleteExpired()`). The transactional attempt is now a `protected
  attemptClaim()` and the class is no longer `final`.
- `PhpRedisClient` rejects a phpredis connection configured with an active
  `Redis::OPT_SERIALIZER` or a non-empty `Redis::OPT_PREFIX` (serializer corrupts
  JSON written by Lua; prefix is not applied inside Lua scripts).
- PDO dialects persist and parse all timestamps as canonical UTC wall-clock
  values, so `expires_at` comparisons stay correct across workers in different
  timezones. The SQL schemas document the UTC convention.
- `RedisIdempotencyStore` normalizes every timestamp to UTC before it reaches the
  Lua expiry comparison. The CLAIM script compares expiry lexically, which only
  matched chronological order for same-offset timestamps; a fleet in mixed
  timezones (or crossing a DST boundary) could reclaim a still-live lease and
  double-execute an operation. All stores now share the same lease semantics
  regardless of the worker's process timezone.
- The retry budget is a single hard cap per `execute()` call again. Under
  `WAIT` + `RETRY`, observing another worker's `FAILED` record no longer re-arms a
  fresh `maxRetries` on each in-progress → failed → reclaim handoff, so an
  operation can run at most `maxRetries + 1` times per call. An exhausted budget
  surfaces `OperationFailedException` instead of recursing.
- `CanonicalJsonFingerprintGenerator` now canonicalizes objects (e.g. `stdClass`
  from `json_decode($json, false)`) like associative arrays, so property order no
  longer changes the fingerprint. `JSON_PRESERVE_ZERO_FRACTION` keeps `1.0`
  distinct from `1`, matching the documented scalar-type contract.

### Removed

- `OperationStalledException` and `ExecutionPolicy::isStale()` are removed. With an
  expiry-aware `get()` and expiry-aware `claim()`, a crashed holder's lease is
  taken over automatically once it expires, so the dedicated stalled-record signal
  was unreachable with real stores. `WAIT` recovers such keys by re-executing.

## [1.0.0-rc1] - 2026-06-10

Initial release candidate.

### Added

- `DefaultIdempotencyEngine`: atomic claim, result replay, ownership-checked
  `complete`/`fail`, configurable in-progress (THROW/WAIT) and failed (THROW/RETRY)
  strategies with retry budget, exponential backoff with jitter.
- Payload-aware fingerprint protection (`ExecutionOptions::$payload`) with explicit
  `fingerprint` override; mismatches raise `FingerprintMismatchException`.
- Optional `ExecutionOptions::$resultTtl`: when omitted, records keep the claim-time
  expiry (fixed window); when set, `complete()`/`fail()` extend expiry from completion time.
- Stores: `PdoIdempotencyStore` (MySQL >= 8.0 / MariaDB >= 10.5 via `MySqlDialect`,
  PostgreSQL >= 13 via `PostgreSqlDialect`), `RedisIdempotencyStore` (Redis >= 6.0,
  atomic Lua scripts, phpredis and Predis adapters), `InMemoryIdempotencyStore` (tests).
- Exception hierarchy: `StoreViolationException` split into `OwnershipViolationException`,
  `RecordNotFoundException` and `IllegalStateTransitionException`.
- `ExpirableStore::deleteExpired()` for PDO/InMemory; Redis relies on native TTL.
- `Clock` abstraction (`SystemClock`) — no direct `microtime()`/`time()` calls in the engine.
- SQL schemas and a cleanup script in `resources/sql/`.
- Cross-process claim concurrency stress tests (8 contending OS processes per round,
  MySQL/PostgreSQL/Redis) in `tests/Integration/Concurrency/`.

### Hardening (pre-release audit fixes)

- Exception codes are normalized in `ErrorDetails::fromThrowable()`: string codes such as
  PDO SQLSTATE (`"23000"`) no longer cause a `TypeError` while marking a record failed.
- The MySQL claim no longer trusts the driver's affected-rows count: connections with
  `PDO::MYSQL_ATTR_FOUND_ROWS` enabled previously allowed two concurrent claims to both
  win. Inserts are now verified by execution-id ownership inside the claim transaction.
- Redis `complete`/`fail` use `SET ... KEEPTTL` instead of re-reading `TTL`: a record
  finishing with < 1s of TTL left could previously lose its expiry and live forever.

### Notes for implementors

- The `PdoDialect` interface gained `isInsertRowCountReliable()`; custom dialect
  implementations must add this method.
- `IdempotencyStore::complete()`/`fail()` accept an optional `?Ttl $resultTtl` parameter.

[1.0.0]: https://github.com/shamanzpua/idempotency-engine/compare/v1.0.0-rc1...v1.0.0
[1.0.0-rc1]: https://github.com/shamanzpua/idempotency-engine/releases/tag/v1.0.0-rc1
