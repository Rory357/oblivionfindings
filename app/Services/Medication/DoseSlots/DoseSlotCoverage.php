<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\MedicationDoseSlotBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which New Zealand days the dose-slot projection holds (P01 foundation C5).
 *
 * Live generation holds every day from the day it began. A completed
 * backfill that reaches the day before extends that back to its first day.
 * A read that starts earlier says so — "Not available before 1 October
 * 2025" — rather than counting days the projection doesn't hold as nothing
 * due.
 */
final class DoseSlotCoverage
{
    /** The NZ day live generation began (its first slot), or null before it ever ran. */
    public function liveFrom(): ?string
    {
        $first = DB::table('medication_dose_slots')->where('reconstructed', false)->min('generated_at');

        return $first === null ? null : $this->nzDate(CarbonImmutable::parse((string) $first, 'UTC'));
    }

    /**
     * The first NZ day the projection holds: back to the first day of a
     * completed backfill joined to live generation, else the day live
     * generation began (today, before it has run).
     */
    public function availableFrom(?CarbonImmutable $now = null): string
    {
        $live = $this->liveFrom() ?? $this->nzDate(($now ?? CarbonImmutable::now())->utc());
        $dayBefore = CarbonImmutable::parse($live, 'UTC')->subDay()->toDateString();

        $backfilled = MedicationDoseSlotBackfill::query()
            ->where('status', MedicationDoseSlotBackfill::STATUS_COMPLETED)
            ->where('to_date', '>=', $dayBefore)
            ->where('from_date', '<', $live)
            ->min('from_date');

        return $backfilled === null ? $live : substr((string) $backfilled, 0, 10);
    }

    /**
     * For a read starting on the NZ day $from: where the projection's days
     * start, whether it holds the whole period, and the notice to show when
     * it doesn't.
     *
     * @return array{available_from: string, complete: bool, notice: string|null}
     */
    public function forPeriod(string $from, ?CarbonImmutable $now = null): array
    {
        $available = $this->availableFrom($now);
        $complete = $from >= $available;

        return [
            'available_from' => $available,
            'complete' => $complete,
            'notice' => $complete
                ? null
                : 'Not available before '.CarbonImmutable::parse($available, 'UTC')->format('j F Y'),
        ];
    }

    private function nzDate(CarbonImmutable $instant): string
    {
        return $instant->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
    }
}
