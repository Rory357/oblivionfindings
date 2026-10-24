<?php

namespace App\Support\Incidents;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class IncidentOccurrencePeriod
{
    public static function timezone(): string
    {
        return (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
    }

    /** Validated dates describe whole worker calendar days, including DST. */
    public static function apply(Builder $query, ?string $from, ?string $to, string $column = 'occurred_at'): Builder
    {
        $timezone = self::timezone();
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if ($to !== null && $to !== '') {
            $query->where($column, '<', CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc());
        }

        return $query;
    }
}
