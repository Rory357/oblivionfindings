<?php

namespace App\Domain\Referrals\Services;

use App\Support\ZonedLocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ReferralDates
{
    public static function parse(string $value, string $field): CarbonImmutable
    {
        try {
            return app(ZonedLocalTime::class)->parse($value, 'Pacific/Auckland');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Choose a valid, unambiguous Pacific/Auckland date and time. Daylight-saving changes can make a time occur twice or not at all.']);
        }
    }
}
