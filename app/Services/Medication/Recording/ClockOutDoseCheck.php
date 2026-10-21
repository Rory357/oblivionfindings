<?php

namespace App\Services\Medication\Recording;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\MedicationScopeDecisionService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * eMAR audit Lane C5 (EA-019): the doses a worker leaving now still owes an
 * outcome for — read from the same dose-slot projection as Meds today.
 *
 * A dose counts only when all of these hold:
 * - it is due by the clock-out instant (its due time has come) or its window
 *   has already ended, with nothing recorded;
 * - it falls inside the shift: from its scheduled start, and before its
 *   scheduled end (a dose due at or after the end belongs to the next
 *   shift — the sleepover's 08:00 dose);
 * - it is staff's to give now: not Away, not self-managed, not waiting for
 *   the order check;
 * - the worker may record it: a person they have current work with and may
 *   open, and a controlled medicine only with both controlled capabilities.
 *
 * A worker sent home at 11:30 is therefore never blocked by the 12:00 and
 * 14:00 doses, and nobody is blocked by a person or medicine they can't open.
 */
final class ClockOutDoseCheck
{
    public function __construct(
        private readonly ScheduledDoseStates $states,
        private readonly MarScheduleService $schedule,
        private readonly MedicationScopeDecisionService $scope,
    ) {}

    public function outstanding(User $worker, Shift $shift, CarbonInterface $at): int
    {
        if (! $shift->starts_at || ! $shift->ends_at) {
            return 0;
        }
        $timezone = $this->schedule->workerTimezone();
        $now = Carbon::instance($at)->timezone($timezone);
        $start = $shift->starts_at->copy()->timezone($timezone);
        $end = $shift->ends_at->copy()->timezone($timezone);
        $until = $now->lessThan($end) ? $now : $end;
        if ($until->lessThan($start)) {
            return 0;
        }

        $clientIds = $this->peopleTheWorkerMayRecordFor($worker, $shift, $now);
        if ($clientIds === []) {
            return 0;
        }
        $includeControlled = $worker->canDo('medications.controlled.view') && $worker->canDo('medications.controlled.record');
        $orders = ScheduledDoseStates::listedOrders($clientIds)
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
            ->get();
        if ($orders->isEmpty()) {
            return 0;
        }

        $doses = $this->states->dosesBetween($orders, $start, $until, $now);
        $records = $this->schedule->administrationsForWindow($clientIds, $start, $until);
        $outstanding = 0;
        foreach ($orders as $order) {
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                $dueAt = $dose['due_at'];
                if ($dueAt->lessThan($start) || $dueAt->greaterThanOrEqualTo($end) || $dueAt->greaterThan($now)) {
                    continue;
                }
                if (! in_array($dose['state'], [DoseSlotProjection::STATE_DUE, DoseSlotProjection::STATE_LATE, DoseSlotProjection::STATE_NOT_RECORDED], true)) {
                    continue;
                }
                $record = $records->get($this->schedule->slotKey((int) $order->client_id, (int) $order->id, $dueAt));
                if ($dose['outcome'] === null && ! in_array($record?->status, ScheduledDoseStates::RECORDED_STATUSES, true)) {
                    $outstanding++;
                }
            }
        }

        return $outstanding;
    }

    /**
     * The shift's person (every shift names one), when the worker has
     * current work with them (the same rule as recording) and may open
     * their medicines.
     *
     * @return list<int>
     */
    private function peopleTheWorkerMayRecordFor(User $worker, Shift $shift, Carbon $at): array
    {
        if (! $shift->client_id || ! $worker->canDo('medications.view')) {
            return [];
        }
        $current = $this->scope->clientIdsWithCurrentAuthority($worker, [(int) $shift->client_id], $at->copy());

        return Client::query()->whereKey($current)->get()
            ->filter(fn (Client $client): bool => Gate::forUser($worker)->allows('viewMedications', $client))
            ->map(fn (Client $client): int => (int) $client->id)
            ->values()
            ->all();
    }
}
