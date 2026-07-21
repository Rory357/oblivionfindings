<?php

namespace App\Models\Concerns;

use App\Support\LegacyStorageContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplies one inert application-level value to required compatibility fields.
 *
 * Canonical ownership and access must never read this value.
 */
trait WritesLegacyStorageContext
{
    public static function bootWritesLegacyStorageContext(): void
    {
        static::creating(function (Model $model): void {
            foreach (LegacyStorageContext::attributes() as $column => $value) {
                if ($model->getAttribute($column) === null) {
                    $model->setAttribute($column, $value);
                }
            }
        });
    }
}
