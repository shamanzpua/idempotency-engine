<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

interface ResultSerializer
{
    public function serialize(mixed $result): string;

    public function deserialize(string $payload): mixed;
}
