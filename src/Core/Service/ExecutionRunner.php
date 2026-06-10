<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Service;

/**
 * Intentionally trivial. Extension point reserved for Phase 3 (hooks, timeouts).
 * Do not add behaviour here before that phase.
 */
final class ExecutionRunner
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
