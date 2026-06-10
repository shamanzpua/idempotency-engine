<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client;

final class PredisClient implements RedisClient
{
    public function __construct(
        private readonly \Predis\ClientInterface $client,
    ) {}

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->client->eval($script, count($keys), ...$keys, ...$args);
    }

    public function get(string $key): string|false
    {
        $result = $this->client->get($key);

        return $result !== null ? $result : false;
    }
}
