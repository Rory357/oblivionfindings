<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Services\MarScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The doses that are overdue now, for the overdue job and the Control Room
 * overdue alert (P01 foundation C6f): the same doses and states as Meds
 * today, My Day and the MAR. A dose is overdue once its window
 * (DoseWindowResolver, as the projection holds it) has ended with nothing
 * recorded — late today, or not recorded on an earlier day. A dose waiting
 * for the order check, a self-managed dose, a dose recorded as missed and a
 * dose owed before its order was entered never is. People whose
 * administration alerts are suppressed have none.
 *
 * Looks back over yesterday and today (NZ days), as the overdue job always
 * has: an omission from earlier than that is the audit's, not an alert's.
 * Only days the projection holds count (DoseSlotCoverage) — a day before it
 * began isn't guessed at from the order as it is now.
 */
final class OverdueDoses
{
    public function __construct(
        private readonly ScheduledDoseStates $states,
        private readonly MarScheduleService $schedule,
        private readonly DoseSlotCoverage $coverage,
    ) {}

    /**
     * @param  list<int>|null  $clientIds  null: everyone at a Site
     * @return Collection<int, array{key: string, client_id: int, site_id: int|null, order: ClientMedication, due_at: Carbon, window_ends_at: Carbon, state: string}>
     */
    public function at(Carbon $now, ?array $clientIds = null): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        $now = $now->copy()->timezone($this->schedule->workerTimezone());

        return $this->between($now->copy()->subDay()->startOfDay(), $now, $clientIds, null);
    }

    /**
     * Of these doses (keys), the ones still overdue now, whatever their day —
     * so an alert for a dose that has aged out of the lookback stays open
     * until the dose is recorded or no longer owed.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function stillOverdue(array $keys, Carbon $now): array
    {
        $doses = collect($keys)
            ->map(fn (string $key): ?array => preg_match('/^(\d+)@(.+)$/', $key, $parts) === 1
                ? ['order_id' => (int) $parts[1], 'due_at' => Carbon::parse($parts[2])]
                : null)
            ->filter();
        if ($doses->isEmpty()) {
            return [];
        }

        $timezone = $this->schedule->workerTimezone();
        $from = $doses->min(fn (array $dose): Carbon => $dose['due_at'])->copy()->timezone($timezone)->startOfDay();
        $overdue = $this->between($from, $now->copy()->timezone($timezone), null, $doses->pluck('order_id')->unique()->values()->all())
            ->pluck('key')
            ->all();

        return array_values(array_intersect($keys, $overdue));
    }

    /**
     * @param  list<int>|null  $clientIds
     * @param  list<int>|null  $orderIds
     * @return Collection<int, array{key: string, client_id: int, site_id: int|null, order: ClientMedication, due_at: Carbon, window_ends_at: Carbon, state: string}>
     */
    private function between(Carbon $from, Carbon $now, ?array $clientIds, ?array $orderIds): Collection
    {
        $timezone = $this->schedule->workerTimezone();
        // Only days the projection holds.
        $held = Carbon::parse($this->coverage->availableFrom(CarbonImmutable::instance($now)), $timezone)->startOfDay();
        if ($held->greaterThan($from)) {
            $from = $held;
        }

        $orders = ClientMedication::query()
            ->where(fn ($orders) => $orders->active()->orWhere(fn ($waiting) => $waiting->awaitingVerification()))
            ->where('is_prn', false)
            ->where(function ($query) {
                $query->whereNotNull('dose_times')
                    ->orWhereNotNull('frequency');
            })
            ->when($clientIds !== null, fn ($query) => $query->whereIn('client_id', $clientIds))
            ->when($orderIds !== null, fn ($query) => $query->whereKey($orderIds))
            ->whereHas('client', fn ($client) => $client
                ->whereNotNull('site_id')
                ->where(fn ($alerts) => $alerts->whereNull('suppress_med_admin_alerts')->orWhere('suppress_med_admin_alerts', false)))
            ->with('client:id,first_name,last_name,site_id,service_context_id,suppress_med_admin_alerts')
            ->get();
        if ($orders->isEmpty()) {
            return collect();
        }

        $doses = $this->states->dosesBetween($orders, $from, $now, $now);
        $records = $this->schedule->administrationsForWindow(
            $orders->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            $from,
            $now,
        );

        $overdue = collect();
        foreach ($orders as $order) {
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                $record = $records->get($this->schedule->slotKey((int) $order->client_id, (int) $order->id, $dose['due_at']));
                if (ScheduledDoseStates::statusFor($dose, $record?->status) !== 'overdue') {
                    continue;
                }

                $overdue->push([
                    'key' => self::key((int) $order->id, $dose['due_at']),
                    'client_id' => (int) $order->client_id,
                    'site_id' => $order->client?->site_id === null ? null : (int) $order->client->site_id,
                    'order' => $order,
                    'due_at' => $dose['due_at']->copy()->timezone($timezone),
                    'window_ends_at' => $dose['window_ends_at']->copy()->timezone($timezone),
                    'state' => $dose['state'],
                ]);
            }
        }

        return $overdue->sortBy(fn (array $dose): string => $dose['due_at']->toIso8601String())->values();
    }

    /** A dose's identity across runs: its order and due instant (UTC minute). */
    public static function key(int $orderId, Carbon $dueAt): string
    {
        return $orderId.'@'.$dueAt->copy()->utc()->format('Y-m-d\TH:i\Z');
    }
}
