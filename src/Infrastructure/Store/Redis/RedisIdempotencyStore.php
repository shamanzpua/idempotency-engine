<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis;

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
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\RedisClient;

final class RedisIdempotencyStore implements IdempotencyStore, ExpirableStore
{
    public function __construct(
        private readonly RedisClient $client,
        private readonly RedisRecordMapper $mapper = new RedisRecordMapper(),
        private readonly string $prefix = 'idempotency',
    ) {}

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
    ): ClaimResult {
        $ttlSeconds = $ttl->inSeconds();
        $nowString = $now->format(\DateTimeInterface::ATOM);
        $expiresAt = $now->modify(sprintf('+%d seconds', $ttlSeconds))->format(\DateTimeInterface::ATOM);
        $redisKey = $this->recordKey($key, $scope);

        try {
            $result = $this->client->eval(
                LuaScripts::CLAIM,
                [$redisKey],
                [
                    $fingerprint->toString(),
                    $executionId->toString(),
                    (string) $ttlSeconds,
                    RecordStatus::IN_PROGRESS->value,
                    $nowString,
                    $expiresAt,
                ],
            );
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to claim idempotency record.', 0, $exception);
        }

        if (!is_array($result) || count($result) !== 2) {
            throw new StoreException(sprintf('Unexpected response structure from Redis CLAIM script: %s', json_encode($result)));
        }

        $status = (string) ($result[0] ?? '');
        $recordJson = (string) ($result[1] ?? '');

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($recordJson, true, 512, JSON_THROW_ON_ERROR);
            $record = $this->mapper->mapArrayToRecord($key, $scope, $decoded);
        } catch (\Throwable $exception) {
            throw new StoreException('Failed to parse record from Redis CLAIM response.', 0, $exception);
        }

        return match ($status) {
            'claimed' => new ClaimResult(ClaimStatus::CLAIMED, $record, $executionId),
            'completed' => new ClaimResult(ClaimStatus::ALREADY_COMPLETED, $record, null),
            'in_progress' => new ClaimResult(ClaimStatus::ALREADY_IN_PROGRESS, $record, null),
            'fingerprint_mismatch' => new ClaimResult(ClaimStatus::FINGERPRINT_MISMATCH, $record, null),
            default => throw new StoreException(sprintf('Unexpected claim response from Redis script: %s', $status)),
        };
    }

    public function get(string $key, string $scope): ?IdempotencyRecord
    {
        try {
            $raw = $this->client->get($this->recordKey($key, $scope));
            if ($raw === false) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $this->mapper->mapArrayToRecord($key, $scope, $decoded);
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
        $status = $this->evalCompleteLikeScript(
            LuaScripts::COMPLETE,
            $this->recordKey($key, $scope),
            $executionId,
            $serializedResult,
            $now,
            'Failed to complete idempotency record.',
            $resultTtl,
        );

        $this->throwForLuaStatus($status, $key, $scope);
    }

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void {
        $status = $this->evalCompleteLikeScript(
            LuaScripts::FAIL,
            $this->recordKey($key, $scope),
            $executionId,
            $this->mapper->mapErrorDetails($errorDetails),
            $now,
            'Failed to mark idempotency record as failed.',
            $resultTtl,
        );

        $this->throwForLuaStatus($status, $key, $scope);
    }

    /**
     * Redis handles key expiry natively via TTL.
     * This method is a no-op and always returns 0.
     */
    public function deleteExpired(\DateTimeImmutable $before): int
    {
        return 0;
    }

    private function recordKey(string $key, string $scope): string
    {
        return sprintf('%s:%s:%s', $this->prefix, $scope, $key);
    }

    private function throwForLuaStatus(string $status, string $key, string $scope): void
    {
        if ($status === 'ok') {
            return;
        }

        throw match ($status) {
            'not_found' => new RecordNotFoundException(sprintf('Record not found: key "%s", scope "%s".', $key, $scope)),
            'ownership_violation' => new OwnershipViolationException('Only owner can complete or fail operation.'),
            'wrong_status' => new IllegalStateTransitionException('Record status does not allow this transition.'),
            default => new StoreException(sprintf('Unexpected status from Redis script: %s', $status)),
        };
    }

    private function evalCompleteLikeScript(
        string $script,
        string $redisKey,
        ExecutionId $executionId,
        string $payload,
        \DateTimeImmutable $now,
        string $storeExceptionMessage,
        ?Ttl $resultTtl = null,
    ): string {
        $resultTtlSeconds = $resultTtl?->inSeconds() ?? 0;
        $newExpiresAt = $resultTtl !== null
            ? $now->modify(sprintf('+%d seconds', $resultTtlSeconds))->format(\DateTimeInterface::ATOM)
            : '';

        try {
            $status = $this->client->eval(
                $script,
                [$redisKey],
                [
                    $executionId->toString(),
                    $payload,
                    $now->format(\DateTimeInterface::ATOM),
                    (string) $resultTtlSeconds,
                    $newExpiresAt,
                ],
            );

            return (string) $status;
        } catch (\Throwable $exception) {
            throw new StoreException($storeExceptionMessage, 0, $exception);
        }
    }
}
