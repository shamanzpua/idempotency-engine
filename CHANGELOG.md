# Changelog

All notable changes to this package are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
  `RecordNotFoundException` and `IllegalStateTransitionException`;
  `OperationStalledException` distinguishes stale in-progress records.
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

[Unreleased]: https://github.com/shamanzpua/idempotency-engine/compare/v1.0.0-rc1...HEAD
[1.0.0-rc1]: https://github.com/shamanzpua/idempotency-engine/releases/tag/v1.0.0-rc1
