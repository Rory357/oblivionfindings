<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\Medication\DoseSlots\DoseTimeParser;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MarScheduleService
{
    public function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    public function dateFromInput(?string $date = null, ?Carbon $fallback = null): Carbon
    {
        $timezone = $this->workerTimezone();

        if ($date !== null && trim($date) !== '') {
            return Carbon::parse($date, $timezone)->timezone($timezone)->startOfDay();
        }

        return ($fallback ?: Carbon::now($timezone))->copy()->timezone($timezone)->startOfDay();
    }

    public function parseWorkerDateTime(string $value): Carbon
    {
        $value = trim($value);
        $timezone = $this->workerTimezone();

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            return Carbon::parse($value)->timezone($timezone);
        }

        return Carbon::parse($value, $timezone);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function utcDayWindow(Carbon $date): array
    {
        $localDate = $date->copy()->timezone($this->workerTimezone())->startOfDay();

        return [
            $localDate->copy()->utc(),
            $localDate->copy()->endOfDay()->utc(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function utcSlotWindow(Carbon $scheduled): array
    {
        return [
            $scheduled->copy()->utc()->subMinute(),
            $scheduled->copy()->utc()->addMinute(),
        ];
    }

    /**
     * Pre-fetch every administration that could match a dose slot inside the
     * worker-tz window [$from, $to] in a SINGLE query, keyed for in-memory
     * matching by {@see slotKey()}. Callers iterate generated slots and look up
     * `$map->get($this->slotKey(...))` instead of issuing one administration
     * query per slot (the N+1 that re-ran on every /my-day & /meds/today
     * 60s refresh).
     *
     * The DB window spans the full local day(s) the [$from, $to] range covers
     * (it may straddle two worker-local days near midnight), expressed in UTC.
     * Ordered by id so a same-slot collision keeps the latest row (matching the
     * old per-slot `->latest('id')->first()`).
     *
     * @param  array<int, int>  $clientIds
     * @return Collection<string, ClientMedicationAdministration>
     */
    public function administrationsForWindow(array $clientIds, Carbon $from, Carbon $to): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        [$dayStartUtc] = $this->utcDayWindow($from);
        [, $dayEndUtc] = $this->utcDayWindow($to);

        return ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('client_id', $clientIds)
            ->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
            ->orderBy('id')
            ->get(['id', 'client_id', 'client_medication_id', 'scheduled_for', 'status'])
            ->keyBy(fn (ClientMedicationAdministration $administration) => $this->slotKey(
                (int) $administration->client_id,
                (int) $administration->client_medication_id,
                $this->rawScheduledForUtc($administration),
            ));
    }

    /**
     * Stable key used on both sides of the in-memory slot↔administration match:
     * the generated slot Carbon and the stored administration are both reduced
     * to their UTC minute, so they collide iff they are the same dose slot.
     */
    public function slotKey(int $clientId, int $medicationId, Carbon $scheduled): string
    {
        return $clientId.':'.$medicationId.':'.$scheduled->copy()->utc()->format('Y-m-d H:i');
    }

    /**
     * Read an administration's `scheduled_for` back as a true UTC Carbon from
     * the raw column value — avoiding the Eloquent accessor, which can
     * re-introduce the worker-tz offset (see house timezone convention).
     */
    private function rawScheduledForUtc(ClientMedicationAdministration $administration): Carbon
    {
        $raw = $administration->getRawOriginal('scheduled_for');

        return $raw
            ? Carbon::parse((string) $raw, 'UTC')
            : Carbon::createFromTimestamp(0, 'UTC');
    }

    public function windowBeforeMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.window_before_minutes')->value('value')
            ?? config('medications.mar.window_before_minutes', 30));
    }

    public function windowAfterMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.window_after_minutes')->value('value')
            ?? config('medications.mar.window_after_minutes', 60));
    }

    public function dueSoonMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.due_soon_minutes')->value('value')
            ?? config('medications.mar.due_soon_minutes', 60));
    }

    /**
     * Build scheduled dose times for a medication on a given date.
     *
     * We intentionally keep this heuristic-based because the app currently stores frequency as a free-text field.
     * Supported inputs:
     * - "08:00" / "8:00" / "08:00, 20:00"
     * - "8am" / "8 pm" / "8am, 12pm, 6pm"
     * - keywords: morning, noon, afternoon, evening, night
     */
    public function scheduledTimesForDate(ClientMedication $medication, Carbon $date): array
    {
        $date = $date->copy()->timezone($this->workerTimezone())->startOfDay();

        if (! $medication->active) {
            return [];
        }

        // PRN meds have no fixed schedule.
        if ($medication->is_prn) {
            return [];
        }

        // If medication has start/end date constraints.
        if ($medication->start_date && $date->toDateString() < $medication->start_date->toDateString()) {
            return [];
        }
        if ($medication->end_date && $date->toDateString() > $medication->end_date->toDateString()) {
            return [];
        }

        $times = DoseTimeParser::parse($medication->dose_times, $medication->frequency);

        if ($times === []) {
            return [];
        }

        return array_map(fn ($t) => $date->copy()->setTimeFromTimeString($t), $times);
    }

    public function windowForScheduled(Carbon $scheduled): array
    {
        $start = $scheduled->copy()->subMinutes($this->windowBeforeMinutes());
        $end = $scheduled->copy()->addMinutes($this->windowAfterMinutes());

        return [$start, $end];
    }

    public function statusForDose(?Carbon $now, Carbon $scheduled, ?array $administration): array
    {
        $now = $now ? $now->copy() : now();
        [$wStart, $wEnd] = $this->windowForScheduled($scheduled);

        // Already recorded
        if ($administration) {
            $adminAt = isset($administration['administered_at']) && $administration['administered_at']
                ? Carbon::parse($administration['administered_at'])
                : null;
            $lateMinutes = null;
            if ($adminAt) {
                $diff = $scheduled->diffInMinutes($adminAt, false);
                $lateMinutes = $diff > 0 ? $diff : 0;
            }

            return [
                'state' => 'completed',
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => $lateMinutes,
            ];
        }

        // Not recorded yet
        if ($now->lessThan($wStart)) {
            $mins = $now->diffInMinutes($scheduled, false);
            $state = ($mins <= $this->dueSoonMinutes()) ? 'due_soon' : 'upcoming';

            return [
                'state' => $state,
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => null,
            ];
        }

        if ($now->betweenIncluded($wStart, $wEnd)) {
            return [
                'state' => 'due',
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => null,
            ];
        }

        return [
            'state' => 'late',
            'window_start' => $wStart,
            'window_end' => $wEnd,
            'late_minutes' => $wEnd->diffInMinutes($now),
        ];
    }
}
