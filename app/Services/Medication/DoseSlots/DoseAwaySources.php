<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientLeaveRequest;
use App\Models\RespiteBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where a person is away from home, so a scheduled dose due then reads as
 * Away (P01 C7; Main, 2–3 Oct). Only existing records that say the person is
 * elsewhere, approved or confirmed:
 *
 * - client leave, approved (or completed): every dose on its NZ days,
 *   first to last;
 * - respite booked at another Site, confirmed, in progress or completed:
 *   every dose due from the booking's start until its end.
 *
 * Excursions and outings are not away: the medicines go with the person and
 * the doses are still owed. Hospital stays are deferred until the clinical
 * event types for admission and discharge exist (Main, 3 Oct); a third
 * source adds one entry to sql() and one to reasons(), nothing else.
 *
 * Away is read live, never stored: withdraw or change the record and the dose
 * is owed again, evaluated normally — overdue included. A recorded outcome
 * always wins over Away. Away never hides a dose: lists show it as
 * "Away · reason", it is never due, late, overdue, badged or alerted, and
 * P09 counts it as its own number, outside the given-as-due denominator.
 */
final class DoseAwaySources
{
    public const LEAVE = 'leave';

    public const RESPITE = 'respite';

    /** Leave that says the person is away: approved, or approved and since completed. */
    public const LEAVE_STATUSES = ['approved', 'completed'];

    /** Respite that says the person is away: confirmed, in progress, or completed. */
    public const RESPITE_STATUSES = ['confirmed', 'in_progress', 'completed'];

