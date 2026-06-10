<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Model;

use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;

final readonly class ExecutionOptions
{
    public const DEFAULT_SCOPE = 'default';
    public const DEFAULT_WAIT_TIMEOUT_MS = 1_000;
    public const DEFAULT_INITIAL_BACKOFF_MS = 25;
    public const DEFAULT_MAX_BACKOFF_MS = 250;
    public const DEFAULT_JITTER_RATIO = 0.2;
    public const DEFAULT_MAX_RETRIES = 1;

    public function __construct(
        public string $scope = self::DEFAULT_SCOPE,
        public ?Ttl $ttl = null,
        public ?Ttl $resultTtl = null,
        public mixed $payload = new NoPayload(),
        public ?Fingerprint $fingerprint = null,
        public InProgressStrategy $inProgressStrategy = InProgressStrategy::THROW,
        public FailedStrategy $failedStrategy = FailedStrategy::THROW,
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public int $waitTimeoutMs = self::DEFAULT_WAIT_TIMEOUT_MS,
        public int $initialBackoffMs = self::DEFAULT_INITIAL_BACKOFF_MS,
        public int $maxBackoffMs = self::DEFAULT_MAX_BACKOFF_MS,
        public float $jitterRatio = self::DEFAULT_JITTER_RATIO,
    ) {
        if ($this->scope === '') {
            throw new \InvalidArgumentException('Scope cannot be empty.');
        }

        if ($this->waitTimeoutMs <= 0) {
            throw new \InvalidArgumentException('waitTimeoutMs must be greater than 0.');
        }

        if ($this->initialBackoffMs <= 0) {
            throw new \InvalidArgumentException('initialBackoffMs must be greater than 0.');
        }

        if ($this->maxBackoffMs <= 0) {
            throw new \InvalidArgumentException('maxBackoffMs must be greater than 0.');
        }

        if ($this->initialBackoffMs > $this->maxBackoffMs) {
            throw new \InvalidArgumentException('initialBackoffMs must be less than or equal to maxBackoffMs.');
        }

        if ($this->jitterRatio < 0 || $this->jitterRatio > 1) {
            throw new \InvalidArgumentException('jitterRatio must be between 0 and 1.');
        }

        if ($this->maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be greater than or equal to 0.');
        }
    }
}
