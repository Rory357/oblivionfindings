<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\MedicationDoseSlot;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseTimingSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The scheduled doses of orders on one NZ day, each with its live state, for
 * the screens that list doses one by one (P01 foundation C6b: Meds today, the
 * MAR schedule and EnhancedMarService's MAR rows).
 *
 * Where the dose-slot projection holds the day — from the day its coverage
 * starts to the end of live generation (today + 2 NZ days) — the doses are
 * the projection's slots: the version in effect then, nothing owed before
 * the order was entered, due at the slot's due time (on the day the clocks
 * go forward a 02:30 dose is due at 03:00, not 03:30), with the projection's
 * state. For a day it doesn't hold, or an order it has never generated (one
 * written around the model), the doses come from the order as it is now and
 * the same rules decide their state.
 *
 * States: the slot's outcome when recorded, else pending_check (due while
 * the order's change waits for its check), not_due, due (inside the window,
 * both ends included), late (window ended, today) or not_recorded (window
 * ended, an earlier day). Windows come from DoseWindowResolver; "shows as due
 * soon" from DoseTimingSettings.
 */
final class ScheduledDoseStates
{
    /** Record outcomes a list shows as the dose's status (Missed is "Missed (recorded)"). */
    public const RECORDED_STATUSES = ['given', 'refused', 'withheld', 'missed'];

    public function __construct(
        private readonly DoseSlotProjection $projection,
        private readonly DoseWindowResolver $windows,
        private readonly DoseSlotCoverage $coverage,
        private readonly MarScheduleService $schedule,
    ) {}

    /**
     * The doses each order owes on the NZ day of $date, oldest due first.
     *
     * @param  iterable<ClientMedication>  $orders  scheduled (non-PRN) orders the caller may show
     * @return array<int, list<array{due_at: Carbon, ordered_time: string, state: string, outcome: string|null, window_opens_at: Carbon, window_ends_at: Carbon, due_soon: bool, order_change_pending: bool}>>
     *                                                                                                                                                                                                         keyed by order id
     */
    public function dosesOn(iterable $orders, Carbon $date, Carbon $now): array
    {
        $timezone = $this->schedule->workerTimezone();
        $day = $date->copy()->timezone($timezone)->startOfDay();
        $nzDate = $day->toDateString();
        $nowUtc = CarbonImmutable::instance($now)->utc();
        $orders = Collection::make($orders)->keyBy(fn (ClientMedication $order): int => (int) $order->id);
        if ($orders->isEmpty()) {
            return [];
        }

        $known = $this->holdsDay($nzDate, $nowUtc)
            ? MedicationDoseSlot::query()
                ->whereIn('client_medication_id', $orders->keys()->all())
                ->distinct()
                ->pluck('client_medication_id')
                ->map(fn ($id): int => (int) $id)
                ->flip()
                ->all()
            : [];
        $slots = $known === []
            ? collect()
            : $this->projection->rows(
                DoseSlotReaderScope::internal($orders->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values()->all()),
                $nzDate,
                $nzDate,
                $nowUtc,
            )->groupBy('client_medication_id');

        $doses = [];
        foreach ($orders as $orderId => $order) {
            if (isset($known[$orderId])) {
                $doses[$orderId] = $slots->get($orderId, collect())
                    ->map(fn (array $slot): array => $this->fromSlot($slot, $nowUtc, $timezone))
                    ->values()
                    ->all();

                continue;
            }

            // Not held by the projection: the order as it is now, same rules.
            if (! $order->isAdministrable()) {
                $doses[$orderId] = [];

                continue;
            }
            $doses[$orderId] = array_map(
                fn (Carbon $scheduled): array => $this->fromOrder($orderId, $scheduled, $nzDate, $nowUtc, $timezone),
                $this->schedule->scheduledTimesForDate($order, $day),
            );
            usort($doses[$orderId], fn (array $a, array $b): int => $a['due_at'] <=> $b['due_at']);
        }

        return $doses;
    }

    /**
     * The projection holds the NZ day: from where its coverage starts to the
     * last day live generation keeps ahead.
     */
    public function holdsDay(string $nzDate, CarbonImmutable $now): bool
    {
        $today = $now->setTimezone($this->schedule->workerTimezone())->toDateString();
        $lastGenerated = CarbonImmutable::parse($today, 'UTC')->addDays(DoseSlotGenerator::HORIZON_DAYS)->toDateString();

        return $nzDate <= $lastGenerated && $nzDate >= $this->coverage->availableFrom($now);
    }

    /**
     * A dose's status in the lists frontline screens share — Meds today, the
     * MAR schedule, My Day — when no record row is matched to it: overdue
     * once its window has ended; due from when it shows as due soon through
     * its window; pending_check while its order's change waits for the
     * check; else upcoming. A recorded dose reads as its outcome (Away as
     * withheld).
     *
     * @param  array{state: string, outcome: string|null, due_soon: bool}  $dose
     */
    public static function listStatus(array $dose): string
    {
        if ($dose['outcome'] !== null) {
            return $dose['outcome'] === 'away' ? 'withheld' : $dose['outcome'];
        }

        return match ($dose['state']) {
            DoseSlotProjection::STATE_PENDING_CHECK => DoseSlotProjection::STATE_PENDING_CHECK,
            DoseSlotProjection::STATE_DUE => 'due',
            DoseSlotProjection::STATE_LATE, DoseSlotProjection::STATE_NOT_RECORDED => 'overdue',
            DoseSlotProjection::STATE_NOT_DUE => $dose['due_soon'] ? 'due' : 'upcoming',
            default => 'upcoming',
        };
    }

