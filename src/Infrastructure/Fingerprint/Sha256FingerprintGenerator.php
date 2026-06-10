<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Fingerprint;

use Shamanzpua\Idempotency\Contract\FingerprintGenerator;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;

final class Sha256FingerprintGenerator implements FingerprintGenerator
{
    public function generate(mixed $input): Fingerprint
    {
        $encoded = json_encode($input, JSON_THROW_ON_ERROR);

        return Fingerprint::fromString(hash('sha256', $encoded));
    }
}
