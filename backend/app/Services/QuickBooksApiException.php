<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class QuickBooksApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
