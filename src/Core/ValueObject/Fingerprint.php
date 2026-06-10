<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\ValueObject;

final readonly class Fingerprint
{
    public function __construct(private string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Fingerprint cannot be empty.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
