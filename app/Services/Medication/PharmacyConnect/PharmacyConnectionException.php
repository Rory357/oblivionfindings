<?php

namespace App\Services\Medication\PharmacyConnect;

use RuntimeException;

final class PharmacyConnectionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
