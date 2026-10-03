<?php

namespace App\Services\Medication;

use App\Models\ClientMedication;
use App\Models\MedicationDoseSlot;
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
            ->with(['client:id,first_name,last_name', 'versions' => fn ($q) => $q->whereIn('client_id', $clientIds)])
            ->get();
        $states = app(ScheduledDoseStates::class);
        $doses = $states->withAwayReasons($states->dosesOn($orders, $day, $now), auth()->user());
        $schedule = app(MarScheduleService::class);
        $board = app(MedsBoardPayloadService::class);
        $timezone = $schedule->workerTimezone();
        $controlledSlots = MedicationDoseSlot::query()->whereIn('client_id', $clientIds)->whereDate('nz_date', $day->toDateString())->whereNull('superseded_at')->where('controlled', true)->pluck('client_medication_id')->all();
        $rows = [];
        foreach ($orders as $order) {
            // A replacement has a new retained order row. Its predecessor's
            // immutable version contains the predecessor's clinical wording.
            $clinical = $order->versions->first(fn ($v) => (int) $v->version_number === (int) $order->version) ?? $order;
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                $due = $dose['due_at'];
                $record = $bySlot->get($schedule->slotKey((int) $order->client_id, (int) $order->id, $due));
                $controlled = (bool) $order->controlled_drug || (bool) $clinical->controlled_drug || in_array((int) $order->id, $controlledSlots, true);
                if (! $includeControlled && $controlled) {
                    $hidden['total']++;
                    $hidden['overdue'] += ScheduledDoseStates::statusFor($dose, $record?->status) === 'overdue' ? 1 : 0;
                    continue;
                }
                $status = ScheduledDoseStates::statusFor($dose, $record?->status);
                $rows[] = [
                    'key' => $order->id.':'.$due->copy()->utc()->format('YmdHi'),
                    'client_id' => $order->client_id, 'client_name' => $order->client?->full_name ?? '',
                    'medication_id' => $order->id, 'medication_name' => $clinical->name,
                    'dose' => $clinical->dosage, 'route' => $clinical->route,
                    'is_controlled' => $controlled, 'requires_witness' => $controlled || (bool) $clinical->witness_required,
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
        $to = $day->copy()->endOfDay()->utc();
        return ClientMedication::withTrashed()->whereIn('client_id', $clientIds)->where('is_prn', true)
            ->where(function ($orders) use ($day, $from, $to, $clientIds) {
                $orders->where(function ($q) use ($day, $from, $to) {
                    $q->where('created_at', '<=', $to)
                        ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $day->toDateString()))
                        ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day->toDateString()))
                        ->where(fn ($q) => $q->whereNull('ceased_at')->orWhere('ceased_at', '>', $from))
                        ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $from))
                        ->where(fn ($q) => $q->where('approval_status', 'verified')->orWhereNull('approval_status'))
                        ->where(fn ($q) => $q->whereNull('verified_at')->orWhere('verified_at', '<=', $to));
                })->orWhereHas('administrations', fn ($q) => $q->effectiveClinicalEvidence()->whereIn('client_id', $clientIds)->whereBetween('administered_at', [$from, $to]));
            })->orderBy('name')->get()->map(fn ($med) => [
                'id' => $med->id, 'client_id' => $med->client_id, 'name' => $med->name,
                'dose' => $med->dosage, 'route' => $med->route, 'prn_reason' => $med->prn_reason,
                'max_per_day' => $med->max_per_day, 'min_hours_between' => $med->min_hours_between_doses,
                'is_controlled' => (bool) $med->controlled_drug, 'requires_witness' => (bool) $med->controlled_drug || (bool) $med->witness_required,
                'given_last_24h' => 0, 'remaining_today' => null, 'last_given_at' => null, 'next_allowed_at' => null,
                'near_limit' => false, 'over_limit' => false, 'interval_blocked' => false,
                'last_given_label' => null, 'next_allowed_label' => null, 'form' => $med->form, 'instructions' => $med->instructions,
            ])->all();
    }
}
