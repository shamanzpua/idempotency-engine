<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\InMemory\InMemoryIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

final class InMemoryStoreContractTest extends AbstractStoreContractTestCase
{
    protected function createStore(FixedClock $clock): IdempotencyStore
    {
        return new InMemoryIdempotencyStore($clock);
    }
}
