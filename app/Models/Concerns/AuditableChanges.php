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
                [
                    'fields' => array_keys($model->getAttributes()),
                    'after' => self::snapshot($model->getAttributes()),
                ]
            );
        });

        static::updated(function ($model) {
            $rawChanges = $model->getChanges();
            unset($rawChanges['updated_at']);

            $changes = array_keys($rawChanges);
            if (empty($changes)) {
                return;
            }

            // Capture before/after for only the changed keys (best-effort, keep it small).
            $before = [];
            $after = [];
            foreach ($changes as $key) {
                $before[$key] = $model->getOriginal($key);
                $after[$key] = $rawChanges[$key] ?? $model->getAttribute($key);
            }

            AuditLogger::log(
                strtolower(class_basename($model)) . '.update',
                $model,
                [
                    'fields' => $changes,
                    'before' => self::snapshot($before),
                    'after' => self::snapshot($after),
                ]
            );
        });

        static::deleted(function ($model) {
            AuditLogger::log(
                strtolower(class_basename($model)) . '.delete',
                $model,
                ['before' => self::snapshot($model->getOriginal())]
            );
        });
    }

    private static function snapshot(array $data): array
    {
        // Keep audit payload small + safe.
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($v) && mb_strlen($v) > 500) {
                $out[$k] = mb_substr($v, 0, 500) . '…';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = array_slice($v, 0, 50);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
