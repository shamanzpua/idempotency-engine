<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

use Shamanzpua\Idempotency\Core\Model\ClaimResult;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;

// Implementors: complete() and fail() accept an optional $resultTtl that overrides
// the expiry set during claim(). When null, the claim-time expiry is preserved.

interface IdempotencyStore
{
    /**
     * Acquires the claim for the pair.
     *
     * A non-expired FAILED record is reported as {@see ClaimStatus::ALREADY_FAILED}
     * and left untouched unless $reclaimFailed is true, in which case it is
     * atomically reclaimed (CLAIMED) for a retry. Expired records are always
     * reclaimable regardless of the flag. This lets the engine honour
     * FailedStrategy::THROW (do not re-run a failed operation) versus RETRY
     * (atomic reclaim) without a separate round-trip.
     *
     * Expiry is checked BEFORE the fingerprint: an expired record is equivalent to
     * an absent one, so a differing fingerprint on an expired record yields CLAIMED,
     * not FINGERPRINT_MISMATCH. Fingerprint protection is therefore bounded by the
     * record TTL; implementors must keep this order (pinned by the store contract
     * test suite).
     */
    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
        bool $reclaimFailed = false,
    ): ClaimResult;

    /**
     * Returns the active record for the pair, or null.
     *
     * The contract is uniform across all stores:
     *
     *   - no record            → null
     *   - logically expired    → null
     *   - active (not expired)  → IdempotencyRecord
     *
     * "Expired" is decided against the store's clock. Physical removal of expired
     * rows is left to {@see ExpirableStore::deleteExpired()} (Redis relies on
     * native TTL); correctness must not depend on how often cleanup runs.
     */
    public function get(string $key, string $scope): ?IdempotencyRecord;

    public function complete(
        string $key,
        string $scope,
        ExecutionId $executionId,
        string $serializedResult,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void;

    public function fail(
        string $key,
        string $scope,
        ExecutionId $executionId,
        ErrorDetails $errorDetails,
        \DateTimeImmutable $now,
        ?Ttl $resultTtl = null,
    ): void;
}
