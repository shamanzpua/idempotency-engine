<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client;

final class PhpRedisClient implements RedisClient
{
    /**
     * phpredis option ids, mirrored as literals so the guard works even when the
     * Redis extension is not loaded during static analysis. They match
     * Redis::OPT_SERIALIZER, Redis::OPT_PREFIX and Redis::SERIALIZER_NONE.
     */
    private const OPT_SERIALIZER = 1;
    private const OPT_PREFIX = 2;
    private const SERIALIZER_NONE = 0;

    public function __construct(
        private readonly object $redis,
    ) {
        if (!method_exists($this->redis, 'eval') || !method_exists($this->redis, 'get')) {
            throw new \InvalidArgumentException('PhpRedisClient expects a phpredis-compatible client object.');
        }

        $this->assertNoConflictingOptions();
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$args], count($keys));
    }

    public function get(string $key): string|false
    {
        return $this->redis->get($key);
    }

    /**
     * The store writes JSON directly from server-side Lua scripts and manages its
     * own key prefix. A client-side serializer would re-encode reads written by Lua
     * (corrupting them), and a client-side prefix is not applied inside Lua scripts
     * (so writes and reads would target different keys). Reject both up front.
     */
    private function assertNoConflictingOptions(): void
    {
        if (!method_exists($this->redis, 'getOption')) {
            return;
        }

        $serializer = $this->redis->getOption(self::OPT_SERIALIZER);
        if (is_int($serializer) && $serializer !== self::SERIALIZER_NONE) {
            throw new \InvalidArgumentException(
                'PhpRedisClient requires Redis::OPT_SERIALIZER to be Redis::SERIALIZER_NONE. '
                . 'The store writes JSON via server-side Lua that bypasses the client serializer, '
                . 'so an active serializer corrupts reads. Disable it or use a dedicated connection.',
            );
        }

        $prefix = $this->redis->getOption(self::OPT_PREFIX);
        if (is_string($prefix) && $prefix !== '') {
            throw new \InvalidArgumentException(
                'PhpRedisClient does not support Redis::OPT_PREFIX. The store manages its own key '
                . 'prefix, and the client prefix is not applied to keys inside Lua scripts, so writes '
                . 'and reads would diverge. Remove the client prefix or use a dedicated connection.',
            );
        }
    }
}
