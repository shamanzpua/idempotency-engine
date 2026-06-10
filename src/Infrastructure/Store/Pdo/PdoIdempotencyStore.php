<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo;

use Shamanzpua\Idempotency\Contract\ExpirableStore;
use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Core\Model\ClaimResult;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\ClaimStatus;
use Shamanzpua\Idempotency\Enum\RecordStatus;
use Shamanzpua\Idempotency\Exception\IllegalStateTransitionException;
use Shamanzpua\Idempotency\Exception\OwnershipViolationException;
use Shamanzpua\Idempotency\Exception\RecordNotFoundException;
use Shamanzpua\Idempotency\Exception\StoreException;
use Shamanzpua\Idempotency\Exception\StoreViolationException;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PdoDialect;

final class PdoIdempotencyStore implements IdempotencyStore, ExpirableStore
{
    public function __construct(
        private readonly \PDO $pdo,
        ?PdoRecordMapper $mapper = null,
        private readonly string $table = 'idempotency_records',
        private readonly PdoDialect $dialect = new MySqlDialect(),
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table) !== 1) {
            throw new \InvalidArgumentException('Invalid table name format.');
        }

        $this->mapper = $mapper ?? new PdoRecordMapper($this->dialect);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    private readonly PdoRecordMapper $mapper;

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
    ): ClaimResult {
        $expiresAt = $now->modify(sprintf('+%d seconds', $ttl->inSeconds()));

        try {
            $this->pdo->beginTransaction();

            if ($this->upsertInProgress($key, $scope, $fingerprint, $executionId, $now, $expiresAt)
                && $this->dialect->isInsertRowCountReliable()
            ) {
                $this->pdo->commit();

                return new ClaimResult(
                    status: ClaimStatus::CLAIMED,
                    record: $this->buildInProgressRecord($key, $scope, $fingerprint, $executionId, $now, $now, $expiresAt),
                    executionId: $executionId,
                );
            }

            $existing = $this->fetchForUpdate($key, $scope);
            if ($existing === null) {
                throw new \LogicException('Upsert reported conflict but row not found.');
            }

            // A freshly generated execution id can only match if this call inserted
            // the row; this is the insert check for dialects with unreliable rowCount.
            if ($existing->status === RecordStatus::IN_PROGRESS
                && $existing->executionId !== null
                && $existing->executionId->equals($executionId)
            ) {
                $this->pdo->commit();

                return new ClaimResult(ClaimStatus::CLAIMED, $existing, $executionId);
            }

            if ($existing->expiresAt <= $now) {
                $reclaimed = $this->rewriteAsInProgress($existing, $fingerprint, $executionId, $now, $expiresAt);
                $this->pdo->commit();

                return new ClaimResult(ClaimStatus::CLAIMED, $reclaimed, $executionId);
            }

            if (!$existing->fingerprint->equals($fingerprint)) {
                $this->pdo->commit();

                return new ClaimResult(ClaimStatus::FINGERPRINT_MISMATCH, $existing, null);
            }

            if ($existing->status === RecordStatus::FAILED) {
                $reclaimed = $this->rewriteAsInProgress($existing, $fingerprint, $executionId, $now, $expiresAt);
                $this->pdo->commit();

                return new ClaimResult(ClaimStatus::CLAIMED, $reclaimed, $executionId);
            }

            $this->pdo->commit();

            return match ($existing->status) {
                RecordStatus::COMPLETED => new ClaimResult(ClaimStatus::ALREADY_COMPLETED, $existing, null),
                default => new ClaimResult(ClaimStatus::ALREADY_IN_PROGRESS, $existing, null),
            };
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new StoreException('Failed to claim idempotency record.', 0, $exception);
        }
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        $sql = sprintf('SELECT * FROM %s WHERE scope = :scope AND idempotency_key = :key LIMIT 1', $this->table);

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                ':scope' => $scope,
                ':key' => $key,
            ]);

            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            /** @var array<string, mixed> $row */
            return $this->mapper->mapRowToRecord($row);
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to read idempotency record.', 0, $exception);
        }
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $expiresAtClause = $resultTtl !== null ? ', expires_at = :expires_at' : '';
        $sql = sprintf(
            'UPDATE %s
             SET status = :status, result_payload = :result_payload, error_json = NULL, updated_at = :updated_at%s
             WHERE scope = :scope AND idempotency_key = :key AND execution_id = :execution_id AND status = :in_progress',
            $this->table,
            $expiresAtClause,
        );

        $params = [
            ':status' => RecordStatus::COMPLETED->value,
            ':result_payload' => $serializedResult,
            ':updated_at' => $this->formatDateTime($now),
            ':scope' => $scope,
            ':key' => $key,
            ':execution_id' => $executionId->toString(),
            ':in_progress' => RecordStatus::IN_PROGRESS->value,
        ];
        if ($resultTtl !== null) {
            $params[':expires_at'] = $this->formatDateTime($now->modify(sprintf('+%d seconds', $resultTtl->inSeconds())));
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

            if ($statement->rowCount() === 0) {
                throw $this->resolveUpdateFailure($key, $scope, $executionId);
            }
        } catch (StoreViolationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to complete idempotency record.', 0, $exception);
        }
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $expiresAtClause = $resultTtl !== null ? ', expires_at = :expires_at' : '';
        $sql = sprintf(
            'UPDATE %s
             SET status = :status, result_payload = NULL, error_json = :error_json, updated_at = :updated_at%s
             WHERE scope = :scope AND idempotency_key = :key AND execution_id = :execution_id AND status = :in_progress',
            $this->table,
            $expiresAtClause,
        );

        $params = [
            ':status' => RecordStatus::FAILED->value,
            ':error_json' => $this->mapper->mapErrorDetails($errorDetails),
            ':updated_at' => $this->formatDateTime($now),
            ':scope' => $scope,
            ':key' => $key,
            ':execution_id' => $executionId->toString(),
            ':in_progress' => RecordStatus::IN_PROGRESS->value,
        ];
        if ($resultTtl !== null) {
            $params[':expires_at'] = $this->formatDateTime($now->modify(sprintf('+%d seconds', $resultTtl->inSeconds())));
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

            if ($statement->rowCount() === 0) {
                throw $this->resolveUpdateFailure($key, $scope, $executionId);
            }
        } catch (StoreViolationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to mark idempotency record as failed.', 0, $exception);
        }
    }

    public function deleteExpired(\DateTimeImmutable $before): int
    {
        $sql = sprintf('DELETE FROM %s WHERE expires_at <= :before', $this->table);

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                ':before' => $this->formatDateTime($before),
            ]);

            return $statement->rowCount();
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to delete expired idempotency records.', 0, $exception);
        }
    }

    private function upsertInProgress(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): bool {
        $statement = $this->pdo->prepare($this->dialect->insertInProgressSql($this->table));
        $statement->execute([
            ':scope' => $scope,
            ':key' => $key,
            ':fingerprint' => $fingerprint->toString(),
            ':status' => RecordStatus::IN_PROGRESS->value,
            ':execution_id' => $executionId->toString(),
            ':created_at' => $this->formatDateTime($now),
            ':updated_at' => $this->formatDateTime($now),
            ':expires_at' => $this->formatDateTime($expiresAt),
        ]);

        return $statement->rowCount() === 1;
    }

    private function rewriteAsInProgress(
        IdempotencyRecord $record,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): IdempotencyRecord {
        $sql = sprintf(
            'UPDATE %s
             SET fingerprint = :fingerprint, status = :status, execution_id = :execution_id, result_payload = NULL, error_json = NULL,
                 updated_at = :updated_at, expires_at = :expires_at
             WHERE scope = :scope AND idempotency_key = :key',
            $this->table,
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':fingerprint' => $fingerprint->toString(),
            ':status' => RecordStatus::IN_PROGRESS->value,
            ':execution_id' => $executionId->toString(),
            ':updated_at' => $this->formatDateTime($now),
            ':expires_at' => $this->formatDateTime($expiresAt),
            ':scope' => $record->scope,
            ':key' => $record->key,
        ]);

        return $this->buildInProgressRecord(
            key: $record->key,
            scope: $record->scope,
            fingerprint: $fingerprint,
            executionId: $executionId,
            createdAt: $record->createdAt,
            updatedAt: $now,
            expiresAt: $expiresAt,
        );
    }

    private function fetchForUpdate(string $key, string $scope): ?IdempotencyRecord
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE scope = :scope AND idempotency_key = :key LIMIT 1 FOR UPDATE',
            $this->table,
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':scope' => $scope,
            ':key' => $key,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapper->mapRowToRecord($row);
    }

    private function buildInProgressRecord(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $expiresAt,
    ): IdempotencyRecord {
        return new IdempotencyRecord(
            key: $key,
            scope: $scope,
            fingerprint: $fingerprint,
            status: RecordStatus::IN_PROGRESS,
            executionId: $executionId,
            serializedResult: null,
            errorDetails: null,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            expiresAt: $expiresAt,
        );
    }

    private function resolveUpdateFailure(string $key, string $scope, ExecutionId $executionId): StoreViolationException
    {
        $sql = sprintf(
            'SELECT execution_id, status FROM %s WHERE scope = :scope AND idempotency_key = :key LIMIT 1',
            $this->table,
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':scope' => $scope, ':key' => $key]);
        /** @var array{execution_id: string|null, status: string}|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return new RecordNotFoundException(sprintf('Record not found: key "%s", scope "%s".', $key, $scope));
        }

        if (($row['execution_id'] ?? '') !== $executionId->toString()) {
            return new OwnershipViolationException('Only owner can complete or fail operation.');
        }

        return new IllegalStateTransitionException(sprintf('Record status "%s" does not allow this transition.', $row['status']));
    }

    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $this->dialect->formatDateTime($dateTime);
    }
}
