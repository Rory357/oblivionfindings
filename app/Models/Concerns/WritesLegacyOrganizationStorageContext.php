<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplies one inert application value to a legacy organisation storage field
 * and prevents that field from appearing in serialized application data.
 *
 * Canonical ownership and access must never read this value.
 */
trait WritesLegacyOrganizationStorageContext
{
    private const STORAGE_COLUMN = 'organization_id';

    private const APPLICATION_VALUE = 1;

    public function initializeWritesLegacyOrganizationStorageContext(): void
    {
        $this->mergeHidden([self::STORAGE_COLUMN]);
    }

    public static function bootWritesLegacyOrganizationStorageContext(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute(self::STORAGE_COLUMN) === null) {
                $model->setAttribute(self::STORAGE_COLUMN, self::APPLICATION_VALUE);
            }
        });
    }
}
