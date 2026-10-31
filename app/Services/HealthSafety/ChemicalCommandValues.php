<?php

namespace App\Services\HealthSafety;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Factual values only. Source services separately prove current authority, ownership and persisted acceptance. */
final class ChemicalCommandValues
{
    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** Domain services supply exact reviewed rules; HTTP framing is never silently accepted here. */
    public static function normalize(array $input, array $rules, bool $create = false): array
    {
        $envelope = ['idempotency_key' => ['required', 'string', 'uuid']];
        if (! $create) {
            $envelope['expected_version'] = ['required', 'integer', 'min:0', 'max:4294967294'];
        }
        if (array_intersect(['idempotency_key', 'expected_version'], array_keys($rules)) !== []) {
            throw new LogicException('Chemical domain rules cannot replace the command envelope.');
        }
        $all = [...$envelope, ...$rules];
        $known = array_filter(array_keys($all), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $known) !== []) {
            self::invalid('command', 'Only the reviewed fields for this chemical action may be submitted.');
        }
        foreach ($all as $field => $constraints) {
            if (str_contains($field, '.') || ! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value !== null && in_array('integer', $constraints, true)
                && (! is_int($value) && (! is_string($value) || preg_match('/^-?(0|[1-9]\d*)$/D', $value) !== 1))) {
                self::invalid($field, 'Use an integer identity or revision, not a boolean or rounded value.');
            }
            if ($value !== null && in_array('boolean', $constraints, true)) {
                if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    self::invalid($field, 'Use a factual boolean value.');
                }
                $input[$field] = in_array($value, [true, 1, '1'], true);
            }
        }
        $values = Validator::make($input, $all)->validate();
        foreach ($all as $field => $constraints) {
            if (! str_contains($field, '.') && isset($values[$field]) && in_array('integer', $constraints, true)) {
                $values[$field] = (int) $values[$field];
            }
        }
        $values['idempotency_key'] = strtolower($values['idempotency_key']);

        return $values;
    }

    public static function day(mixed $value, string $field, bool $actual = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            self::invalid($field, 'Use a real YYYY-MM-DD calendar date.');
        }
        if ($actual && $value > now('Pacific/Auckland')->toDateString()) {
            self::invalid($field, 'A completed fact cannot be dated in the future.');
        }

        return $value;
    }

    /** Returns a UTC database instant with retained microseconds, not a date inferred from the server timezone. */
    public static function instant(mixed $value, string $field, bool $actual = true): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) {
            self::invalid($field, 'Use a real date and time with an explicit timezone offset.');
        }
        $offset = $parts[8];
        if ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59
            || ((int) substr($offset, 1, 2) === 14 && (int) substr($offset, 4, 2) !== 0))) {
            self::invalid($field, 'Use a real timezone offset.');
        }
        $instant = Carbon::parse($value)->utc();
        if ($actual && $instant->gt(now())) {
            self::invalid($field, 'An actual fact cannot be recorded in the future.');
        }

        return $instant->format('Y-m-d H:i:s.u');
    }

    /** Existing storage DECIMAL(10,2), preserving unknown null and refusing implicit precision loss. */
    public static function decimalQuantity(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || (is_float($value) && ! is_finite($value))) {
            self::invalid($field, 'Use a non-negative quantity with at most two decimal places.');
        }
        $text = (string) $value;
        if (preg_match('/^(0|[1-9]\d{0,7})(?:\.(\d{1,2}))?$/D', $text, $parts) !== 1) {
            self::invalid($field, 'Use a non-negative quantity with at most two decimal places within the recorded storage range.');
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 2, '0');
    }

    /** UUID uniqueness is checked by the future ledger; this digest binds the exact typed intent. */
    public static function fingerprint(string $sourceType, ?int $sourceId, ?int $parentId, int $actorId, string $command, array $values): string
    {
        if ($sourceType === '' || $command === '' || $actorId < 1 || ($sourceId !== null && $sourceId < 1)
            || ($parentId !== null && $parentId < 1)) {
            throw new LogicException('Chemical intent requires canonical source, parent, actor and command identities.');
        }

        return self::digest(['source_type' => $sourceType, 'source_id' => $sourceId, 'parent_id' => $parentId,
            'actor_id' => $actorId, 'command' => $command, 'values' => array_diff_key($values, ['idempotency_key' => true])]);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $nested) {
                $value[$key] = self::canonical($nested);
            }

            return $value;
        }
        if (($value !== null && ! is_scalar($value)) || (is_float($value) && ! is_finite($value))) {
            throw new LogicException('Chemical intent digests accept finite detached factual values only.');
        }

        return $value;
    }
}
