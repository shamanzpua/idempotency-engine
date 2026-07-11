<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoRecordMapper;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\RedisRecordMapper;

final class RecordMapperErrorDetailsTest extends TestCase
{
    /**
     * A non-UTF-8 exception message (binary from crypto/iconv/native drivers)
     * must not make mapErrorDetails() throw a raw JsonException — otherwise
     * fail() aborts before persisting FAILED and the record leaks IN_PROGRESS
     * until its lease expires.
     *
     * @param callable(ErrorDetails): string $map
     */
    #[DataProvider('mappers')]
    public function testNonUtf8ErrorMessageIsEncodedNotThrown(callable $map): void
    {
        $error = new ErrorDetails('RuntimeException', "decrypt failed: \xB1\xF0", 500);

        $json = $map($error);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('RuntimeException', $decoded['type']);
        self::assertSame(500, $decoded['code']);
        self::assertStringStartsWith('decrypt failed: ', $decoded['message']);
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function mappers(): array
    {
        return [
            'pdo' => [static fn (ErrorDetails $e): string => (new PdoRecordMapper(new MySqlDialect()))->mapErrorDetails($e)],
            'redis' => [static fn (ErrorDetails $e): string => (new RedisRecordMapper())->mapErrorDetails($e)],
        ];
    }
}
