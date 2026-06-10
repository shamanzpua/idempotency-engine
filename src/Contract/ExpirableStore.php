<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

interface ExpirableStore
{
    /**
     * Deletes records expired before or at the given timestamp.
     *
     * Returns the number of deleted records.
     */
    public function deleteExpired(\DateTimeImmutable $before): int;
}
