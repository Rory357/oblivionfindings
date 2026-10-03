<?php

namespace App\Services\Medication\Followups;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Explicit offsets disambiguate the repeated NZ hour; local gaps are rejected. */
final class FollowupTime
{
    public static function parse(string $value, string $field = 'again_at'): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ValidationException::withMessages([$field => 'Choose a date and time with the Pacific/Auckland timezone.']);
        }
        try {
            $instant = CarbonImmutable::parse($value);
            $local = $instant->setTimezone('Pacific/Auckland');
            $providedWall = substr($value, 0, 16);
            if (! str_ends_with($value, 'Z') && $local->format('Y-m-d\TH:i') !== $providedWall) {
                throw new \InvalidArgumentException('Invalid NZ wall time/offset.');
            }

            return $instant->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'That time does not exist in Pacific/Auckland. Choose the correct timezone offset.']);
        }
    }
}
