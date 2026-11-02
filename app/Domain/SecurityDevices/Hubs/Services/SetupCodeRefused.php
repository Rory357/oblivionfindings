<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use DomainException;

/** Why a setup code can't be used: unknown, used, expired or cancelled. */
final class SetupCodeRefused extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'used' => 'This installer was already used. Download a new one from Oblivion.',
            'expired' => 'This installer’s setup code has expired. Download a new one from Oblivion.',
            'cancelled' => 'This installer’s setup code was cancelled in Oblivion.',
            default => 'That setup code isn’t right. Check the 8 characters and try again.',
        });
    }
}
