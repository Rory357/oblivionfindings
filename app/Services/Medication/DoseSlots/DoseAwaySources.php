<?php

namespace App\Services\Medication\DoseSlots;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Models\ClientLeaveRequest;
use App\Models\RespiteStay;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where a person is away from home, so a scheduled dose due then reads as
 * Away (P01 C7; Main, 2–3 Oct). Away needs positive evidence that the person
 * is actually elsewhere, at the dose's due time:
 *
 * - a respite stay at another Site, from its actual start (written by the
 *   system at admission; the stay must have been checked in) until its
 *   actual end once discharged (written by the system at discharge). A stay
 *   checked in and not discharged is away from its start onward — a planned
 *   end ("extended") is not a discharge. The booking's planned start and end
 *   are never read: a confirmed booking not yet checked in is not away, and
 *   an early discharge ends Away there and then. Respite at the person's own
 *   Site, or with no Site recorded, is not away;
 * - approved leave after its recorded actual departure, until actual return;
 * - a canonical hospital admission until its linked actual discharge.
 * Planned leave dates and legacy hospital types never establish presence.
 *
 * A dose due before the away period began is owed, overdue included: Away
 * never settles a dose whose window ended before the person left.
 * Excursions and outings are not away (the medicines go with the person).
 * Hospital takes precedence over respite and leave when valid periods overlap.
 *
 * Away is read live, never stored: withdraw or change the record and the dose
 * is owed again, evaluated normally. A recorded outcome always wins. Lists
 * show it as "Away · reason"; it is never due, late, overdue, badged or
 * alerted, and P09 counts it on its own.
 */
final class DoseAwaySources
{
    public const LEAVE = 'leave';

    public const RESPITE = 'respite';

    public const HOSPITAL = 'hospital';

    /** Leave that says the person is away: approved, or approved and since completed. */
    public const LEAVE_STATUSES = ['approved', 'completed'];

    /** A respite stay that has been checked in (an admitted-only stay hasn't). */
    public const STAY_STATUSES = ['active', 'extended', 'on_leave', 'discharged'];

    /** Compatibility opt-out; only explicit actual evidence ever counts. */
    public static function leaveCounts(): bool
    {
        return (bool) config('medications.away.from_leave', true);
    }

    /**
     * Each source's correlated subquery for a slot row (alias `s`, with
     * client_id, nz_date and due_at): the id of the record the person is away
     * by, or NULL. Keyed by the column the projection selects it as.
     *
     * @return array<string, string>
     */
    public static function sql(): array
    {
        $stays = "'".implode("','", self::STAY_STATUSES)."'";
        $leave = "'".implode("','", self::LEAVE_STATUSES)."'";

        return [
            'away_hospital_id' => "SELECT h.id FROM clinical_events h JOIN clients hc ON hc.id = h.client_id
                WHERE h.client_id = s.client_id AND h.deleted_at IS NULL
                  AND h.event_type = 'hospital_admission' AND h.hospital_admitted_at IS NOT NULL
                  AND h.hospital_admitted_at = h.occurred_at AND h.hospital_admitted_at <= s.due_at
                  AND NOT EXISTS (SELECT 1 FROM clinical_events d WHERE d.hospital_admission_id = h.id
                    AND d.client_id = h.client_id AND d.deleted_at IS NULL
                    AND d.event_type = 'hospital_discharge' AND d.hospital_discharged_at IS NOT NULL
                    AND d.hospital_discharged_at = d.occurred_at
                    AND d.hospital_discharged_at >= h.hospital_admitted_at AND d.hospital_discharged_at <= s.due_at)
                ORDER BY h.hospital_admitted_at, h.id LIMIT 1",
            'away_respite_id' => "SELECT st.id FROM respite_stays st
                JOIN respite_bookings rb ON rb.id = st.booking_id
                JOIN clients rc ON rc.id = st.client_id
                WHERE st.client_id = s.client_id AND st.deleted_at IS NULL AND rb.deleted_at IS NULL
                  AND st.status IN ({$stays})
                  AND st.actual_start IS NOT NULL AND st.actual_start <= s.due_at
                  AND (st.status <> 'discharged' OR (st.actual_end IS NOT NULL AND st.actual_end > s.due_at))
                  AND rb.location_id IS NOT NULL AND rc.site_id IS NOT NULL AND rb.location_id <> rc.site_id
                ORDER BY st.actual_start, st.id LIMIT 1",
            'away_leave_id' => self::leaveCounts()
                ? "SELECT l.id FROM client_leave_requests l
                    WHERE l.client_id = s.client_id AND l.deleted_at IS NULL AND l.status IN ({$leave})
                      AND l.approved_at IS NOT NULL AND l.approved_by IS NOT NULL AND l.withdrawn_at IS NULL
                      AND l.departed_at IS NOT NULL AND l.departed_at <= s.due_at
                      AND (l.returned_at IS NULL OR l.returned_at > s.due_at)
                    ORDER BY l.departed_at, l.id LIMIT 1"
                : 'SELECT NULL',
        ];
    }

