<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Support\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
