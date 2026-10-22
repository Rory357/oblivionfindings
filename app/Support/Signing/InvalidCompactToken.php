<?php

namespace App\Support\Signing;

/** A token failed a check. The reason is a short code (malformed, algorithm, key, signature, audience, expired, not_yet_valid). */
final class InvalidCompactToken extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The token was refused: '.$reason.'.');
    }
}
