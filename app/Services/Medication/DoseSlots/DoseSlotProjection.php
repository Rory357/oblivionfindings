<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The read side of the dose-slot projection (P01 foundation C4): one
 * definition of a scheduled dose and its state for every screen and report.
 * Read by the eMAR dashboard and the /dashboard widget (C6a); other
 * screens move onto it in later C6 steps.
 *
 * Live state is worked out in SQL against a bound `now` — never the database
 * clock, never stored — so it can't go stale:
 * - recorded: the outcome (given, refused, withheld, missed, away);
 * - away: due while the person is away by a record that says so — checked
 *   in at respite at another Site, from the stay's actual start until its
 *   discharge (DoseAwaySources; C7) — read live, so withdrawing the record
 *   makes the dose owed again. A dose due before the person left is owed. A
 *   recorded outcome always wins;
 * - self_managed: the person takes it themselves (not chased);
 * - pending_check: due while a change to the order waited for its check,
 *   with nothing recorded ("Waiting for the order check") — doses can't be
 *   recorded against an order awaiting verification yet (until P04);
 * - not_due: before the window opens;
 * - due: inside the window (both ends included);
 * - late: the window has ended with nothing recorded, still today (NZ);
 * - not_recorded: the window ended with nothing recorded on an earlier day.
 *
 * Numbers follow P09's definitions: a dose counts once, on its NZ day, once
 * its window has ended; Away and self-managed doses are never due; a zero
 * denominator is "not applicable" (null), never 0 % or 100 %. A dose waiting
 * for the order check is never due, overdue or not recorded: it is its own
 * number (Main, 2 Oct; back to normal counting once P04 lets staff record
 * against the version in effect during a check).
 *
 * The window per order comes from DoseWindowResolver (time-critical
 * overrides included). Superseded slots are never read.
 */
final class DoseSlotProjection
{
    public const STATE_NOT_DUE = 'not_due';

    public const STATE_DUE = 'due';

    public const STATE_LATE = 'late';

    public const STATE_NOT_RECORDED = 'not_recorded';

    public const STATE_SELF_MANAGED = 'self_managed';

    public const STATE_PENDING_CHECK = 'pending_check';

    /** Away: a recorded absence (withheld, away reason) or a live away period (C7). */
    public const STATE_AWAY = 'away';

    /** Every state a slot can read as: its recorded outcome, else its live state. */
    public const STATES = [
        'given', 'refused', 'withheld', 'missed', self::STATE_AWAY,
        self::STATE_NOT_DUE, self::STATE_DUE, self::STATE_LATE, self::STATE_NOT_RECORDED, self::STATE_SELF_MANAGED,
        self::STATE_PENDING_CHECK,
    ];

    private const GROUPS = ['nz_date', 'client_id'];

    private readonly DoseSlotCoverage $coverage;

    public function __construct(private readonly DoseWindowResolver $windows, ?DoseSlotCoverage $coverage = null)
    {
        $this->coverage = $coverage ?? new DoseSlotCoverage;
    }

    /**
     * Which days a read starting on $from can show (C5): the projection
     * holds days from live generation, and back to a completed backfill. A
     * period starting earlier carries "Not available before {date}".
     *
     * @return array{available_from: string, complete: bool, notice: string|null}
     */
    public function coverage(string $from, ?CarbonImmutable $now = null): array
    {
        if (! DoseSlotRules::isCalendarDate($from)) {
            throw new InvalidArgumentException('An NZ calendar day is Y-m-d.');
        }

        return $this->coverage->forPeriod($from, $now);
    }

