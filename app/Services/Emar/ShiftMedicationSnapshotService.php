<?php

namespace App\Services\Emar;

use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Computes the live medication picture for a single rostering shift's window —
 * the "Medications this shift" lens surfaced in the eMAR handover wizard + detail
 * dialog. Reuses the same battle-tested pipeline the MAR chart / meds board use
 * ({@see EnhancedMarService::build()}) plus {@see MarOmissionService} for
 * omissions, so there is no second medication-state implementation to drift.
 *
 * This is computed ON DEMAND (one shift at a time via the handover endpoint),
 * never per-handover at index time — build() is heavy, so fanning it across the
 * 300-row handover list would be an N+1. Callers pass a single Shift.
 */
class ShiftMedicationSnapshotService
{
    public function __construct(
        private readonly EnhancedMarService $marService,
        private readonly MarScheduleService $scheduleService,
        private readonly MarOmissionService $omissionService,
    ) {}

    /**
     * Build the snapshot for an outgoing shift, or null when the shift has no
     * client / no start time (nothing to chart). All counts/lists are scoped to
     * the shift's [starts_at, ends_at] window.
     *
     * @return array<string, mixed>|null
     */
    public function forShift(Shift $shift, bool $includeControlled = false): ?array
    {
        // Full client — EnhancedMarService::build() reads many client columns.
        $shift->loadMissing('client');
        $client = $shift->client;

        if (! $client || ! $shift->starts_at) {
            return null;
        }

        $tz = $this->scheduleService->workerTimezone();
        $windowStart = $shift->starts_at->copy()->timezone($tz);
        $windowEnd = ($shift->ends_at ?? $shift->starts_at->copy()->addHours(8))->copy()->timezone($tz);
        $date = $windowStart->copy()->startOfDay();

        // One full-day MAR build for this client, then narrow to the shift window.
        $mar = $this->marService->build($client, $date, null, $shift->id, $includeControlled);
        $scheduled = collect(Arr::get($mar, 'scheduled', []))
            ->filter(function (array $row) use ($windowStart, $windowEnd): bool {
                $sf = Arr::get($row, 'scheduled_for');

                if (! $sf) {
                    return false;
                }

                return Carbon::parse($sf)->betweenIncluded($windowStart, $windowEnd);
            })
            ->values();

        // Still-actionable doses for the incoming shift (missed_auto is counted
        // separately as an omission, not as "due", so the two stay disjoint).
        $pending = $scheduled->filter(fn (array $r) => in_array(
            Arr::get($r, 'schedule_state'),
            ['due', 'due_soon', 'upcoming', 'late'],
            true,
        ));
        $given = $scheduled->filter(fn (array $r) => Arr::get($r, 'schedule_state') === 'completed'
            && Arr::get($r, 'administration.status') !== 'refused');
        $missed = $scheduled->filter(fn (array $r) => Arr::get($r, 'schedule_state') === 'missed_auto');
        $refused = $scheduled->filter(fn (array $r) => Arr::get($r, 'administration.status') === 'refused');
        $cdDue = $pending->filter(fn (array $r) => (bool) Arr::get($r, 'medication.controlled_drug'));

        [$startUtc, $endUtc] = [$windowStart->copy()->utc(), $windowEnd->copy()->utc()];
        $prnGivenQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $client->id)
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->whereBetween('administered_at', [$startUtc, $endUtc]);
        $reviewsOutstandingQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $client->id)
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->whereBetween('administered_at', [$startUtc, $endUtc])
            ->whereDoesntHave('prnEffectiveness');
        if (! $includeControlled) {
            $prnGivenQuery->whereHas('medication', fn ($query) => $query->where('controlled_drug', false));
            $reviewsOutstandingQuery->whereHas('medication', fn ($query) => $query->where('controlled_drug', false));
        }
        $prnGiven = $prnGivenQuery->count();
        $reviewsOutstanding = $reviewsOutstandingQuery->count();

        $omissions = $this->omissionService->omissionsForRange(
            $windowStart->copy(),
            $windowEnd->copy(),
            (int) $client->id,
            $includeControlled,
        );

        return [
            'window' => [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
            ],
            'counts' => [
                'due' => $pending->count(),
                'given' => $given->count(),
                'missed' => $missed->count(),
                'refused' => $refused->count(),
                'cd_due' => $cdDue->count(),
                'prn_given' => $prnGiven,
                'reviews_outstanding' => $reviewsOutstanding,
                'omissions' => count($omissions),
            ],
            // Pre-fill source for the wizard's "Medications due" list.
            'due' => $pending->map(fn (array $r) => [
                'name' => (string) Arr::get($r, 'medication.name', 'Medication'),
                'time' => (string) Arr::get($r, 'scheduled_time', ''),
                'state' => (string) Arr::get($r, 'schedule_state', 'due'),
                'controlled' => (bool) Arr::get($r, 'medication.controlled_drug'),
            ])->values()->all(),
            'alerts' => $this->alerts($scheduled, Arr::get($mar, 'prn', []), Arr::get($mar, 'attention_alerts', [])),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Low-stock + clinical attention alerts for the shift's medications, in the
     * stacked-banner shape the handover UIs render.
     *
     * @param  Collection<int, array<string, mixed>>  $scheduled
     * @param  array<int, array<string, mixed>>  $prn
     * @param  array<int, array<string, mixed>>  $attention
     * @return array<int, array{kind: string, tone: string, message: string}>
     */
    private function alerts($scheduled, array $prn, array $attention): array
    {
        $alerts = [];
        $seenStock = [];

        foreach (array_merge($scheduled->all(), $prn) as $row) {
            $medId = Arr::get($row, 'medication.id');
            $onHand = Arr::get($row, 'medication.stock.on_hand');
            $reorder = Arr::get($row, 'medication.stock.reorder_level');

            if ($medId === null || isset($seenStock[$medId]) || $reorder === null || $onHand === null) {
                continue;
            }

            $seenStock[$medId] = true;

            if ($onHand <= $reorder) {
                $alerts[] = [
                    'kind' => 'stock',
                    'tone' => 'warning',
                    'message' => sprintf('%s low stock — %s on hand (reorder at %s).', Arr::get($row, 'medication.name', 'Medication'), $onHand, $reorder),
                ];
            }
        }

        foreach ($attention as $alert) {
            $alerts[] = [
                'kind' => 'attention',
                'tone' => 'critical',
                'message' => (string) (Arr::get($alert, 'title') ?: Arr::get($alert, 'detail', 'Medication alert')),
            ];
        }

        return $alerts;
    }
}
