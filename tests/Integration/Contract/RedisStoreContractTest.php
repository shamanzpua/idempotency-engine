<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\PredisClient;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\RedisIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

final class RedisStoreContractTest extends AbstractStoreContractTestCase
{
    protected function createStore(FixedClock $clock): ?IdempotencyStore
    {
        if (!class_exists(\Predis\Client::class)) {
            return null;
        }

        $dsn = getenv('REDIS_DSN') ?: '';
        if ($dsn === '') {
            return null;
        }

        $client = new \Predis\Client($dsn);
        $client->flushdb();

        return new RedisIdempotencyStore(new PredisClient($client), clock: $clock);
    }
}
