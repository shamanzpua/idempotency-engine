<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Exception;

final class OperationStalledException extends IdempotencyException
{
    public function __construct(string $key, string $scope)
    {
        parent::__construct(
            sprintf(
                'Operation for idempotency key "%s" in scope "%s" appears stalled (stale in-progress record).',
                $key,
                $scope,
            ),
        );
    }
}
