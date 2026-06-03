<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Services\Sites\Calendar\Contracts\CalendarObligationProvider;
use Illuminate\Support\Carbon;

/**
 * Shared helpers for obligation providers — site/owner normalisation, ISO
 * formatting, range checks and the scheduled/overdue/completed status rule.
 */
abstract class ObligationProvider implements CalendarObligationProvider
{
    protected function siteArray($site): ?array
    {
        return $site ? ['id' => $site->id, 'name' => $site->name, 'type' => $site->type] : null;
    }

    protected function ownerArray($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    protected function isoDate(\DateTimeInterface|Carbon|string $date): string
    {
        return Carbon::parse($date)->toIso8601String();
    }

    /**
     * Whether a date falls within the (date-granular) range.
     */
    protected function inRange(?Carbon $date, Carbon $start, Carbon $end): bool
    {
        if (! $date) {
            return false;
        }
        $d = $date->toDateString();

        return $d >= $start->toDateString() && $d <= $end->toDateString();
    }

    /**
     * Standard obligation status: done wins, else overdue if the due date has
     * passed, else scheduled.
     */
    protected function dueStatus(?Carbon $due, bool $completed): string
    {
        if ($completed) {
            return 'completed';
        }

        if ($due && $due->lt(Carbon::today())) {
            return 'overdue';
        }

        return 'scheduled';
    }
}
