<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client;

final class PhpRedisClient implements RedisClient
{
    public function __construct(
        private readonly object $redis,
    ) {
        if (!method_exists($this->redis, 'eval') || !method_exists($this->redis, 'get')) {
            throw new \InvalidArgumentException('PhpRedisClient expects a phpredis-compatible client object.');
        }
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$args], count($keys));
    }

    public function get(string $key): string|false
    {
        return $this->redis->get($key);
    }
}
