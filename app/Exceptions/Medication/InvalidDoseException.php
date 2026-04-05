<?php

namespace App\Exceptions\Medication;

class InvalidDoseException extends \RuntimeException
{
    public function __construct(string $message = 'The administered dose exceeds the prescribed amount.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