    /** SQL: the slot row (alias $alias) is away by some source. */
    public static function awaySql(string $alias = 's'): string
    {
        return '('.implode(' OR ', array_map(
            fn (string $column): string => "{$alias}.{$column} IS NOT NULL",
            array_keys(self::sql()),
        )).')';
    }

    /**
     * The source and record id a slot row is away by, if any.
     *
     * @param  object|array<string, mixed>  $row
     * @return array{source: string, id: int}|null
     */
    public static function refOf(object|array $row): ?array
    {
        $row = (array) $row;
        foreach ([self::HOSPITAL => 'away_hospital_id', self::RESPITE => 'away_respite_id', self::LEAVE => 'away_leave_id'] as $source => $column) {
            if (($row[$column] ?? null) !== null) {
                return ['source' => $source, 'id' => (int) $row[$column]];
            }
        }

        return null;
    }

    /**
     * The reason each away ref reads as after "Away · ", for $viewer:
     * "Respite at Kōwhai House (since Mon 15 Jun, 3:05 pm)" — the house named
     * only to a reader who may access that Site, else "Respite at another
     * house" — or "On leave (since Mon 15 Jun, 7:00 am)". Batch-loaded; only the
     * surfaces that show the words ask for them.
     *
     * @param  iterable<array{source: string, id: int}|null>  $refs
     * @return array<string, string> keyed "source:id"
     */
    public function reasons(iterable $refs, ?User $viewer): array
    {
        $ids = [self::LEAVE => [], self::RESPITE => [], self::HOSPITAL => []];
        foreach ($refs as $ref) {
            if ($ref !== null && isset($ids[$ref['source']])) {
                $ids[$ref['source']][$ref['id']] = true;
            }
        }

        $reasons = [];
        if ($ids[self::HOSPITAL] !== []) {
            ClinicalEvent::query()->whereKey(array_keys($ids[self::HOSPITAL]))->get(['id', 'hospital_admitted_at'])
                ->each(function (ClinicalEvent $event) use (&$reasons): void {
                    $since = $event->getRawOriginal('hospital_admitted_at');
                    $reasons[self::HOSPITAL.':'.$event->id] = 'In hospital'.($since === null ? '' : ' (since '
                        .CarbonImmutable::parse((string) $since, 'UTC')->setTimezone(self::timezone())->format('D j M, g:i a').')');
                });
        }
        if ($ids[self::RESPITE] !== []) {
            $visibleSites = $viewer === null
                ? []
                : app(UserSiteAccessService::class)->accessibleSiteIds($viewer, ['clinical.accessAllSites', 'sites.viewAll']);
            RespiteStay::query()
                ->whereKey(array_keys($ids[self::RESPITE]))
                ->with('booking:id,location_id', 'booking.location:id,name')
                ->get(['id', 'booking_id', 'actual_start'])
                ->each(function (RespiteStay $stay) use (&$reasons, $visibleSites): void {
                    $siteId = $stay->booking?->location_id;
                    $where = $siteId !== null && in_array((int) $siteId, array_map('intval', $visibleSites), true) && $stay->booking?->location?->name
                        ? 'Respite at '.$stay->booking->location->name
                        : 'Respite at another house';
                    $since = $stay->getRawOriginal('actual_start');
                    $reasons[self::RESPITE.':'.$stay->id] = $where.($since === null ? '' : ' (since '.CarbonImmutable::parse((string) $since, 'UTC')
                        ->setTimezone(self::timezone())
                        ->format('D j M, g:i a').')');
                });
        }
        if ($ids[self::LEAVE] !== []) {
            ClientLeaveRequest::query()
                ->whereKey(array_keys($ids[self::LEAVE]))
                ->get(['id', 'departed_at', 'returned_at'])
                ->each(function (ClientLeaveRequest $leave) use (&$reasons): void {
                    $since = $leave->getRawOriginal('departed_at');
                    $reasons[self::LEAVE.':'.$leave->id] = 'On leave'.($since === null ? '' : ' (since '
                        .CarbonImmutable::parse((string) $since, 'UTC')->setTimezone(self::timezone())->format('D j M, g:i a').')');
                });
        }

        return $reasons;
    }

