<?php

namespace App\Services\Medication\Audit;

use InvalidArgumentException;

final class MedicationEventFingerprint
{
    public static function of(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Medication event facts must contain only JSON scalar values and arrays.');
        }

        // Eloquent/MySQL JSON may encode 1.0 as 1. Its value must fingerprint
        // identically after storage without changing list or object structure.
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }
}
