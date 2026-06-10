# Changelog

All notable changes to this package are documented in this file.

## [Unreleased]

### Added

- Payload-aware fingerprint path via `ExecutionOptions::$payload` with explicit fingerprint override priority.
- `OperationStalledException` to distinguish stale in-progress records from active execution.
- `ExpirableStore` contract and `deleteExpired()` implementations for PDO and InMemory stores.
- MySQL cleanup SQL example: `resources/sql/cleanup_expired.mysql.sql`.
- Redis store implementation with Lua-based atomic claim/complete/fail operations.
- Redis client abstraction (`RedisClient`) with phpredis and predis adapters.
- PostgreSQL schema and dedicated integration suite for PDO store.

### Changed

- Retry budget is now configurable with `ExecutionOptions::$maxRetries`.
- `PdoIdempotencyStore` now validates table name format on construction.
- PDO store now supports dialect-aware datetime formatting/parsing (MySQL and PostgreSQL).
- Documentation now includes support matrix, known limitations, and in-progress exception semantics.
