<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Exception;

final class FingerprintMismatchException extends IdempotencyException
{
    public function __construct(string $key, string $scope)
    {
        parent::__construct(
            sprintf('Fingerprint mismatch for idempotency key "%s" in scope "%s".', $key, $scope),
        );
    }
}
