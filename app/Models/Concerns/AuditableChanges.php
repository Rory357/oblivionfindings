<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use App\Support\SafeOperationalData;

trait AuditableChanges
{
    public static function bootAuditableChanges(): void
    {
        static::created(function ($model) {
            $attributes = $model->auditSafeAttributes($model->getAttributes());
            $protected = SafeOperationalData::protectsRequestContext($model);
            AuditLogger::log(
                strtolower(class_basename($model)).'.create',
                $model,
                [
                    'fields' => $protected ? SafeOperationalData::auditFields($attributes) : array_keys($attributes),
                    'after' => $protected ? SafeOperationalData::auditValues($attributes) : self::auditSnapshot($attributes),
                ]
            );
        });

        static::updated(function ($model) {
            $rawChanges = $model->getChanges();
            unset($rawChanges['updated_at']);
            $rawChanges = $model->auditSafeAttributes($rawChanges);
            $protected = SafeOperationalData::protectsRequestContext($model);
            $changes = $protected ? SafeOperationalData::auditFields($rawChanges) : array_keys($rawChanges);
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
                strtolower(class_basename($model)).'.update',
                $model,
                [
                    'fields' => $changes,
                    'before' => $protected ? SafeOperationalData::auditValues($before) : self::auditSnapshot($before),
                    'after' => $protected ? SafeOperationalData::auditValues($after) : self::auditSnapshot($after),
                ]
            );
        });

        static::deleted(function ($model) {
            $attributes = $model->auditSafeAttributes($model->getOriginal());
            $protected = SafeOperationalData::protectsRequestContext($model);
            AuditLogger::log(
                strtolower(class_basename($model)).'.delete',
                $model,
                [
                    'fields' => $protected ? SafeOperationalData::auditFields($attributes) : array_keys($attributes),
                    'before' => $protected ? SafeOperationalData::auditValues($attributes) : self::auditSnapshot($attributes),
                ]
            );
        });
    }

    private static function auditSnapshot(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 500) {
                $out[$key] = mb_substr($value, 0, 500).'…';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = array_slice($value, 0, 50);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<int, string> */
    private function auditExcludedAttributes(): array
    {
        if (! property_exists($this, 'auditExcludedAttributes')) {
            return [];
        }

        return is_array($this->auditExcludedAttributes) ? $this->auditExcludedAttributes : [];
    }

    private function auditSafeAttributes(array $data): array
    {
        return array_diff_key($data, array_flip($this->auditExcludedAttributes()));
    }
}
