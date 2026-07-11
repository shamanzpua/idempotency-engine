<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Support;

use Shamanzpua\Idempotency\Support\Clock\Clock;

/**
 * Deterministic clock for tests. Defaults to the 2026-01-01 UTC epoch used across
 * the suite; call set() to advance time (e.g. to make a record logically expire).
 */
final class FixedClock implements Clock
{
    public function __construct(
        private \DateTimeImmutable $now = new \DateTimeImmutable('2026-01-01 00:00:00'),
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