    /**
     * The list status of a dose with the record row matched to its slot, if
     * any: the record's outcome (given, refused, withheld or missed), else
     * listStatus(). The one mapping Meds today and My Day share.
     *
     * @param  array{state: string, outcome: string|null, due_soon: bool}  $dose
     */
    public static function statusFor(array $dose, ?string $recordedStatus): string
    {
        return in_array($recordedStatus, self::RECORDED_STATUSES, true)
            ? $recordedStatus
            : self::listStatus($dose);
    }

    /**
     * The doses each order owes on every NZ day from $from's to $to's,
     * oldest due first.
     *
     * @param  iterable<ClientMedication>  $orders
     * @return array<int, list<array{due_at: Carbon, ordered_time: string, state: string, outcome: string|null, window_opens_at: Carbon, window_ends_at: Carbon, due_soon: bool, order_change_pending: bool}>>
     */
    public function dosesBetween(iterable $orders, Carbon $from, Carbon $to, Carbon $now): array
    {
        $timezone = $this->schedule->workerTimezone();
        $orders = Collection::make($orders);
        $doses = [];
        $day = $from->copy()->timezone($timezone)->startOfDay();
        $last = $to->copy()->timezone($timezone)->startOfDay();
        while ($day->lessThanOrEqualTo($last)) {
            foreach ($this->dosesOn($orders, $day, $now) as $orderId => $orderDoses) {
                $doses[$orderId] = array_merge($doses[$orderId] ?? [], $orderDoses);
            }
            $day->addDay();
        }

        return $doses;
    }

    /**
     * Shows as due soon: not yet in its window, but within the "shows as due
     * soon" minutes before its dose time (P11 Rounds & timing).
     */
    public function isDueSoon(CarbonImmutable $dueAt, CarbonImmutable $now): bool
    {
        return $now->greaterThanOrEqualTo($dueAt->subMinutes(app(DoseTimingSettings::class)->dueSoonMinutes()));
    }

    /**
     * @param  array<string, mixed>  $slot  a DoseSlotProjection row
     * @return array{due_at: Carbon, ordered_time: string, state: string, outcome: string|null, window_opens_at: Carbon, window_ends_at: Carbon, due_soon: bool, order_change_pending: bool}
     */
    private function fromSlot(array $slot, CarbonImmutable $now, string $timezone): array
    {
        $dueAt = CarbonImmutable::parse($slot['due_at'])->utc();

        return [
            'due_at' => Carbon::instance($dueAt)->timezone($timezone),
            'ordered_time' => (string) $slot['ordered_time'],
            'state' => (string) $slot['state'],
            'outcome' => $slot['outcome'],
            'window_opens_at' => Carbon::parse($slot['window_opens_at'])->timezone($timezone),
            'window_ends_at' => Carbon::parse($slot['window_ends_at'])->timezone($timezone),
            'due_soon' => $slot['state'] === DoseSlotProjection::STATE_NOT_DUE && $this->isDueSoon($dueAt, $now),
            'order_change_pending' => (bool) $slot['order_change_pending'],
        ];
    }

    /**
     * @return array{due_at: Carbon, ordered_time: string, state: string, outcome: string|null, window_opens_at: Carbon, window_ends_at: Carbon, due_soon: bool, order_change_pending: bool}
     */
    private function fromOrder(int $orderId, Carbon $scheduled, string $nzDate, CarbonImmutable $now, string $timezone): array
    {
        $dueAt = CarbonImmutable::instance($scheduled)->utc();
        $window = $this->windows->forOrder($orderId);
        $opens = $window->opensAt($dueAt);
        $ends = $window->closesAt($dueAt);
        $today = $now->setTimezone($timezone)->toDateString();
        $state = match (true) {
            $now->lessThan($opens) => DoseSlotProjection::STATE_NOT_DUE,
            $now->lessThanOrEqualTo($ends) => DoseSlotProjection::STATE_DUE,
            $nzDate === $today => DoseSlotProjection::STATE_LATE,
            default => DoseSlotProjection::STATE_NOT_RECORDED,
        };

        return [
            'due_at' => $scheduled->copy()->timezone($timezone),
            'ordered_time' => $scheduled->copy()->timezone($timezone)->format('H:i'),
            'state' => $state,
            'outcome' => null,
            'window_opens_at' => Carbon::instance($opens)->timezone($timezone),
            'window_ends_at' => Carbon::instance($ends)->timezone($timezone),
            'due_soon' => $state === DoseSlotProjection::STATE_NOT_DUE && $this->isDueSoon($dueAt, $now),
            'order_change_pending' => false,
        ];
    }
}
