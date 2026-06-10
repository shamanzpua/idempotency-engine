<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;

interface IdempotencyEngine
{
    /**
     * Executes the operation with idempotency guarantees.
     *
     * Warning: when neither ExecutionOptions::$payload nor ExecutionOptions::$fingerprint
     * is provided, fingerprint is derived from $key only. This legacy fallback disables
     * payload-mismatch protection for calls sharing the same key.
     */
    public function execute(string $key, callable $operation, ?ExecutionOptions $options = null): mixed;
}
