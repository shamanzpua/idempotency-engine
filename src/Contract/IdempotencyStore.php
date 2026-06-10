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
    public function claim(
        string $key,
        string $scope,
        Fingerprint $fingerprint,
        ExecutionId $executionId,
        Ttl $ttl,
        \DateTimeImmutable $now,
    ): ClaimResult;

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
