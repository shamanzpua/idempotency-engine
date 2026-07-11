<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Engine;

use Shamanzpua\Idempotency\Contract\ExecutionPolicy;
use Shamanzpua\Idempotency\Contract\FingerprintGenerator;
use Shamanzpua\Idempotency\Contract\IdempotencyEngine;
use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Contract\ResultSerializer;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\ExecutionOptions;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\Model\NoPayload;
use Shamanzpua\Idempotency\Core\Service\ExecutionRunner;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Enum\ClaimStatus;
use Shamanzpua\Idempotency\Enum\FailedStrategy;
use Shamanzpua\Idempotency\Enum\InProgressStrategy;
use Shamanzpua\Idempotency\Enum\RecordStatus;
use Shamanzpua\Idempotency\Exception\FingerprintMismatchException;
use Shamanzpua\Idempotency\Exception\OperationFailedException;
use Shamanzpua\Idempotency\Exception\OperationInProgressException;
use Shamanzpua\Idempotency\Support\Clock\Clock;

final readonly class DefaultIdempotencyEngine implements IdempotencyEngine
{
    public function __construct(
        private IdempotencyStore $store,
        private FingerprintGenerator $fingerprintGenerator,
        private ResultSerializer $serializer,
        private ExecutionPolicy $policy,
        private ExecutionRunner $runner,
        private Clock $clock,
        private Ttl $defaultTtl = new Ttl(3600),
    ) {}

    public function execute(string $key, callable $operation, ?ExecutionOptions $options = null): mixed
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Idempotency key cannot be empty.');
        }

        if (strlen($key) > ExecutionOptions::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Idempotency key cannot exceed %d bytes.', ExecutionOptions::MAX_KEY_LENGTH),
            );
        }

        $options = $options ?? new ExecutionOptions();

        return $this->executeInternal(
            $key,
            $operation,
            $options,
            $this->resolveFingerprint($key, $options),
            $this->resolveRetryBudget($options),
        );
    }

    private function executeInternal(
        string $key,
        callable $operation,
        ExecutionOptions $options,
        Fingerprint $fingerprint,
        int $retryBudget,
        bool $reclaimFailed = false,
    ): mixed {
        $now = $this->clock->now();
        $executionId = ExecutionId::generate();
        $ttl = $options->ttl ?? $this->defaultTtl;
        $resultTtl = $options->resultTtl;

        $claim = $this->store->claim($key, $options->scope, $fingerprint, $executionId, $ttl, $now, $reclaimFailed);

        return match ($claim->status) {
            ClaimStatus::CLAIMED => $this->executeClaimed($key, $operation, $options, $executionId, $fingerprint, $retryBudget, $resultTtl),
            ClaimStatus::ALREADY_COMPLETED => $this->replay($claim->record),
            ClaimStatus::ALREADY_IN_PROGRESS => $this->handleInProgress($key, $options, $claim->record, $operation, $fingerprint, $retryBudget),
            ClaimStatus::ALREADY_FAILED => $this->handleAlreadyFailed($key, $operation, $options, $claim->record, $fingerprint, $retryBudget),
            ClaimStatus::FINGERPRINT_MISMATCH => throw new FingerprintMismatchException($key, $options->scope),
        };
    }

    private function executeClaimed(
        string $key,
        callable $operation,
        ExecutionOptions $options,
        ExecutionId $executionId,
        Fingerprint $fingerprint,
        int $retryBudget,
        ?Ttl $resultTtl,
    ): mixed {
        try {
            $result = $this->runner->run($operation);
        } catch (\Throwable $throwable) {
            return $this->handleExecutionFailure($key, $operation, $options, $executionId, $fingerprint, $retryBudget, $throwable, $resultTtl);
        }

        $this->persistSuccessfulResult($key, $options, $executionId, $result, $resultTtl);

        return $result;
    }

    private function resolveFingerprint(string $key, ExecutionOptions $options): Fingerprint
    {
        if ($options->fingerprint !== null) {
            return $options->fingerprint;
        }

        if ($options->payload instanceof NoPayload) {
            return $this->fingerprintGenerator->generate($key);
        }

        return $this->fingerprintGenerator->generate($options->payload);
    }

    private function resolveRetryBudget(ExecutionOptions $options): int
    {
        if ($options->failedStrategy !== FailedStrategy::RETRY) {
            return 0;
        }

        return $options->maxRetries;
    }

    private function handleExecutionFailure(
        string $key,
        callable $operation,
        ExecutionOptions $options,
        ExecutionId $executionId,
        Fingerprint $fingerprint,
        int $retryBudget,
        \Throwable $throwable,
        ?Ttl $resultTtl,
    ): mixed {
        $this->markAsFailed($key, $options->scope, $executionId, $throwable, $resultTtl);

        if ($this->canRetry($options, $retryBudget)) {
            return $this->executeInternal($key, $operation, $options, $fingerprint, $retryBudget - 1, reclaimFailed: true);
        }

        throw $throwable;
    }

    private function persistSuccessfulResult(
        string $key,
        ExecutionOptions $options,
        ExecutionId $executionId,
        mixed $result,
        ?Ttl $resultTtl,
    ): void {
        try {
            $serialized = $this->serializer->serialize($result);
            $this->store->complete($key, $options->scope, $executionId, $serialized, $this->clock->now(), $resultTtl);
        } catch (\Throwable $throwable) {
            $this->markPersistenceFailure($key, $options->scope, $executionId, $throwable, $resultTtl);

            throw $throwable;
        }
    }

    private function replay(?IdempotencyRecord $record): mixed
    {
        if ($record === null || $record->serializedResult === null) {
            throw new \LogicException('Completed claim must provide replayable record.');
        }

        return $this->serializer->deserialize($record->serializedResult);
    }

    private function handleInProgress(
        string $key,
        ExecutionOptions $options,
        ?IdempotencyRecord $record,
        callable $operation,
        Fingerprint $fingerprint,
        int $retryBudget,
    ): mixed
    {
        if ($record === null) {
            throw new \LogicException('In-progress claim must provide record.');
        }

        $strategy = $this->policy->onInProgress($options, $record);

        if ($strategy === InProgressStrategy::THROW) {
            throw new OperationInProgressException($key, $options->scope);
        }

        return $this->waitAndReplay($key, $options, $operation, $fingerprint, $retryBudget);
    }

    private function waitAndReplay(
        string $key,
        ExecutionOptions $options,
        callable $operation,
        Fingerprint $fingerprint,
        int $retryBudget,
    ): mixed
    {
        $scope = $options->scope;
        $deadlineAt = $this->clock->now()->modify(sprintf('+%d milliseconds', $options->waitTimeoutMs));
        $backoffMs = $options->initialBackoffMs;

        while ($this->clock->now() < $deadlineAt) {
            $record = $this->store->get($key, $scope);

            if ($record === null) {
                // The holder's record is gone: an expiry-aware store hides a
                // logically expired in-progress lease (crashed holder) or a
                // completed result whose window closed. Either way the claim is
                // free — take over with a fresh attempt instead of spinning to
                // the timeout. claim() reclaims the expired row atomically.
                // Carry the residual budget so takeovers can't re-arm retries
                // and push executions past maxRetries + 1 for this execute().
                return $this->executeInternal($key, $operation, $options, $fingerprint, $retryBudget);
            }

            if ($this->isReplayable($record)) {
                return $this->replay($record);
            }

            if ($this->isFailed($record)) {
                return $this->handleFailedRecord($key, $operation, $options, $record, $fingerprint, $retryBudget);
            }

            // The record is a still-live in-progress lease held by someone else:
            // keep polling. A crashed holder is recovered without a dedicated
            // staleness signal — once its lease expires, get() reports it absent
            // and the null branch above takes the claim over on the next poll.
            $backoffMs = $this->sleepAndIncreaseBackoff($backoffMs, $options);
        }

        throw new OperationInProgressException($key, $scope);
    }

    private function isReplayable(?IdempotencyRecord $record): bool
    {
        return $record !== null
            && $record->status === RecordStatus::COMPLETED
            && $record->serializedResult !== null;
    }

    private function isFailed(?IdempotencyRecord $record): bool
    {
        return $record !== null && $record->status === RecordStatus::FAILED;
    }

    private function handleAlreadyFailed(
        string $key,
        callable $operation,
        ExecutionOptions $options,
        ?IdempotencyRecord $record,
        Fingerprint $fingerprint,
        int $retryBudget,
    ): mixed {
        if ($record === null) {
            throw new \LogicException('Already-failed claim must provide record.');
        }

        return $this->handleFailedRecord($key, $operation, $options, $record, $fingerprint, $retryBudget);
    }

    private function handleFailedRecord(
        string $key,
        callable $operation,
        ExecutionOptions $options,
        IdempotencyRecord $record,
        Fingerprint $fingerprint,
        int $retryBudget,
    ): mixed {
        if ($this->policy->onFailed($options, $record) === FailedStrategy::THROW) {
            throw new OperationFailedException($key, $options->scope, $record->errorDetails);
        }

        // RETRY: reclaim the failed record and re-run, carrying the *residual*
        // budget rather than re-arming a fresh maxRetries. Decrementing stays the
        // sole job of handleExecutionFailure (one unit per actual run), so the
        // total run count for this execute() stays capped at maxRetries + 1.
        // A spent budget surfaces the last failure instead of recursing, which
        // also bounds ping-pong when concurrent callers keep re-failing the key.
        if ($retryBudget <= 0) {
            throw new OperationFailedException($key, $options->scope, $record->errorDetails);
        }

        return $this->executeInternal($key, $operation, $options, $fingerprint, $retryBudget, reclaimFailed: true);
    }

    private function sleepAndIncreaseBackoff(int $backoffMs, ExecutionOptions $options): int
    {
        $delayMs = $this->withJitter($backoffMs, $options->jitterRatio);
        usleep((int) max(1, $delayMs) * 1000);

        return min($options->maxBackoffMs, $backoffMs * 2);
    }

    private function canRetry(ExecutionOptions $options, int $retryBudget): bool
    {
        return $options->failedStrategy === FailedStrategy::RETRY && $retryBudget > 0;
    }

    private function markAsFailed(string $key, string $scope, ExecutionId $executionId, \Throwable $throwable, ?Ttl $resultTtl): void
    {
        $this->store->fail(
            key: $key,
            scope: $scope,
            executionId: $executionId,
            errorDetails: ErrorDetails::fromThrowable($throwable),
            now: $this->clock->now(),
            resultTtl: $resultTtl,
        );
    }

    private function markPersistenceFailure(
        string $key,
        string $scope,
        ExecutionId $executionId,
        \Throwable $throwable,
        ?Ttl $resultTtl,
    ): void {
        try {
            $this->markAsFailed($key, $scope, $executionId, $throwable, $resultTtl);
        } catch (\Throwable $failureMarkingThrowable) {
            throw new \RuntimeException(
                'Failed to persist successful operation result and failed to mark operation as failed.',
                0,
                $failureMarkingThrowable,
            );
        }
    }

    private function withJitter(int $backoffMs, float $jitterRatio): int
    {
        if ($jitterRatio <= 0) {
            return $backoffMs;
        }

        $delta = (int) round($backoffMs * $jitterRatio);
        if ($delta <= 0) {
            return $backoffMs;
        }

        return $backoffMs + random_int(-$delta, $delta);
    }
}
