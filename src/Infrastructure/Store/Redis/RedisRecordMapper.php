<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis;

use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Enum\RecordStatus;

final class RedisRecordMapper
{
    /**
     * @param array<string, mixed> $data
     */
    public function mapArrayToRecord(string $key, string $scope, array $data): IdempotencyRecord
    {
        $errorDetails = null;
        if (isset($data['error_json']) && is_string($data['error_json']) && $data['error_json'] !== '') {
            /** @var array{type?: string, message?: string, code?: int|string} $decoded */
            $decoded = json_decode($data['error_json'], true, 512, JSON_THROW_ON_ERROR);
            $errorDetails = new ErrorDetails(
                type: (string) ($decoded['type'] ?? 'UnknownException'),
                message: (string) ($decoded['message'] ?? 'Unknown error'),
                code: (int) ($decoded['code'] ?? 0),
            );
        }

        return new IdempotencyRecord(
            key: $key,
            scope: $scope,
            fingerprint: Fingerprint::fromString((string) $data['fingerprint']),
            status: RecordStatus::from((string) $data['status']),
            executionId: isset($data['execution_id']) && is_string($data['execution_id']) && $data['execution_id'] !== ''
                ? ExecutionId::fromString($data['execution_id'])
                : null,
            serializedResult: isset($data['result_payload']) && is_string($data['result_payload']) ? $data['result_payload'] : null,
            errorDetails: $errorDetails,
            createdAt: new \DateTimeImmutable((string) $data['created_at']),
            updatedAt: new \DateTimeImmutable((string) $data['updated_at']),
            expiresAt: new \DateTimeImmutable((string) $data['expires_at']),
        );
    }

    public function mapErrorDetails(ErrorDetails $errorDetails): string
    {
        return json_encode(
            [
                'type' => $errorDetails->type,
                'message' => $errorDetails->message,
                'code' => $errorDetails->code,
            ],
            // JSON_INVALID_UTF8_SUBSTITUTE: exception messages can carry non-UTF-8
            // bytes (crypto/iconv/native drivers). Substituting them keeps fail()
            // from throwing a raw JsonException that would leave the record stuck
            // IN_PROGRESS until its lease expires.
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
