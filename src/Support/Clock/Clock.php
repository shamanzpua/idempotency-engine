<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Support\Clock;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