    /**
     * Each source's correlated subquery for a slot row (alias `s`, with
     * client_id, nz_date and due_at): the id of the record the person is away
     * by, or NULL. Keyed by the column the projection selects it as.
     *
     * @return array<string, string>
     */
    public static function sql(): array
    {
        $leave = "'".implode("','", self::LEAVE_STATUSES)."'";
        $respite = "'".implode("','", self::RESPITE_STATUSES)."'";

        return [
            'away_leave_id' => "SELECT l.id FROM client_leave_requests l
                WHERE l.client_id = s.client_id AND l.deleted_at IS NULL AND l.status IN ({$leave})
                  AND l.starts_on <= s.nz_date AND l.ends_on >= s.nz_date
                ORDER BY l.starts_on, l.id LIMIT 1",
            'away_respite_id' => "SELECT b.id FROM respite_bookings b JOIN clients rc ON rc.id = b.client_id
                WHERE b.client_id = s.client_id AND b.deleted_at IS NULL AND b.status IN ({$respite})
                  AND b.location_id IS NOT NULL AND (rc.site_id IS NULL OR b.location_id <> rc.site_id)
                  AND b.start_at <= s.due_at AND b.end_at > s.due_at
                ORDER BY b.start_at, b.id LIMIT 1",
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
        foreach ([self::LEAVE => 'away_leave_id', self::RESPITE => 'away_respite_id'] as $source => $column) {
            if (($row[$column] ?? null) !== null) {
                return ['source' => $source, 'id' => (int) $row[$column]];
            }
        }

        return null;
    }

    /**
     * The reason each away ref reads as after "Away · ": "On leave (until
     * Fri 3 Oct)", "Respite at Kōwhai House (until Sat 4 Oct)". Batch-loaded.
     *
     * @param  iterable<array{source: string, id: int}|null>  $refs
     * @return array<string, string> keyed "source:id"
     */
    public function reasons(iterable $refs): array
    {
        $ids = [self::LEAVE => [], self::RESPITE => []];
        foreach ($refs as $ref) {
            if ($ref !== null && isset($ids[$ref['source']])) {
                $ids[$ref['source']][$ref['id']] = true;
            }
        }

        $reasons = [];
        if ($ids[self::LEAVE] !== []) {
            ClientLeaveRequest::query()
                ->whereKey(array_keys($ids[self::LEAVE]))
                ->get(['id', 'ends_on'])
                ->each(function (ClientLeaveRequest $leave) use (&$reasons): void {
                    $reasons[self::LEAVE.':'.$leave->id] = 'On leave'.self::until($leave->ends_on?->toDateString());
                });
        }
        if ($ids[self::RESPITE] !== []) {
            RespiteBooking::query()
                ->whereKey(array_keys($ids[self::RESPITE]))
                ->with('location:id,name')
                ->get(['id', 'location_id', 'end_at'])
                ->each(function (RespiteBooking $booking) use (&$reasons): void {
                    $site = $booking->location?->name;
                    $end = $booking->end_at === null ? null : CarbonImmutable::parse($booking->getRawOriginal('end_at'), 'UTC')
                        ->setTimezone(self::timezone())
                        ->toDateString();
                    $reasons[self::RESPITE.':'.$booking->id] = ($site ? 'Respite at '.$site : 'Respite at another Site').self::until($end);
                });
        }

        return $reasons;
    }

    /**
     * The away periods of these people overlapping the NZ days $from..$to, as
     * the projection reads them — for a day the projection doesn't hold.
     *
     * @param  array<int, int>  $clientIds
     * @return Collection<int, list<array{source: string, id: int, from: CarbonImmutable, until: CarbonImmutable}>> keyed by client id
     */
    public function periods(array $clientIds, string $from, string $to): Collection
    {
        if ($clientIds === []) {
            return collect();
        }
        $timezone = self::timezone();
        $periods = [];

        ClientLeaveRequest::query()
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', self::LEAVE_STATUSES)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->orderBy('starts_on')->orderBy('id')
            ->get(['id', 'client_id', 'starts_on', 'ends_on'])
            ->each(function (ClientLeaveRequest $leave) use (&$periods, $timezone): void {
                $periods[(int) $leave->client_id][] = [
                    'source' => self::LEAVE,
                    'id' => (int) $leave->id,
                    'from' => CarbonImmutable::parse($leave->starts_on->toDateString(), $timezone)->utc(),
                    'until' => CarbonImmutable::parse($leave->ends_on->toDateString(), $timezone)->addDay()->utc(),
                ];
            });

        RespiteBooking::query()
            ->join('clients as rc', 'rc.id', '=', 'respite_bookings.client_id')
            ->whereIn('respite_bookings.client_id', $clientIds)
            ->whereIn('respite_bookings.status', self::RESPITE_STATUSES)
            ->whereNotNull('respite_bookings.location_id')
            ->where(fn ($elsewhere) => $elsewhere->whereNull('rc.site_id')->orWhereColumn('respite_bookings.location_id', '<>', 'rc.site_id'))
            ->where('respite_bookings.start_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->utc())
            ->where('respite_bookings.end_at', '>', CarbonImmutable::parse($from, $timezone)->utc())
            ->orderBy('respite_bookings.start_at')->orderBy('respite_bookings.id')
            ->get(['respite_bookings.id', 'respite_bookings.client_id', 'respite_bookings.start_at', 'respite_bookings.end_at'])
            ->each(function (RespiteBooking $booking) use (&$periods): void {
                $periods[(int) $booking->client_id][] = [
                    'source' => self::RESPITE,
                    'id' => (int) $booking->id,
                    'from' => CarbonImmutable::parse($booking->getRawOriginal('start_at'), 'UTC'),
                    'until' => CarbonImmutable::parse($booking->getRawOriginal('end_at'), 'UTC'),
                ];
            });

        return collect($periods);
    }

    /**
     * The first period (leave before respite, as the projection) a dose due
     * at $dueAt falls in.
     *
     * @param  list<array{source: string, id: int, from: CarbonImmutable, until: CarbonImmutable}>  $periods
     * @return array{source: string, id: int}|null
     */
    public static function refAt(array $periods, CarbonImmutable $dueAt): ?array
    {
        foreach ([self::LEAVE, self::RESPITE] as $source) {
            foreach ($periods as $period) {
                if ($period['source'] === $source && $dueAt->greaterThanOrEqualTo($period['from']) && $dueAt->lessThan($period['until'])) {
                    return ['source' => $source, 'id' => $period['id']];
                }
            }
        }

        return null;
    }

    private static function until(?string $nzDate): string
    {
        return $nzDate === null ? '' : ' (until '.CarbonImmutable::parse($nzDate, self::timezone())->format('D j M').')';
    }

    private static function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
