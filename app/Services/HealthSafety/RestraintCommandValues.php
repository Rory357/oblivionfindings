<?php

namespace App\Services\HealthSafety;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Typed factual command values. Elapsed whole minutes are rounded down, never wall-clock subtraction. */
final class RestraintCommandValues
{
    public static function validate(array $input, array $rules, bool $create = false): array
    {
        $envelope = ['idempotency_key' => ['required', 'string', 'uuid']];
        if (! $create) {
            $envelope['expected_version'] = ['required', 'integer', 'min:1', 'max:4294967294'];
        }
        if (array_diff(array_keys($input), [...array_keys($envelope), ...array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'))])) {
            throw ValidationException::withMessages(['command' => 'Only this operation’s command fields may be submitted.']);
        }
        foreach (['expected_version', ...array_keys($rules)] as $field) {
            if (in_array('integer', $rules[$field] ?? ($envelope[$field] ?? []), true) && is_bool($input[$field] ?? null)) {
                throw ValidationException::withMessages([$field => 'Use an integer, not a boolean.']);
            }
        }
        $values = Validator::make($input, [...$envelope, ...$rules])->validate();
        foreach ([...$envelope, ...$rules] as $field => $fieldRules) {
            if (! in_array('integer', $fieldRules, true)) {
                continue;
            }
            if (str_ends_with($field, '.*')) {
                $parent = substr($field, 0, -2);
                foreach ($values[$parent] ?? [] as $id) {
                    if (is_bool($id)) {
                        throw ValidationException::withMessages([$parent => 'Use integer identities, not booleans.']);
                    }
                }
                if (isset($values[$parent])) {
                    $values[$parent] = self::ids($values[$parent]);
                }
            } elseif (isset($values[$field])) {
                $values[$field] = (int) $values[$field];
            }
        }
        $values['idempotency_key'] = strtolower($values['idempotency_key']);
        if (! $create) {
            $values['expected_version'] = (int) $values['expected_version'];
        }

        return $values;
    }

    public static function instant(mixed $value, string $field, bool $actual = true): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || ($parts[7] !== 'Z' && ((int) substr($parts[7], 1, 2) > 14 || (int) substr($parts[7], 4, 2) > 59 || ((int) substr($parts[7], 1, 2) === 14 && (int) substr($parts[7], 4, 2) !== 0)))) {
            throw ValidationException::withMessages([$field => 'Use a real date and time with an explicit timezone offset.']);
        }
        $instant = Carbon::parse($value)->utc();
        if ($actual && $instant->gt(now())) {
            throw ValidationException::withMessages([$field => 'An actual fact cannot be recorded in the future.']);
        }

        return $instant->format('Y-m-d H:i:s.u');
    }

    public static function ids(array $values): array
    {
        $ids = array_map('intval', $values);
        sort($ids);

        return $ids;
    }

    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = self::canonical($nested);
        }

        return $value;
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