    /**
     * Every live slot on the NZ days $from..$to (inclusive) for the scope,
     * with its window and state, ordered by due time. Rows naming a
     * controlled medicine are concealed for readers without controlled view:
     * the row stays, the order and record references don't.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(DoseSlotReaderScope $scope, string $from, string $to, CarbonImmutable $now): Collection
    {
        return DB::query()
            ->fromSub($this->base($scope, $from, $to, $now), 'slots')
            ->orderBy('due_at')
            ->orderBy('client_id')
            ->orderBy('client_medication_id')
            ->orderBy('ordered_time')
            ->get()
            ->map(function (object $row) use ($scope): array {
                $concealed = (bool) $row->controlled && ! $scope->canViewControlled;

                return [
                    'id' => (int) $row->id,
                    'client_id' => (int) $row->client_id,
                    'client_medication_id' => $concealed ? null : (int) $row->client_medication_id,
                    'schedule_version_id' => $concealed || $row->schedule_version_id === null ? null : (int) $row->schedule_version_id,
                    'nz_date' => substr((string) $row->nz_date, 0, 10),
                    'ordered_time' => (string) $row->ordered_time,
                    'due_at' => self::iso($row->due_at),
                    'window_opens_at' => self::iso($row->window_opens_at),
                    'window_ends_at' => self::iso($row->window_ends_at),
                    'state' => (string) $row->state,
                    'outcome' => $row->outcome,
                    'outcome_administration_id' => $concealed || $row->outcome_administration_id === null ? null : (int) $row->outcome_administration_id,
                    'outcome_at' => $row->outcome_at === null ? null : self::iso($row->outcome_at),
                    'recorded_late' => (bool) $row->recorded_late,
                    'controlled' => (bool) $row->controlled,
                    'concealed' => $concealed,
                    'order_change_pending' => (bool) $row->order_change_pending,
                    'dst_adjustment' => $row->dst_adjustment,
                    'last_day' => (bool) $row->last_day,
                    // Rebuilt from history by the backfill, not generated live.
                    'reconstructed' => (bool) $row->reconstructed,
                    // The record the person is away by (C7), when that is the state.
                    'away' => $row->outcome === null && $row->state === self::STATE_AWAY
                        ? DoseAwaySources::refOf($row)
                        : null,
                ];
            });
    }

    /**
     * P09's dose numbers over the period. Controlled doses are included for
     * every reader (P09 Q6).
     *
     * @return array<string, int|float|null>
     */
    public function totals(DoseSlotReaderScope $scope, string $from, string $to, CarbonImmutable $now): array
    {
        $row = DB::query()
            ->fromSub($this->base($scope, $from, $to, $now), 'slots')
            ->selectRaw($this->aggregateSql())
            ->first();

        return self::totalsFrom($row);
    }

