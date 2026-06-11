<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class WorkerClock
{
    public static function toUtc(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::parse(
            $value,
            config('app.worker_timezone', 'Pacific/Auckland')
        )->utc();
    }
}
