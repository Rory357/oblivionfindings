<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Services\Calendar\Contracts\FinanceObligationProvider;
use Illuminate\Support\Carbon;

/**
 * Shared helpers for finance obligation providers — ISO date formatting and the
 * common due/overdue/settled status vocabulary.
 */
abstract class ObligationProvider implements FinanceObligationProvider
{
    /**
     * Normalise a date to an ISO calendar date (Y-m-d), or null.
     */
    protected function isoDate(Carbon|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return ($date instanceof Carbon ? $date : Carbon::parse($date))->toDateString();
    }

    /**
     * Status for a dated payable/receivable: a settled row reports its settled
     * label; an unsettled row is "overdue" once its due date has passed, else
     * "due".
     */
    protected function dueStatus(Carbon $due, bool $settled, string $settledLabel = 'paid'): string
    {
        if ($settled) {
            return $settledLabel;
        }

        return $due->startOfDay()->lt(Carbon::today()) ? 'overdue' : 'due';
    }
}