    /**
     * The same numbers per NZ day or per person.
     *
     * @return array<string|int, array<string, int|float|null>>
     */
    public function totalsBy(string $group, DoseSlotReaderScope $scope, string $from, string $to, CarbonImmutable $now): array
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("Dose totals group by one of: ".implode(', ', self::GROUPS).'.');
        }

        return DB::query()
            ->fromSub($this->base($scope, $from, $to, $now), 'slots')
            ->selectRaw("{$group} as grp, ".$this->aggregateSql())
            ->groupBy($group)
            ->orderBy($group)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                ($group === 'nz_date' ? substr((string) $row->grp, 0, 10) : (int) $row->grp) => self::totalsFrom($row),
            ])
            ->all();
    }

    /**
     * How many slots are in each live state — the day view's counts (C6):
     * every slot, whether or not its window has ended. Recorded slots count
     * by outcome (given, refused, withheld, missed, away); the rest by state
     * (not_due, due, late, not_recorded, self_managed). Controlled doses are
     * included for every reader, as in totals(). Optionally per NZ day or
     * per person.
     *
     * @return array<string, int>|array<string|int, array<string, int>>
     */
    public function stateCounts(DoseSlotReaderScope $scope, string $from, string $to, CarbonImmutable $now, ?string $group = null): array
    {
        if ($group !== null && ! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException('Dose state counts group by one of: '.implode(', ', self::GROUPS).'.');
        }

        $empty = array_fill_keys(self::STATES, 0);
        $query = DB::query()
            ->fromSub($this->base($scope, $from, $to, $now), 'slots')
            ->selectRaw('state, COUNT(*) as n')
            ->groupBy('state');
        if ($group === null) {
            $counts = $empty;
            foreach ($query->get() as $row) {
                $counts[(string) $row->state] = ($counts[(string) $row->state] ?? 0) + (int) $row->n;
            }

            return $counts;
        }

        $grouped = [];
        foreach ($query->selectRaw("{$group} as grp")->groupBy($group)->get() as $row) {
            $key = $group === 'nz_date' ? substr((string) $row->grp, 0, 10) : (int) $row->grp;
            $grouped[$key] ??= $empty;
            $grouped[$key][(string) $row->state] = ($grouped[$key][(string) $row->state] ?? 0) + (int) $row->n;
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * The scoped slots with their window and state, as a subquery.
     */
    public function base(DoseSlotReaderScope $scope, string $from, string $to, CarbonImmutable $now): Builder
    {
        if (! DoseSlotRules::isCalendarDate($from) || ! DoseSlotRules::isCalendarDate($to)) {
            throw new InvalidArgumentException('NZ calendar days are Y-m-d.');
        }

        $nowUtc = $now->utc()->format('Y-m-d H:i:s');
        $today = $now->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        $scoped = DB::table('medication_dose_slots as s')
            ->whereBetween('s.nz_date', [$from, $to])
            ->whereNull('s.superseded_at');
        if ($scope->clientIds !== null) {
            $scope->clientIds === []
                ? $scoped->whereRaw('1 = 0')
                : $scoped->whereIn('s.client_id', $scope->clientIds);
        }

        [$before, $after] = $this->windowSql((clone $scoped)->distinct()->pluck('s.client_medication_id'));
        $opens = "DATE_SUB(s.due_at, INTERVAL {$before} MINUTE)";
        $ends = "DATE_ADD(s.due_at, INTERVAL {$after} MINUTE)";

        // The slots, each with the record (if any) the person is away by
        // (C7), read live; the window and state are worked out over them.
        $slots = $scoped->select([
            's.id', 's.client_id', 's.client_medication_id', 's.schedule_version_id', 's.nz_date', 's.ordered_time',
            's.due_at', 's.controlled', 's.order_change_pending', 's.dst_adjustment', 's.self_managed', 's.last_day', 's.reconstructed',
            's.outcome', 's.outcome_administration_id', 's.outcome_at',
        ]);
        foreach (DoseAwaySources::sql() as $column => $sql) {
            $slots->selectRaw("({$sql}) as {$column}");
        }

        return DB::query()->fromSub($slots, 's')
            ->select('s.*')
            ->selectRaw("{$opens} as window_opens_at")
            ->selectRaw("{$ends} as window_ends_at")
            ->selectRaw("CASE WHEN ? > {$ends} THEN 1 ELSE 0 END as window_ended", [$nowUtc])
            ->selectRaw("CASE WHEN s.outcome = 'given' AND s.outcome_at > {$ends} THEN 1 ELSE 0 END as recorded_late")
            ->selectRaw(
                "CASE
                    WHEN s.outcome IS NOT NULL THEN s.outcome
                    WHEN ".DoseAwaySources::awaySql('s')." THEN '".self::STATE_AWAY."'
                    WHEN s.self_managed = 1 THEN '".self::STATE_SELF_MANAGED."'
                    WHEN s.order_change_pending = 1 THEN '".self::STATE_PENDING_CHECK."'
                    WHEN ? < {$opens} THEN '".self::STATE_NOT_DUE."'
                    WHEN ? <= {$ends} THEN '".self::STATE_DUE."'
                    WHEN s.nz_date = ? THEN '".self::STATE_LATE."'
                    ELSE '".self::STATE_NOT_RECORDED."'
                END as state",
                [$nowUtc, $nowUtc, $today],
            );
    }

    /**
     * Minutes before/after for each order as SQL integer expressions: one
     * literal when every order shares a window, else a CASE by order.
     *
     * @param  Collection<int, mixed>  $orderIds
     * @return array{0: string, 1: string}
     */
    private function windowSql(Collection $orderIds): array
    {
        $default = $this->windows->forOrder(0);
        $groups = [];
        foreach ($orderIds as $orderId) {
            $window = $this->windows->forOrder((int) $orderId);
            if ($window->beforeMinutes === $default->beforeMinutes && $window->afterMinutes === $default->afterMinutes) {
                continue;
            }
            $groups[$window->beforeMinutes.':'.$window->afterMinutes][] = (int) $orderId;
        }

        $before = (string) (int) $default->beforeMinutes;
        $after = (string) (int) $default->afterMinutes;
        if ($groups === []) {
            return [$before, $after];
        }

        $beforeCase = 'CASE';
        $afterCase = 'CASE';
        foreach ($groups as $minutes => $ids) {
            [$groupBefore, $groupAfter] = array_map('intval', explode(':', (string) $minutes));
            $in = implode(',', array_map('intval', $ids));
            $beforeCase .= " WHEN s.client_medication_id IN ({$in}) THEN {$groupBefore}";
            $afterCase .= " WHEN s.client_medication_id IN ({$in}) THEN {$groupAfter}";
        }

        return ["({$beforeCase} ELSE {$before} END)", "({$afterCase} ELSE {$after} END)"];
    }

    private function aggregateSql(): string
    {
        // Counted: the window has ended, and the dose is a staff dose (not
        // self-managed) that was not away — recorded as away, or due in an
        // away period (C7) — and isn't waiting for the order check.
        $pendingCheck = "state = '".self::STATE_PENDING_CHECK."'";
        $away = "state = '".self::STATE_AWAY."'";
        $counted = "window_ended = 1 AND self_managed = 0 AND NOT ({$away}) AND NOT ({$pendingCheck})";
        $open = "window_ended = 0 AND self_managed = 0 AND outcome IS NULL AND NOT ({$away}) AND NOT ({$pendingCheck})";

        return implode(', ', [
            'COUNT(*) as slots',
            "SUM(CASE WHEN {$counted} THEN 1 ELSE 0 END) as due",
            "SUM(CASE WHEN {$counted} AND outcome = 'given' THEN 1 ELSE 0 END) as given",
            "SUM(CASE WHEN {$counted} AND outcome = 'refused' THEN 1 ELSE 0 END) as refused",
            "SUM(CASE WHEN {$counted} AND outcome = 'withheld' THEN 1 ELSE 0 END) as withheld",
            "SUM(CASE WHEN {$counted} AND outcome = 'missed' THEN 1 ELSE 0 END) as missed",
            "SUM(CASE WHEN {$counted} AND outcome IS NULL THEN 1 ELSE 0 END) as not_recorded",
            "SUM(CASE WHEN {$counted} AND recorded_late = 1 THEN 1 ELSE 0 END) as recorded_late",
            "SUM(CASE WHEN {$away} THEN 1 ELSE 0 END) as away",
            "SUM(CASE WHEN self_managed = 1 THEN 1 ELSE 0 END) as self_managed",
            "SUM(CASE WHEN {$open} AND state = '".self::STATE_DUE."' THEN 1 ELSE 0 END) as due_now",
            "SUM(CASE WHEN {$open} AND state = '".self::STATE_NOT_DUE."' THEN 1 ELSE 0 END) as not_yet_due",
            "SUM(CASE WHEN state = '".self::STATE_LATE."' THEN 1 ELSE 0 END) as late_today",
            "SUM(CASE WHEN {$pendingCheck} THEN 1 ELSE 0 END) as pending_check",
        ]);
    }

    /**
     * @return array<string, int|float|null>
     */
    private static function totalsFrom(?object $row): array
    {
        $keys = ['slots', 'due', 'given', 'refused', 'withheld', 'missed', 'not_recorded', 'recorded_late', 'away', 'self_managed', 'due_now', 'not_yet_due', 'late_today', 'pending_check'];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = (int) ($row?->{$key} ?? 0);
        }
        // "Given as due" (P09): null when nothing was due — "Not applicable".
        $totals['given_rate'] = $totals['due'] > 0 ? round($totals['given'] / $totals['due'] * 100, 1) : null;

        return $totals;
    }

    private static function iso(mixed $utc): string
    {
        return CarbonImmutable::parse((string) $utc, 'UTC')->toIso8601String();
    }
}
