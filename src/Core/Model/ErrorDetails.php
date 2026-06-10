<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Core\Model;

final readonly class ErrorDetails
{
    public function __construct(
        public string $type,
        public string $message,
        public int $code,
    ) {}

    public static function fromThrowable(\Throwable $throwable): self
    {
        return new self(
            type: $throwable::class,
            message: $throwable->getMessage(),
            code: self::normalizeCode($throwable->getCode()),
        );
    }

    /**
     * Throwable::getCode() is untyped: PDOException returns SQLSTATE strings
     * (e.g. "23000", "HY000"), which would fail the int parameter under strict types.
     */
    private static function normalizeCode(mixed $code): int
    {
        if (is_int($code)) {
            return $code;
        }

        if (is_numeric($code)) {
            return (int) $code;
        }

        return 0;
    }
}
