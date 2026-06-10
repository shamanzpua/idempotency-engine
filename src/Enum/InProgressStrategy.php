<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Enum;

enum InProgressStrategy: string
{
    case THROW = 'throw';
    case WAIT = 'wait';
}
