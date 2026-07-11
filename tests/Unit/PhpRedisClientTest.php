<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\PhpRedisClient;

final class PhpRedisClientTest extends TestCase
{
    public function testRejectsActiveSerializer(): void
    {
        $redis = new FakePhpRedis(serializer: 1 /* SERIALIZER_PHP */, prefix: '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OPT_SERIALIZER');

        new PhpRedisClient($redis);
    }

    public function testRejectsIgbinarySerializer(): void
    {
        $redis = new FakePhpRedis(serializer: 2 /* SERIALIZER_IGBINARY */, prefix: '');

        $this->expectException(\InvalidArgumentException::class);

        new PhpRedisClient($redis);
    }

    public function testRejectsConfiguredPrefix(): void
    {
        $redis = new FakePhpRedis(serializer: 0, prefix: 'app:');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OPT_PREFIX');

        new PhpRedisClient($redis);
    }

    public function testAcceptsNoSerializerAndNoPrefix(): void
    {
        $redis = new FakePhpRedis(serializer: 0, prefix: '');

        $client = new PhpRedisClient($redis);

        self::assertInstanceOf(PhpRedisClient::class, $client);
    }

    public function testAcceptsClientWithoutGetOption(): void
    {
        $redis = new FakePhpRedisWithoutGetOption();

        $client = new PhpRedisClient($redis);

        self::assertInstanceOf(PhpRedisClient::class, $client);
    }

    public function testRejectsNonPhpRedisObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('phpredis-compatible');

        new PhpRedisClient(new \stdClass());
    }
}

final class FakePhpRedis
{
    public function __construct(
        private readonly int $serializer,
        private readonly string $prefix,
    ) {}

    public function getOption(int $option): mixed
    {
        return match ($option) {
            1 => $this->serializer, // OPT_SERIALIZER
            2 => $this->prefix,     // OPT_PREFIX
            default => false,
        };
    }

    /**
     * @param array<array-key, mixed> $args
     */
    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        return null;
    }

    public function get(string $key): mixed
    {
        return false;
    }
}

final class FakePhpRedisWithoutGetOption
{
    /**
     * @param array<array-key, mixed> $args
     */
    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        return null;
    }

    public function get(string $key): mixed
    {
        return false;
    }
}
