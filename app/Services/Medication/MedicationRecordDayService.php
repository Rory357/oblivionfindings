<?php

namespace App\Services\Medication;

use App\Models\ClientMedication;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Historical person readers use held obligations, including retained stopped orders. */
final class MedicationRecordDayService
{
    public function scheduled(array $clientIds, Carbon $day, Carbon $now, Collection $bySlot, bool $includeControlled, array &$hidden): array
    {
        $hidden = ['total' => 0, 'overdue' => 0];
        $orders = ClientMedication::withTrashed()->whereIn('client_id', $clientIds)
            ->whereIn('id', MedicationDoseSlot::query()->whereIn('client_id', $clientIds)->whereDate('nz_date', $day->toDateString())->whereNull('superseded_at')->select('client_medication_id'))
            ->with('client:id,first_name,last_name')
            ->get();
        $checked = $this->checkedVersions($orders, $clientIds, $day->copy()->addDay()->utc());
        $states = app(ScheduledDoseStates::class);
        $doses = $states->withAwayReasons($states->dosesOn($orders, $day, $now), auth()->user());
        $schedule = app(MarScheduleService::class);
        $board = app(MedsBoardPayloadService::class);
        $timezone = $schedule->workerTimezone();
        $controlledSlots = MedicationDoseSlot::query()->whereIn('client_id', $clientIds)->whereDate('nz_date', $day->toDateString())->whereNull('superseded_at')->where('controlled', true)->pluck('client_medication_id')->all();
        $rows = [];
        foreach ($orders as $order) {
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                $due = $dose['due_at'];
                $clinical = $this->versionAt($order, $checked, $due);
                $record = $bySlot->get($schedule->slotKey((int) $order->client_id, (int) $order->id, $due));
                $controlled = (bool) $order->controlled_drug || (bool) $clinical?->controlled_drug || in_array((int) $order->id, $controlledSlots, true);
                if (! $includeControlled && $controlled) {
                    $hidden['total']++;
                    $hidden['overdue'] += ScheduledDoseStates::statusFor($dose, $record?->status) === 'overdue' ? 1 : 0;

                    continue;
                }
                $status = ScheduledDoseStates::statusFor($dose, $record?->status);
                $rows[] = [
                    'key' => $order->id.':'.$due->copy()->utc()->format('YmdHi'),
                    'client_id' => $order->client_id, 'client_name' => $order->client?->full_name ?? '',
                    'medication_id' => $order->id, 'order_version_id' => $clinical?->id,
                    'medication_name' => $clinical?->name ?? 'Medicine — historical wording not recorded',
                    'dose' => $clinical?->dosage ?? 'Not recorded', 'route' => $clinical?->route ?? 'Not recorded',
                    'is_controlled' => $controlled, 'requires_witness' => $controlled || (bool) $clinical?->witness_required,
                    'scheduled_for' => $due->toIso8601String(), 'time' => $due->format('H:i'),
                    'round_label' => $board->roundLabelFor($due), 'status' => $status,
                    'state' => $dose['state'], 'window_opens_at' => $dose['window_opens_at']->toIso8601String(),
                    'support_mode' => $dose['support_mode'] ?? 'staff_given',
                    'window_ends_at' => $dose['window_ends_at']->toIso8601String(),
                    'away_reason' => $status === 'away' ? $dose['away_reason'] : null,
                    'recorded' => $record ? $board->recordedPayload($record, $timezone) : null,
                    'mar_url' => '/emar/mar?client_id='.$order->client_id.'&date='.$day->toDateString(),
                ];
            }
        }

