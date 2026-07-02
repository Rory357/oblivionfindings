<?php

namespace App\Models\Concerns;

use App\Services\References\ReferenceNumberGenerator;

/**
 * Auto-assigns a ticket number (e.g. INC-2026-0042) on create via the
 * central ReferenceNumberGenerator. The model declares its prefix:
 *
 *     public const REFERENCE_PREFIX = 'INC';
 *
 * The column is `reference_number`; an explicitly supplied value wins.
 */
trait HasReferenceNumber
{
    public static function bootHasReferenceNumber(): void
    {
        static::creating(function ($model): void {
            if (empty($model->reference_number)) {
                $model->reference_number = app(ReferenceNumberGenerator::class)
                    ->next(static::REFERENCE_PREFIX);
            }
        });
    }
}
