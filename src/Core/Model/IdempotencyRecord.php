<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Model;

use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Enum\RecordStatus;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $key,
        public string $scope,
        public Fingerprint $fingerprint,
        public RecordStatus $status,
        public ?ExecutionId $executionId,
        public ?string $serializedResult,
        public ?ErrorDetails $errorDetails,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
        if ($this->key === '') {
            throw new \InvalidArgumentException('Key cannot be empty.');
        }

        if ($this->scope === '') {
            throw new \InvalidArgumentException('Scope cannot be empty.');
        }
    }
}
