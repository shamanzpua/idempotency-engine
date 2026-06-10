<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Model;

use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Enum\ClaimStatus;

final readonly class ClaimResult
{
    public function __construct(
        public ClaimStatus $status,
        public ?IdempotencyRecord $record = null,
        public ?ExecutionId $executionId = null,
    ) {}
}
