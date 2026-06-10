<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Pdo;

use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\Model\IdempotencyRecord;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Enum\RecordStatus;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PdoDialect;

final class PdoRecordMapper
{
    public function __construct(
        private readonly PdoDialect $dialect = new MySqlDialect(),
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public function mapRowToRecord(array $row): IdempotencyRecord
    {
        $errorDetails = null;
        if (isset($row['error_json']) && is_string($row['error_json']) && $row['error_json'] !== '') {
            /** @var array{type?: string, message?: string, code?: int|string} $decoded */
            $decoded = json_decode($row['error_json'], true, 512, JSON_THROW_ON_ERROR);
            $errorDetails = new ErrorDetails(
                type: (string) ($decoded['type'] ?? 'UnknownException'),
                message: (string) ($decoded['message'] ?? 'Unknown error'),
                code: (int) ($decoded['code'] ?? 0),
            );
        }

        return new IdempotencyRecord(
            key: (string) $row['idempotency_key'],
            scope: (string) $row['scope'],
            fingerprint: Fingerprint::fromString((string) $row['fingerprint']),
            status: RecordStatus::from((string) $row['status']),
            executionId: isset($row['execution_id']) && is_string($row['execution_id']) && $row['execution_id'] !== ''
                ? ExecutionId::fromString($row['execution_id'])
                : null,
            serializedResult: isset($row['result_payload']) ? (string) $row['result_payload'] : null,
            errorDetails: $errorDetails,
            createdAt: $this->dialect->parseDateTime((string) $row['created_at']),
            updatedAt: $this->dialect->parseDateTime((string) $row['updated_at']),
            expiresAt: $this->dialect->parseDateTime((string) $row['expires_at']),
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
            JSON_THROW_ON_ERROR,
        );
    }
}
