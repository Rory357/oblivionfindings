<?php

namespace App\Services\Medication\Controlled;

use App\Support\NewZealandLocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Operational timestamps are Auckland wall times; UTC Z identifies an absolute instant. */
final class ControlledLocalTime
{
    public function parse(?string $value, string $field, bool $defaultNow = false, bool $allowFuture = false): CarbonImmutable
    {
        if (blank($value) && $defaultNow) {
            return CarbonImmutable::now('UTC');
        }
        $instant = (new NewZealandLocalTime)->parse($value, $field);
        if (! $allowFuture && $instant->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages([$field => 'This event cannot be recorded in the future.']);
        }

        return $instant;
    }
}
