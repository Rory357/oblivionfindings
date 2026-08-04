<?php

namespace App\Models\Concerns;

use App\Support\LegacyStorageContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplies one inert application-level value to required compatibility fields
 * and prevents those fields from appearing in serialized application data.
 *
 * Canonical ownership and access must never read this value.
 */
trait WritesLegacyStorageContext
{
    public function initializeWritesLegacyStorageContext(): void
    {
        $this->mergeHidden([LegacyStorageContext::column()]);
    }

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

    /**
     * Replicate an application record without carrying its inert compatibility
     * value forward. The normal creating hook supplies the canonical write-only
     * value to the clone.
     *
     * @param  array<int, string>  $except
     */
    public function replicateForApplication(array $except = []): static
    {
        return $this->replicate([
            LegacyStorageContext::column(),
            ...$except,
        ]);
    }
}
