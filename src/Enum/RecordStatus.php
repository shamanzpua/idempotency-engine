<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Enum;

enum RecordStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
