<?php

namespace App\Domain\Referrals\Models\Concerns;

use LogicException;

trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException('Record a new revision or event; historical referral evidence cannot be overwritten.'));
        static::deleting(fn () => throw new LogicException('Historical referral evidence requires the controlled retention process.'));
    }
}
