<?php

namespace App\Services\Emar;

use App\Models\ClientMedication;
use App\Services\MarScheduleService;
use Carbon\Carbon;

/**
 * Detects medication omissions — scheduled doses that were never recorded
 * ("blank MAR slots"). A blank slot is a medication error in CQC's view
 * (NICE SC1), but the audit feed otherwise only lists records that exist, so
 * an omission is invisible. This reconstructs each active medication's expected
 * schedule from its frequency/dose_times (the same MarScheduleService the live
 * /meds/today board uses) and flags any past slot with no recorded
 * administration, emitting synthetic `omission` events for the audit feed.
 *
 * Scope/limits (honest, bounded): active, non-PRN medications only — a since-
 * ceased medication's historical schedule is not reconstructed. The window is
 * clamped to a recent lookback (most actionable for inspectors) and the result
 * count is capped to protect payload/render cost. Deep-history reconciliation
 * would need a persisted projection table (audit-plan G8).
 */
class MarOmissionService
{
    /** Dose statuses that count as "recorded" — a slot with one of these is not an omission. */
    private const RECORDED_STATUSES = ['given', 'refused', 'withheld', 'missed'];

    /**
     * Default lookback when no explicit `from` is given. Kept short: a blank MAR
     * slot is most actionable while recent, and a tight window keeps the unified
     * feed readable rather than flooding it with omissions on data-sparse sites.
     */
    private const DEFAULT_LOOKBACK_DAYS = 7;

    /** Never scan further back than this, regardless of the requested window. */
    private const MAX_LOOKBACK_DAYS = 31;

    /** Hard cap on emitted omission events (bounds payload + keeps the feed usable). */
    private const MAX_RESULTS = 200;

    public function __construct(private readonly MarScheduleService $schedule) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function omissionsForRange(?Carbon $from = null, ?Carbon $to = null, ?int $clientId = null): array
    {
        $tz = $this->schedule->workerTimezone();
        $now = Carbon::now($tz);

        // Clamp the window: never look back past MAX_LOOKBACK_DAYS, never look
        // into the future, and default to a recent lookback when unspecified.
        $end = ($to ? $to->copy()->timezone($tz) : $now->copy())->min($now);
        $hardFloor = $now->copy()->subDays(self::MAX_LOOKBACK_DAYS)->startOfDay();
        $defaultFloor = $now->copy()->subDays(self::DEFAULT_LOOKBACK_DAYS)->startOfDay();
        $start = ($from ? $from->copy()->timezone($tz)->startOfDay() : $defaultFloor)->max($hardFloor);

        if ($start->gt($end)) {
            return [];
        }

        $medications = ClientMedication::query()
            ->active()
            ->where('is_prn', false)
            ->where(fn ($q) => $q->whereNotNull('dose_times')->orWhereNotNull('frequency'))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with('client:id,first_name,last_name')
            ->get();

        if ($medications->isEmpty()) {
            return [];
        }

        $clientIds = $medications->pluck('client_id')->filter()->unique()->values()->all();
        // Single query for every administration that could match a slot in the window.
        $bySlot = $this->schedule->administrationsForWindow($clientIds, $start, $end);

        $events = [];

        foreach ($medications as $med) {
            $clientName = $med->client
                ? trim($med->client->first_name.' '.$med->client->last_name)
                : 'Unknown';

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                foreach ($this->schedule->scheduledTimesForDate($med, $day) as $scheduled) {
                    // Only past slots inside the window are omissions.
                    if ($scheduled->gte($now) || $scheduled->lt($start) || $scheduled->gt($end)) {
                        continue;
                    }

                    $administration = $bySlot->get(
                        $this->schedule->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                    );

                    if ($administration && in_array($administration->status, self::RECORDED_STATUSES, true)) {
                        continue; // a real record exists — not an omission
                    }

                    $events[] = [
                        'id' => 'omission_'.$med->id.'_'.$scheduled->copy()->utc()->format('YmdHi'),
                        'event_type' => 'omission',
                        'timestamp' => $scheduled->copy()->utc()->toIso8601String(),
                        'description' => "{$med->name} dose due {$scheduled->format('H:i')} not recorded for {$clientName}",
                        'performed_by' => null,
                        'client_id' => $med->client_id,
                        'client_name' => $clientName,
                        'details' => [
                            'medication' => $med->name,
                            'dose' => $med->dosage,
                            'scheduled_for' => $scheduled->toIso8601String(),
                        ],
                    ];

                    if (count($events) >= self::MAX_RESULTS) {
                        return $events;
                    }
                }
            }
        }

        return $events;
    }
}
