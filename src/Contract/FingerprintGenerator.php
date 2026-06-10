<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Contract;

use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;

interface FingerprintGenerator
{
    public function generate(mixed $input): Fingerprint;
}
