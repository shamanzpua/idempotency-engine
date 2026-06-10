<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Exception;

final class OperationInProgressException extends IdempotencyException
{
    public function __construct(string $key, string $scope)
    {
        parent::__construct(
            sprintf('Operation for idempotency key "%s" in scope "%s" is already in progress.', $key, $scope),
        );
    }
}
