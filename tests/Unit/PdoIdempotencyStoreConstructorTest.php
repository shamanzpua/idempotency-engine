<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;

final class PdoIdempotencyStoreConstructorTest extends TestCase
{
    public function testConstructorThrowsWhenPdoIsNotInExceptionMode(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDO::ERRMODE_EXCEPTION');

        new PdoIdempotencyStore($pdo);
    }

    public function testConstructorThrowsForWarningErrorMode(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);

        $this->expectException(\InvalidArgumentException::class);

        new PdoIdempotencyStore($pdo);
    }

    public function testConstructorAcceptsPdoInExceptionMode(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $store = new PdoIdempotencyStore($pdo);

        self::assertInstanceOf(PdoIdempotencyStore::class, $store);
    }

    public function testConstructorDoesNotMutateSharedConnectionErrorMode(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        try {
            new PdoIdempotencyStore($pdo);
        } catch (\InvalidArgumentException) {
            // expected: the store rejects the connection instead of changing it
        }

        self::assertSame(\PDO::ERRMODE_SILENT, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }
}
