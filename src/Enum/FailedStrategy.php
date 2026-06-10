<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Enum;

enum FailedStrategy: string
{
    case THROW = 'throw';
    case RETRY = 'retry';
}
