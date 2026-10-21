<?php

namespace App\Domain\Referrals\Models\Concerns;

use Illuminate\Support\Str;

trait HasReferralReference
{
    protected static function bootHasReferralReference(): void
    {
        static::creating(function ($model): void {
            $model->reference ??= (string) Str::ulid();
        });
    }
}
