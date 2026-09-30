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

    /**
     * Start of today in the worker timezone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
    }

    /**
     * Whole calendar days from today in the worker timezone to the date's
     * calendar day: 0 today, negative once it has passed.
     */
    public static function daysUntil(DateTimeInterface $date): int
    {
        return (int) CarbonImmutable::parse(self::today()->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($date->format('Y-m-d'), 'UTC'));
    }
}
