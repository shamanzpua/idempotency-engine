<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;

final class ErrorDetailsTest extends TestCase
{
    public function testFromThrowableKeepsIntCode(): void
    {
        $details = ErrorDetails::fromThrowable(new \RuntimeException('boom', 500));

        self::assertSame(\RuntimeException::class, $details->type);
        self::assertSame('boom', $details->message);
        self::assertSame(500, $details->code);
    }

    public function testFromThrowableCastsNumericStringCode(): void
    {
        $details = ErrorDetails::fromThrowable(new StringCodeException('duplicate entry', '23000'));

        self::assertSame(23000, $details->code);
    }

    public function testFromThrowableNormalizesNonNumericStringCodeToZero(): void
    {
        $details = ErrorDetails::fromThrowable(new StringCodeException('general error', 'HY000'));

        self::assertSame(0, $details->code);
    }

    public function testFromThrowableHandlesPdoExceptionWithSqlstateCode(): void
    {
        $exception = new \PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $codeProperty = new \ReflectionProperty(\Exception::class, 'code');
        $codeProperty->setValue($exception, '23000');

        $details = ErrorDetails::fromThrowable($exception);

        self::assertSame(\PDOException::class, $details->type);
        self::assertSame(23000, $details->code);
    }
}

/**
 * Mimics PDOException, which assigns SQLSTATE strings to the untyped code property.
 */
final class StringCodeException extends \RuntimeException
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message);
        $this->code = $code;
    }
}