        return $rows;
    }

    public function prn(array $clientIds, Carbon $day): array
    {
        $from = $day->copy()->utc();
        $until = $day->copy()->addDay()->utc();

        // Current PRN/date fields can have changed since this day. Retain the
        // order first, then use its checked prescription at each held time.
        $orders = ClientMedication::withTrashed()->whereIn('client_id', $clientIds)
            ->where(function ($orders) use ($from, $until, $clientIds) {
                $orders->where(function ($q) use ($from, $until) {
                    $q->where('created_at', '<', $until)
                        ->where(fn ($q) => $q->whereNull('ceased_at')->orWhere('ceased_at', '>', $from))
                        ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $from))
                        ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>', $from));
                })->orWhereHas('administrations', fn ($q) => $q->effectiveClinicalEvidence()
                    ->whereIn('client_id', $clientIds)
                    ->whereColumn('client_medication_administrations.client_id', 'client_medications.client_id')
                    ->where('administered_at', '>=', $from)->where('administered_at', '<', $until));
            })->orderBy('id')->get();
        $checked = $this->checkedVersions($orders, $clientIds, $until);
        $rows = [];
        foreach ($orders as $order) {
            $first = $order->created_at && $order->created_at->greaterThan($from) ? $order->created_at : $from;
            $changes = $checked->get($order->id, collect())
                ->filter(fn ($r) => $r->checked_at->greaterThan($first))
                ->pluck('checked_at')->push($first)->sortBy(fn ($at) => $at->timestamp)
                ->unique(fn ($at) => $at->toIso8601String())->values();
            foreach ($changes as $index => $at) {
                $clinical = $this->versionAt($order, $checked, $at);
                if ($clinical ? ! $clinical->is_prn : ! $order->is_prn) {
                    continue;
                }
                if ($clinical && (($clinical->start_date && $clinical->start_date->toDateString() > $day->toDateString())
                    || ($clinical->end_date && $clinical->end_date->toDateString() < $day->toDateString()))) {
                    continue;
                }
                $controlled = (bool) $order->controlled_drug || (bool) $clinical?->controlled_drug;
                $rows[] = [
                    'id' => $order->id, 'client_id' => $order->client_id,
                    'key' => $order->id.':'.($clinical?->id ?? 'unrecorded').':'.$at->copy()->utc()->format('YmdHis'),
                    'history_from' => $at->toIso8601String(), 'history_until' => ($changes[$index + 1] ?? $until)->toIso8601String(),
                    'name' => $clinical?->name ?? 'Medicine — historical wording not recorded',
                    'dose' => $clinical?->dosage ?? 'Not recorded', 'route' => $clinical?->route ?? 'Not recorded',
                    'prn_reason' => $clinical?->prn_reason, 'max_per_day' => $clinical?->max_per_day,
                    'min_hours_between' => $clinical?->min_hours_between_doses,
                    'is_controlled' => $controlled, 'requires_witness' => $controlled || (bool) $clinical?->witness_required,
                    'given_last_24h' => 0, 'remaining_today' => null, 'last_given_at' => null, 'next_allowed_at' => null,
                    'near_limit' => false, 'over_limit' => false, 'interval_blocked' => false,
                    'last_given_label' => null, 'next_allowed_label' => null,
                    'form' => $clinical?->form, 'instructions' => $clinical?->instructions,
                ];
            }
        }

        return collect($rows)->sortBy(fn ($row) => mb_strtolower($row['name']))->values()->all();
    }

    /** Same checked-at rule as P09's historical dose rows; proposals never supply wording. */
    private function checkedVersions(Collection $orders, array $clientIds, Carbon $until): Collection
    {
        $owners = $orders->keyBy('id');

        return MedicationOrderRevision::query()->canonicalVersion()
            ->whereIn('client_medication_id', $orders->pluck('id'))->whereIn('client_id', $clientIds)
            ->whereIn('status', ['checked', 'checked_alone'])->whereNotNull('checked_at')
            ->where('checked_at', '<', $until)->with('version')
            ->orderByDesc('checked_at')->orderByDesc('id')->get()
            ->filter(fn ($r) => (int) $owners->get($r->client_medication_id)?->client_id === (int) $r->client_id)
            ->groupBy('client_medication_id');
    }

    private function versionAt(ClientMedication $order, Collection $checked, Carbon $at): ?MedicationOrderVersion
    {
        return $checked->get($order->id, collect())->first(fn ($r) => (int) $r->client_id === (int) $order->client_id
            && $r->checked_at->lessThanOrEqualTo($at))?->version;
    }
}
