<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PostgreSqlDialect;

final class PdoDialectTest extends TestCase
{
    public function testMySqlFormatsNonUtcInputAsUtcWallClock(): void
    {
        $dialect = new MySqlDialect();
        // 2026-01-01 00:00:00 in New York (-05:00) == 2026-01-01 05:00:00 UTC.
        $dateTime = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('America/New_York'));

        self::assertSame('2026-01-01 05:00:00.000000', $dialect->formatDateTime($dateTime));
    }

    public function testMySqlSameInstantInDifferentZonesFormatsIdentically(): void
    {
        $dialect = new MySqlDialect();
        $ny = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('America/New_York'));
        $tokyo = $ny->setTimezone(new \DateTimeZone('Asia/Tokyo'));

        self::assertSame($dialect->formatDateTime($ny), $dialect->formatDateTime($tokyo));
    }

    public function testMySqlParsesStoredValueAsUtc(): void
    {
        $dialect = new MySqlDialect();

        $parsed = $dialect->parseDateTime('2026-01-01 05:00:00');

        self::assertSame('UTC', $parsed->getTimezone()->getName());
        self::assertSame('2026-01-01T05:00:00+00:00', $parsed->format(\DateTimeInterface::ATOM));
    }

    public function testMySqlRoundTripPreservesInstantRegardlessOfTimezone(): void
    {
        $dialect = new MySqlDialect();
        $original = new \DateTimeImmutable('2026-06-13 18:30:00', new \DateTimeZone('America/New_York'));

        $roundTripped = $dialect->parseDateTime($dialect->formatDateTime($original));

        self::assertSame($original->getTimestamp(), $roundTripped->getTimestamp());
    }

    public function testPostgreSqlFormatsNonUtcInputAsUtcWallClockWithMicroseconds(): void
    {
        $dialect = new PostgreSqlDialect();
        $dateTime = new \DateTimeImmutable('2026-01-01 00:00:00.000000', new \DateTimeZone('America/New_York'));

        self::assertSame('2026-01-01 05:00:00.000000', $dialect->formatDateTime($dateTime));
    }

    public function testPostgreSqlParsesStoredValueAsUtc(): void
    {
        $dialect = new PostgreSqlDialect();

        $parsed = $dialect->parseDateTime('2026-01-01 05:00:00.000000');

        self::assertSame('UTC', $parsed->getTimezone()->getName());
        self::assertSame('2026-01-01T05:00:00+00:00', $parsed->format(\DateTimeInterface::ATOM));
    }

    public function testPostgreSqlRoundTripPreservesInstantAndMicroseconds(): void
    {
        $dialect = new PostgreSqlDialect();
        $original = new \DateTimeImmutable('2026-06-13 18:30:00.123456', new \DateTimeZone('America/New_York'));

        $roundTripped = $dialect->parseDateTime($dialect->formatDateTime($original));

        self::assertSame($original->getTimestamp(), $roundTripped->getTimestamp());
        self::assertSame('123456', $roundTripped->format('u'));
    }
}
