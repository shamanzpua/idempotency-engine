<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client;

interface RedisClient
{
    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;

    public function get(string $key): string|false;
}
