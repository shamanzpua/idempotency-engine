<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\InMemory;

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

/**
 * In-memory implementation intended for tests and simple single-process scenarios.
 *
 * Not thread-safe: check-then-act flow is not atomic for async/shared-memory runtimes.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore, ExpirableStore
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
    ): ClaimResult {
        $recordKey = $this->recordKey($key, $scope);
        $existing = $this->records[$recordKey] ?? null;

        if ($existing !== null && $existing->expiresAt <= $now) {
            $existing = null;
            unset($this->records[$recordKey]);
        }

        if ($existing === null) {
            $created = new IdempotencyRecord(
                key: $key,
                scope: $scope,
                fingerprint: $fingerprint,
                status: RecordStatus::IN_PROGRESS,
                executionId: $executionId,
                serializedResult: null,
                errorDetails: null,
                createdAt: $now,
                updatedAt: $now,
                expiresAt: $now->modify(sprintf('+%d seconds', $ttl->inSeconds())),
            );

            $this->records[$recordKey] = $created;

            return new ClaimResult(ClaimStatus::CLAIMED, $created, $executionId);
        }

        if (!$existing->fingerprint->equals($fingerprint)) {
            return new ClaimResult(ClaimStatus::FINGERPRINT_MISMATCH, $existing, null);
        }

        return match ($existing->status) {
            RecordStatus::COMPLETED => new ClaimResult(ClaimStatus::ALREADY_COMPLETED, $existing, null),
            RecordStatus::IN_PROGRESS => new ClaimResult(ClaimStatus::ALREADY_IN_PROGRESS, $existing, null),
            RecordStatus::FAILED => $this->reclaimFailed($existing, $executionId, $ttl, $now),
        };
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        return $this->records[$this->recordKey($key, $scope)] ?? null;
    }

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $record = $this->requireRecord($key, $scope);

        if (!$this->isOwnedBy($record, $executionId)) {
            throw new OwnershipViolationException('Only record owner can complete operation.');
        }

        if ($record->status !== RecordStatus::IN_PROGRESS) {
            throw new IllegalStateTransitionException(sprintf(
                'Cannot complete record in status "%s".',
                $record->status->value,
            ));
        }

        $this->records[$this->recordKey($key, $scope)] = new IdempotencyRecord(
            key: $record->key,
            scope: $record->scope,
            fingerprint: $record->fingerprint,
            status: RecordStatus::COMPLETED,
            executionId: $record->executionId,
            serializedResult: $serializedResult,
            errorDetails: null,
            createdAt: $record->createdAt,
            updatedAt: $now,
            expiresAt: $resultTtl !== null
                ? $now->modify(sprintf('+%d seconds', $resultTtl->inSeconds()))
                : $record->expiresAt,
        );
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $record = $this->requireRecord($key, $scope);

        if (!$this->isOwnedBy($record, $executionId)) {
            throw new OwnershipViolationException('Only record owner can fail operation.');
        }

        if ($record->status !== RecordStatus::IN_PROGRESS) {
            throw new IllegalStateTransitionException(sprintf(
                'Cannot fail record in status "%s".',
                $record->status->value,
            ));
        }

        $this->records[$this->recordKey($key, $scope)] = new IdempotencyRecord(
            key: $record->key,
            scope: $record->scope,
            fingerprint: $record->fingerprint,
            status: RecordStatus::FAILED,
            executionId: $record->executionId,
            serializedResult: null,
            errorDetails: $errorDetails,
            createdAt: $record->createdAt,
            updatedAt: $now,
            expiresAt: $resultTtl !== null
                ? $now->modify(sprintf('+%d seconds', $resultTtl->inSeconds()))
                : $record->expiresAt,
        );
    }

    public function deleteExpired(\DateTimeImmutable $before): int
    {
        $deleted = 0;

        foreach ($this->records as $recordKey => $record) {
            if ($record->expiresAt <= $before) {
                unset($this->records[$recordKey]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function reclaimFailed(
        IdempotencyRecord $record,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
    ): ClaimResult {
        $reclaimed = new IdempotencyRecord(
            key: $record->key,
            scope: $record->scope,
            fingerprint: $record->fingerprint,
            status: RecordStatus::IN_PROGRESS,
            executionId: $executionId,
            serializedResult: null,
            errorDetails: null,
            createdAt: $record->createdAt,
            updatedAt: $now,
            expiresAt: $now->modify(sprintf('+%d seconds', $ttl->inSeconds())),
        );

        $this->records[$this->recordKey($record->key, $record->scope)] = $reclaimed;

        return new ClaimResult(ClaimStatus::CLAIMED, $reclaimed, $executionId);
    }

    private function requireRecord(string $key, string $scope): IdempotencyRecord
    {
        $record = $this->get($key, $scope);

        if ($record === null) {
            throw new RecordNotFoundException(sprintf('Record not found: key "%s", scope "%s".', $key, $scope));
        }

        return $record;
    }

    private function isOwnedBy(IdempotencyRecord $record, ExecutionId $executionId): bool
    {
        return $record->executionId !== null && $record->executionId->equals($executionId);
    }

    private function recordKey(string $key, string $scope): string
    {
        return $scope . '::' . $key;
    }
}