    /**
     * The away periods of these people overlapping the NZ days $from..$to, as
     * the projection reads them — for a day the projection doesn't hold.
     *
     * @param  array<int, int>  $clientIds
     * @return Collection<int, list<array{source: string, id: int, from: CarbonImmutable, until: CarbonImmutable|null}>> keyed by client id
     */
    public function periods(array $clientIds, string $from, string $to): Collection
    {
        if ($clientIds === []) {
            return collect();
        }
        $timezone = self::timezone();
        $dayStart = CarbonImmutable::parse($from, $timezone)->utc();
        $dayEnd = CarbonImmutable::parse($to, $timezone)->addDay()->utc();
        $periods = [];

        ClinicalEvent::query()->join('clients as hc', 'hc.id', '=', 'clinical_events.client_id')
            ->whereIn('clinical_events.client_id', $clientIds)
            ->where('event_type', 'hospital_admission')->whereNotNull('hospital_admitted_at')
            ->whereColumn('hospital_admitted_at', 'occurred_at')->where('hospital_admitted_at', '<', $dayEnd)
            ->with('hospitalDischarges')->orderBy('hospital_admitted_at')->orderBy('clinical_events.id')
            ->get(['clinical_events.*'])->each(function (ClinicalEvent $admission) use (&$periods, $dayStart): void {
                $end = $admission->hospitalDischarges->filter(fn (ClinicalEvent $d): bool => (int) $d->client_id === (int) $admission->client_id
                    && $d->hospital_discharged_at !== null
                    && $d->getRawOriginal('hospital_discharged_at') === $d->getRawOriginal('occurred_at')
                    && $d->hospital_discharged_at->greaterThanOrEqualTo($admission->hospital_admitted_at))
                    ->sortBy(fn (ClinicalEvent $d): string => (string) $d->getRawOriginal('occurred_at'))->first();
                $until = $end ? CarbonImmutable::parse((string) $end->getRawOriginal('occurred_at'), 'UTC') : null;
                if ($until === null || $until->greaterThan($dayStart)) {
                    $periods[(int) $admission->client_id][] = ['source' => self::HOSPITAL, 'id' => (int) $admission->id,
                        'from' => CarbonImmutable::parse((string) $admission->getRawOriginal('hospital_admitted_at'), 'UTC'), 'until' => $until];
                }
            });

        RespiteStay::query()
            ->join('respite_bookings as rb', 'rb.id', '=', 'respite_stays.booking_id')
            ->join('clients as rc', 'rc.id', '=', 'respite_stays.client_id')
            ->whereNull('rb.deleted_at')
            ->whereIn('respite_stays.client_id', $clientIds)
            ->whereIn('respite_stays.status', self::STAY_STATUSES)
            ->whereNotNull('respite_stays.actual_start')
            ->where('respite_stays.actual_start', '<', $dayEnd)
            ->where(fn ($open) => $open->where('respite_stays.status', '<>', 'discharged')
                ->orWhere('respite_stays.actual_end', '>', $dayStart))
            ->whereNotNull('rb.location_id')
            ->whereNotNull('rc.site_id')
            ->whereColumn('rb.location_id', '<>', 'rc.site_id')
            ->orderBy('respite_stays.actual_start')->orderBy('respite_stays.id')
            ->get(['respite_stays.id', 'respite_stays.client_id', 'respite_stays.status', 'respite_stays.actual_start', 'respite_stays.actual_end'])
            ->each(function (RespiteStay $stay) use (&$periods): void {
                $periods[(int) $stay->client_id][] = [
                    'source' => self::RESPITE,
                    'id' => (int) $stay->id,
                    'from' => CarbonImmutable::parse((string) $stay->getRawOriginal('actual_start'), 'UTC'),
                    'until' => $stay->status === 'discharged' && $stay->getRawOriginal('actual_end') !== null
                        ? CarbonImmutable::parse((string) $stay->getRawOriginal('actual_end'), 'UTC')
                        : null,
                ];
            });

        if (self::leaveCounts()) {
            ClientLeaveRequest::query()
                ->whereIn('client_id', $clientIds)
                ->whereIn('status', self::LEAVE_STATUSES)
                ->whereNotNull('approved_at')->whereNotNull('approved_by')->whereNull('withdrawn_at')
                ->whereNotNull('departed_at')->where('departed_at', '<', $dayEnd)
                ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>', $dayStart))
                ->orderBy('departed_at')->orderBy('id')
                ->get(['id', 'client_id', 'departed_at', 'returned_at'])
                ->each(function (ClientLeaveRequest $leave) use (&$periods): void {
                    $periods[(int) $leave->client_id][] = [
                        'source' => self::LEAVE,
                        'id' => (int) $leave->id,
                        'from' => CarbonImmutable::parse((string) $leave->getRawOriginal('departed_at'), 'UTC'),
                        'until' => $leave->getRawOriginal('returned_at') === null ? null
                            : CarbonImmutable::parse((string) $leave->getRawOriginal('returned_at'), 'UTC'),
                    ];
                });
        }

        return collect($periods);
    }

    /**
     * The first period (hospital, respite, then leave, as the projection) a dose due
     * at $dueAt falls in.
     *
     * @param  list<array{source: string, id: int, from: CarbonImmutable, until: CarbonImmutable|null}>  $periods
     * @return array{source: string, id: int}|null
     */
    public static function refAt(array $periods, CarbonImmutable $dueAt): ?array
    {
        foreach ([self::HOSPITAL, self::RESPITE, self::LEAVE] as $source) {
            foreach ($periods as $period) {
                if ($period['source'] === $source
                    && $dueAt->greaterThanOrEqualTo($period['from'])
                    && ($period['until'] === null || $dueAt->lessThan($period['until']))) {
                    return ['source' => $source, 'id' => $period['id']];
                }
            }
        }

        return null;
    }

    private static function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
