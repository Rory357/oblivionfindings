<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

trait AuditableChanges
{
    public static function bootAuditableChanges(): void
    {
        static::created(function ($model) {
            AuditLogger::log(
                strtolower(class_basename($model)) . '.create',
                $model,
                ['fields' => array_keys($model->getAttributes())]
            );
        });

        static::updated(function ($model) {
            $changes = array_keys($model->getChanges());
            $changes = array_values(array_filter($changes, fn ($f) => $f !== 'updated_at'));
            if (empty($changes)) {
                return;
            }

            AuditLogger::log(
                strtolower(class_basename($model)) . '.update',
                $model,
                ['fields' => $changes]
            );
        });

        static::deleted(function ($model) {
            AuditLogger::log(
                strtolower(class_basename($model)) . '.delete',
                $model
            );
        });
    }
}
