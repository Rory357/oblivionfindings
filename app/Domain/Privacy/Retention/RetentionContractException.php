<?php

namespace App\Domain\Privacy\Retention;

use RuntimeException;

class RetentionContractException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly bool $blocked = false,
    ) {
        parent::__construct($message);
    }
}
