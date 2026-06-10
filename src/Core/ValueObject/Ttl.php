<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\ValueObject;

final readonly class Ttl
{
    public function __construct(private int $seconds)
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('TTL must be greater than zero.');
        }
    }

    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    public function inSeconds(): int
    {
        return $this->seconds;
    }
}
