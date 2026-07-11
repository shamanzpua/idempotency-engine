<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Contract;

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\PhpRedisClient;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\RedisIdempotencyStore;
use Shamanzpua\Idempotency\Tests\Support\FixedClock;

/**
 * Runs the store contract against the phpredis adapter (ext-redis), so the second
 * documented Redis client is exercised against a real server — not just Predis and
 * the mocked PhpRedisClient unit test. Skips when ext-redis is absent.
 */
final class PhpRedisStoreContractTest extends AbstractStoreContractTestCase
{
    protected function createStore(FixedClock $clock): ?IdempotencyStore
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        $dsn = getenv('REDIS_DSN') ?: '';
        if ($dsn === '') {
            return null;
        }

        $host = parse_url($dsn, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($dsn, PHP_URL_PORT) ?: 6379;

        $redis = new \Redis();
        $redis->connect($host, (int) $port);
        $redis->flushdb();

        return new RedisIdempotencyStore(new PhpRedisClient($redis), clock: $clock);
    }
}
