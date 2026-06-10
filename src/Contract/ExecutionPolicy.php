<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;

interface ExecutionPolicy
{
    public function onInProgress(ExecutionOptions $options, IdempotencyRecord $record): InProgressStrategy;

    public function onFailed(ExecutionOptions $options, IdempotencyRecord $record): FailedStrategy;

    /**
     * Returns true when an in-progress record is considered stale.
     *
     * Default policy may use TTL expiry as a staleness proxy.
     * Custom policies can implement heartbeat-based detection.
     */
    public function isStale(IdempotencyRecord $record, \DateTimeImmutable $now): bool;
}
