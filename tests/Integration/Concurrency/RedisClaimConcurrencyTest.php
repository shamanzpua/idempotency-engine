<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Integration\Concurrency;

final class RedisClaimConcurrencyTest extends AbstractClaimConcurrencyTestCase
{
    protected function backend(): string
    {
        return 'redis';
    }

    protected function isConfigured(): bool
    {
        return class_exists(\Predis\Client::class) && (getenv('REDIS_DSN') ?: '') !== '';
    }

    protected function prepareStorage(): void
    {
        (new \Predis\Client((string) getenv('REDIS_DSN')))->flushdb();
    }
}
