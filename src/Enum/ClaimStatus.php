<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Enum;

enum ClaimStatus: string
{
    case CLAIMED = 'claimed';
    case ALREADY_COMPLETED = 'already_completed';
    case ALREADY_IN_PROGRESS = 'already_in_progress';
    case FINGERPRINT_MISMATCH = 'fingerprint_mismatch';
}
