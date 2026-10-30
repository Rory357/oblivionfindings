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

    public static function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    /** The UTC instant a worker-timezone calendar day (Y-m-d) starts. */
    public static function dayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->utc();
    }

    /** The UTC instant a worker-timezone calendar day (Y-m-d) ends. */
    public static function dayEndUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->endOfDay()->utc();
    }

    /** The start (UTC) of the last $days worker-timezone days, today included. */
    public static function lastDaysStartUtc(int $days): CarbonImmutable
    {
        return self::today()->subDays(max(1, $days) - 1)->utc();
    }

    /** The worker-timezone calendar day (Y-m-d) of a stored UTC instant. */
    public static function localDate(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->timezone(self::timezone())->toDateString();
    }

    /** A stored UTC instant shown in the worker timezone, e.g. "2026-10-09 08:05 NZDT". */
    public static function display(DateTimeInterface $value, string $format = 'Y-m-d H:i T'): string
    {
        return CarbonImmutable::instance($value)->timezone(self::timezone())->format($format);
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
