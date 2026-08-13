<?php

namespace App\Services\Catering;

use RuntimeException;

class UnsafeMealServiceReversal extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("This meal cannot be un-served safely: {$reason}");
    }
}
