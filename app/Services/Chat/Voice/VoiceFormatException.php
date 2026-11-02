<?php

namespace App\Services\Chat\Voice;

use RuntimeException;

/** A voice file broke a format rule. The reason is a short code for tests and logs, never shown to people. */
final class VoiceFormatException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
