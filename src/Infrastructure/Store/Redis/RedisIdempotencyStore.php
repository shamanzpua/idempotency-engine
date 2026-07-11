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
use Shamanzpua\Idempotency\Support\Clock\Clock;
use Shamanzpua\Idempotency\Support\Clock\SystemClock;

final class RedisIdempotencyStore implements IdempotencyStore, ExpirableStore
{
    public function __construct(
        private readonly RedisClient $client,
        private readonly RedisRecordMapper $mapper = new RedisRecordMapper(),
        private readonly string $prefix = 'idempotency',
        private readonly Clock $clock = new SystemClock(),
    ) {}

    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult {
        $ttlSeconds = $ttl->inSeconds();
        $nowString = $this->formatUtc($now);
        $expiresAt = $this->formatUtc($now->modify(sprintf('+%d seconds', $ttlSeconds)));
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
                    $reclaimFailed ? '1' : '0',
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
            'already_failed' => new ClaimResult(ClaimStatus::ALREADY_FAILED, $record, null),
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

            $record = $this->mapper->mapArrayToRecord($key, $scope, $decoded);

            // Uniform expiry-aware get() contract: never return a logically
            // expired record, even in the rare window where the key physically
            // outlives its logical expiry (see IdempotencyStore::get()).
            if ($record->expiresAt <= $this->clock->now()) {
                return null;
            }

            return $record;
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

    /**
     * Fixed-width UTC timestamp with microseconds, e.g. 2026-01-01T00:00:01.900000Z.
     *
     * The CLAIM script compares expiry lexically (data.expires_at <= now), so the
     * format must make lexical order equal chronological order. Two properties are
     * required and both are provided here:
     *   - always UTC ("Z"), so a mixed-timezone or DST-transitioning fleet cannot
     *     mis-order a live lease (differing offsets break a lexical compare);
     *   - microsecond precision, matching the PDO (DATETIME(6)) and InMemory stores.
     *     ISO-8601 ATOM truncates to whole seconds, which would let a claim taken at
     *     ...00.900 with a 1s TTL be reclaimed ~900ms early (a sub-second double-flight
     *     window), because the physical Redis EX key is still alive.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }

    private function recordKey(string $key, string $scope): string
    {
        // Length-prefixed encoding so distinct (scope, key) pairs can never map to
        // the same physical key (e.g. scope "a:b"/key "c" vs scope "a"/key "b:c").
        return sprintf('%s:%d:%s:%d:%s', $this->prefix, strlen($scope), $scope, strlen($key), $key);
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
            ? $this->formatUtc($now->modify(sprintf('+%d seconds', $resultTtlSeconds)))
            : '';

        try {
            $status = $this->client->eval(
                $script,
                [$redisKey],
                [
                    $executionId->toString(),
                    $payload,
                    $this->formatUtc($now),
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
