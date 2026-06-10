<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Serialization;

use Shamanzpua\Idempotency\Contract\ResultSerializer;
use Shamanzpua\Idempotency\Exception\SerializationException;

final class JsonResultSerializer implements ResultSerializer
{
    public function serialize(mixed $result): string
    {
        try {
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException('Unable to serialize operation result.', 0, $exception);
        }
    }

    public function deserialize(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException('Unable to deserialize operation result.', 0, $exception);
        }
    }
}
