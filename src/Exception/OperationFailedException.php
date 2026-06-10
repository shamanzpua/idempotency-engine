<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Exception;

use Shamanzpua\Idempotency\Core\Model\ErrorDetails;

final class OperationFailedException extends IdempotencyException
{
    public function __construct(string $key, string $scope, ?ErrorDetails $errorDetails = null)
    {
        $message = sprintf('Operation for idempotency key "%s" in scope "%s" has failed.', $key, $scope);

        if ($errorDetails !== null) {
            $message .= sprintf(' Last error: %s: %s', $errorDetails->type, $errorDetails->message);
        }

        parent::__construct($message);
    }
}
