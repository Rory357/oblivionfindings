<?php

namespace App\Exceptions\Medication;

class WitnessRequiredException extends \RuntimeException
{
    public function __construct(string $message = 'A witness is required for this controlled drug operation.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
