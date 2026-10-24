<?php

namespace App\Services\HealthSafety;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PpeCommandValues
{
    public const CATEGORIES = 'head,eye,ear,respiratory,hand,foot,body,fall_protection,high_visibility,other';
    public const CONDITIONS = 'new,good,fair,poor,condemned';

    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function normalize(array $input, array $rules, bool $create = false): array
    {
        $envelope = ['idempotency_key' => ['required', 'string', 'uuid']];
        if (! $create) {
            $envelope['expected_version'] = ['required', 'integer', 'min:0', 'max:4294967294'];
        }
        $all = [...$envelope, ...$rules];
        $known = array_filter(array_keys($all), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $known)) {
            self::invalid('command', 'Only the reviewed fields for this PPE action may be submitted.');
        }
        foreach ($all as $field => $constraints) {
            if ((is_bool($input[$field] ?? null) || is_float($input[$field] ?? null)) && in_array('integer', $constraints, true)) {
                self::invalid($field, 'Use an integer identity or revision.');
            }
            if (array_key_exists($field, $input) && in_array('boolean', $constraints, true)) {
                if (! in_array($input[$field], [true, false, 0, 1, '0', '1'], true)) {
                    self::invalid($field, 'Use a factual boolean value.');
                }
                $input[$field] = in_array($input[$field], [true, 1, '1'], true);
            }
        }
        $values = Validator::make($input, $all)->validate();
        foreach ($all as $field => $constraints) {
            if (isset($values[$field]) && in_array('integer', $constraints, true)) {
                $values[$field] = (int) $values[$field];
            }
        }
        $values['idempotency_key'] = strtolower($values['idempotency_key']);
        return $values;
    }

    public static function date(mixed $value, string $field, bool $past = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            self::invalid($field, 'Use a real YYYY-MM-DD calendar date.');
        }
        if ($past && $value > now(config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC')->toDateString()) {
            self::invalid($field, 'A completed fact cannot be dated in the future.');
        }
        return $value;
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
