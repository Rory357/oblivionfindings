<?php

namespace App\Services\HealthSafety;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Literal DATEs and explicit-offset instants; never PHP's lenient rollover parser. */
final class RestraintDates
{
    public static function day(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || ((int) $parts[1] < 1000 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
            throw ValidationException::withMessages([$field => 'Choose a real calendar date in YYYY-MM-DD format.']);
        }

        return $value;
    }

    public static function instant(mixed $value, string $field, bool $actual = true): Carbon
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/D', $value, $p)
            || ((int) $p[1] < 1000 || ! checkdate((int) $p[2], (int) $p[3], (int) $p[1])) || (int) $p[4] > 23 || (int) $p[5] > 59 || (int) $p[6] > 59
            || ($p[8] !== 'Z' && ((int) substr($p[8], 1, 2) > 14 || (int) substr($p[8], 4, 2) > 59 || ((int) substr($p[8], 1, 2) === 14 && (int) substr($p[8], 4, 2) !== 0)))) {
            throw ValidationException::withMessages([$field => 'Record a valid date and time with an explicit timezone offset.']);
        }
        $instant = Carbon::parse($value)->utc();
        if ((int) $instant->format('Y') < 1000 || (int) $instant->format('Y') > 9999) {
            throw ValidationException::withMessages([$field => 'Use an instant within the supported calendar range.']);
        }
        if ($actual && $instant->gt(now())) {
            throw ValidationException::withMessages([$field => 'An actual decision or review cannot be in the future.']);
        }

        return $instant;
    }
}
