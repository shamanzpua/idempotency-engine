<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Policy;

use Shamanzpua\Idempotency\Contract\ExecutionPolicy;
use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;

final class DefaultExecutionPolicy implements ExecutionPolicy
{
    public function onInProgress(ExecutionOptions $options, IdempotencyRecord $record): InProgressStrategy
    {
        return $options->inProgressStrategy;
    }

    public function onFailed(ExecutionOptions $options, IdempotencyRecord $record): FailedStrategy
    {
        return $options->failedStrategy;
    }

    public function isStale(IdempotencyRecord $record, \DateTimeImmutable $now): bool
    {
        return $record->expiresAt <= $now;
    }
}
