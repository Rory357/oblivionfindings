<?php

namespace App\Domain\SecurityDevices\DoorClockins\Screens;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinReview;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinRules;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinSetup;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Access › Clock-ins (drawing pg-sdr-19e) and its side panel (pop-up 89), for people who sort out
 * clock-in gaps (attendance.doorClockIns.review): what each badge suggested and what the person
 * chose today, gaps and mismatches first, and how this week went. Only the viewer's Sites; another
 * Site's rows never show. Times go out as instants; the page writes them in NZ time.
 *
 * The S&D session wires this into the Access page: `present()` is the tab's payload, `count`
 * is the tab's number (waiting for the manager), `meter` the header's "Clocked in from a badge".
 */
final class AccessClockinsPresenter
{
    /** Outcomes that wait for the manager until they review them. */
    private const array FOR_MANAGER = ['apart', 'no_badge', 'not_rostered', 'not_working', 'not_answered'];

    private const array TONES = [
        'no_badge' => 'critical',
        'apart' => 'warning', 'not_rostered' => 'warning', 'not_working' => 'warning', 'not_answered' => 'warning',
        'waiting' => 'info', 'asked' => 'info',
        'confirmed' => 'success', 'kept' => 'success',
        'now' => 'neutral', 'other_way' => 'neutral', 'not_yet' => 'neutral', 'not_work' => 'neutral',
    ];

    public function __construct(
        private readonly DoorClockinSetup $setup,
        private readonly DoorClockinReview $review,
    ) {}

    public static function canOpen(User $viewer): bool
    {
        return DoorClockinRules::canReview($viewer);
    }

    /**
     * The tab's payload, or null when the viewer can't sort out clock-ins.
     *
     * @return array<string, mixed>|null
     */
    public function present(User $viewer, ?int $siteFilter = null, ?CarbonImmutable $now = null): ?array
    {
        if (! self::canOpen($viewer)) {
            return null;
        }
        $now ??= CarbonImmutable::now('UTC');
        $siteIds = $this->siteIds($viewer, $siteFilter);
        $doors = $this->setup->doors($viewer)->whereIn('site_id', $siteIds)->values();
        $config = $this->setup->config();
        $today = DoorClockinRules::today($now);

        $rows = $this->todayRows($siteIds, $now);
        $waiting = count(array_filter($rows, fn (array $row): bool => $row['waiting_for_you']));
        $started = array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === DoorClockin::ROSTERED && $row['shift'] !== null
            && CarbonImmutable::parse($row['shift']['starts_at'])->lte($now)));

        return [
            'state' => self::state($doors, $today),
            'how' => [
                'since' => self::since($doors, $today),
                'starts_on' => self::state($doors, $today) === 'starting' ? (string) $doors->min(fn (DoorClockinDoor $door): string => $door->starts_on->format('Y-m-d')) : null,
                'places' => self::places($doors),
                'before' => (int) $config->before_minutes,
                'after' => (int) $config->after_minutes,
                'badge_time' => (bool) $config->badge_time,
            ],
            'week' => $this->week($siteIds, $now),
            'rows' => $rows,
            'count' => $waiting,
            'meter' => [
                'confirmed' => count(array_filter($started, fn (array $row): bool => in_array($row['outcome'], ['confirmed'], true))),
                'starts' => count($started),
            ],
            'can' => [
                'review' => true,
                'setup' => DoorClockinRules::canChange($viewer),
            ],
        ];
    }

    /**
     * Just the tab's number and the header's "Clocked in from a badge" block, for every Access tab
     * (the rail shows the count on all of them). Null when the viewer can't sort out clock-ins.
     *
     * @return array{count: int, meter: array{confirmed: int, starts: int}}|null
     */
    public function header(User $viewer, ?int $siteFilter = null, ?CarbonImmutable $now = null): ?array
    {
        if (! self::canOpen($viewer)) {
            return null;
        }
        $now ??= CarbonImmutable::now('UTC');
        $rows = $this->todayRows($this->siteIds($viewer, $siteFilter), $now);
        $started = array_filter($rows, fn (array $row): bool => $row['kind'] === DoorClockin::ROSTERED && $row['shift'] !== null
            && CarbonImmutable::parse($row['shift']['starts_at'])->lte($now));

        return [
            'count' => count(array_filter($rows, fn (array $row): bool => $row['waiting_for_you'])),
            'meter' => [
                'confirmed' => count(array_filter($started, fn (array $row): bool => $row['outcome'] === 'confirmed')),
                'starts' => count($started),
            ],
        ];
    }

    /**
     * The side panel (pop-up 89) for one row.
     *
     * @return array<string, mixed>|null
     */
    public function panel(User $viewer, int $clockinId, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now('UTC');
        $row = $this->review->find($viewer, $clockinId);
        if ($row === null) {
            return null;
        }
        $row->load(['user:id,name', 'site:id,name', 'door:id,name', 'reviewer:id,name']);
        $session = $this->review->session($row);
        $creators = $session !== null && (int) $session->created_by !== (int) $row->user_id
            ? User::query()->whereKey($session->created_by)->pluck('name', 'id') : collect();
        $data = $this->rowData($row, $session, $creators, $now);
        unset($data['sort_at']);
        $told = collect((array) $row->manager_told_user_ids)->filter()->all();
        $toldNames = $told === [] ? [] : User::query()->whereIn('id', $told)->orderBy('name')->pluck('name')->all();

        $nextShift = null;
        if ($row->kind === DoorClockin::NOT_ROSTERED) {
            $next = Shift::query()->where('user_id', $row->user_id)->where('site_id', $row->site_id)
                ->where('status', '!=', 'cancelled')->where('starts_at', '>', $now)->orderBy('starts_at')->first(['starts_at']);
            $nextShift = $next?->starts_at?->toIso8601String();
        }

        return [
            ...$data,
            'told' => $row->manager_told_at !== null ? ['at' => $row->manager_told_at->toIso8601String(), 'names' => $toldNames] : null,
            'next_shift' => $nextShift,
            'morning' => $this->morning($row, $session, $data['clock_in']['how'] ?? null, $toldNames),
            'choices' => $this->review->choices($viewer, $row),
            'can_note' => $viewer->canDo('timesheets.manageAny') || $viewer->canDo('timesheets.approve'),
        ];
    }

    /** @return list<int> */
    private function siteIds(User $viewer, ?int $siteFilter): array
    {
        $siteIds = DoorClockinRules::reviewSiteIds($viewer);

        return $siteFilter !== null ? array_values(array_intersect($siteIds, [$siteFilter])) : $siteIds;
    }

    /**
     * Today's rostered starts so far (and early badges for later ones), and badges from people not
     * on the roster: waiting for the manager first.
     *
     * @param  list<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function todayRows(array $siteIds, CarbonImmutable $now): array
    {
        if ($siteIds === []) {
            return [];
        }
        $rows = DoorClockin::query()
            ->whereIn('site_id', $siteIds)
            ->where('day', DoorClockinRules::today($now))
            ->where(fn ($query) => $query->whereNull('close_reason')->orWhere('close_reason', '!=', 'shift_changed'))
            ->where(fn ($query) => $query
                ->where('kind', DoorClockin::NOT_ROSTERED)
                ->orWhereNotNull('badge_at')
                ->orWhere('shift_starts_at', '<=', $now))
            ->with(['user:id,name', 'site:id,name', 'door:id,name', 'reviewer:id,name'])
            ->orderBy('id')
            ->limit(500)
            ->get();

        return $this->presentRows($rows, $now);
    }

    /**
     * @param  Collection<int, DoorClockin>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentRows(Collection $rows, CarbonImmutable $now): array
    {
        $sessions = $this->sessionsFor($rows);
        $creators = User::query()->whereIn('id', $sessions->pluck('created_by')->filter()->unique())->pluck('name', 'id');
        $told = $rows->flatMap(fn (DoorClockin $row): array => (array) $row->manager_told_user_ids)->filter()->unique()->values();
        $toldNames = $told->isEmpty() ? collect() : User::query()->whereIn('id', $told)->pluck('name', 'id');

        $out = $rows->map(function (DoorClockin $row) use ($sessions, $creators, $toldNames, $now): array {
            $session = $this->sessionFrom($sessions, $row);
            $data = $this->rowData($row, $session, $creators, $now);
            $data['told'] = $row->manager_told_at !== null ? [
                'at' => $row->manager_told_at->toIso8601String(),
                'names' => collect((array) $row->manager_told_user_ids)->map(fn (mixed $id): ?string => $toldNames[(int) $id] ?? null)->filter()->values()->all(),
            ] : null;

            return $data;
        })->all();

        $rank = fn (array $row): int => $row['waiting_for_you'] ? ($row['tone'] === 'critical' ? 0 : 1) : ($row['tone'] === 'info' ? 2 : 3);
        usort($out, fn (array $a, array $b): int => [$rank($a), $a['sort_at']] <=> [$rank($b), $b['sort_at']]);

        return array_values(array_map(function (array $row): array {
            unset($row['sort_at']);

            return $row;
        }, $out));
    }

    /**
     * @param  Collection<int, string>  $creators
     * @return array<string, mixed>
     */
    private function rowData(DoorClockin $row, ?HrAttendanceSession $session, Collection $creators, CarbonImmutable $now): array
    {
        $outcome = $this->outcome($row, $session, $now);
        $reviewed = $row->review !== null && in_array($outcome, self::FOR_MANAGER, true);
        if ($reviewed) {
            $outcome = match ($row->review) {
                'kept' => 'kept',
                'asked' => 'asked',
                default => 'not_work',
            };
        }
        $badgeAt = $row->badge_at;
        $apart = $badgeAt !== null && $session?->clock_in_at !== null
            ? (int) round(abs($session->clock_in_at->getTimestamp() - $badgeAt->getTimestamp()) / 60) : null;

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'person' => ['id' => (int) $row->user_id, 'name' => (string) ($row->user?->name ?? 'Someone who has left')],
            'site' => ['id' => (int) $row->site_id, 'name' => (string) ($row->site?->name ?? 'Site')],
            'day' => $row->day->format('Y-m-d'),
            'badge' => $badgeAt !== null ? [
                'at' => $badgeAt->toIso8601String(),
                'door' => (string) ($row->door?->name ?? 'A door'),
                'method' => DoorClockinRules::methodWord($row->method),
            ] : null,
            'suggestion' => $row->suggested_at !== null ? [
                'at' => $row->suggested_at->toIso8601String(),
                'answer' => in_array($row->answer, ['badge', 'now', 'not_working'], true) ? $row->answer : null,
                'answered_at' => $row->answered_at?->toIso8601String(),
                'closed' => $row->closed_at !== null && $row->answer === null,
            ] : null,
            'shift' => $row->shift_starts_at !== null ? [
                'starts_at' => $row->shift_starts_at->toIso8601String(),
                'ends_at' => $row->shift_ends_at?->toIso8601String(),
            ] : null,
            'clock_in' => $session?->clock_in_at !== null ? [
                'at' => $session->clock_in_at->toIso8601String(),
                'how' => self::how($session, (int) $row->user_id, $creators),
            ] : null,
            'apart_minutes' => $apart,
            'outcome' => $outcome,
            'tone' => self::TONES[$outcome] ?? 'neutral',
            'waiting_for_you' => ! $reviewed && in_array($outcome, self::FOR_MANAGER, true),
            'gap_checked_at' => $row->gap_checked_at?->toIso8601String(),
            'review' => $row->review !== null ? [
                'kind' => (string) $row->review,
                'by' => (string) ($row->reviewer?->name ?? 'A manager'),
                'at' => $row->reviewed_at?->toIso8601String(),
                'note' => $row->review_note,
            ] : null,
            'sort_at' => ($badgeAt ?? $row->shift_starts_at ?? $row->created_at)?->getTimestamp() ?? 0,
        ];
    }

    private function outcome(DoorClockin $row, ?HrAttendanceSession $session, CarbonImmutable $now): string
    {
        if ($row->kind === DoorClockin::NOT_ROSTERED) {
            return 'not_rostered';
        }

        return match ($row->answer) {
            'badge' => 'confirmed',
            'now' => 'now',
            'not_working' => 'not_working',
            default => match (true) {
                $session !== null && $row->badge_at !== null
                    && abs($session->clock_in_at->getTimestamp() - $row->badge_at->getTimestamp()) > DoorClockinRules::APART_MINUTES * 60 => 'apart',
                $session !== null => 'other_way',
                $row->badge_at !== null && $row->isWaiting() => 'waiting',
                $row->badge_at !== null => 'not_answered',
                $row->gap_checked_at !== null || ($row->shift_starts_at !== null && $row->shift_starts_at->lte($now->subMinutes(DoorClockinRules::GAP_AFTER_MINUTES))) => 'no_badge',
                default => 'not_yet',
            },
        };
    }

    /** @param  Collection<int, string>  $creators */
    private static function how(HrAttendanceSession $session, int $userId, Collection $creators): string
    {
        if ($session->source === 'door_badge') {
            return 'from the badge';
        }
        if ($session->created_by !== null && (int) $session->created_by !== $userId) {
            return 'by '.($creators[(int) $session->created_by] ?? 'a manager');
        }

        return match ((string) $session->source) {
            'self_service' => 'from My HR',
            'hr_module' => 'by HR',
            default => 'the usual way',
        };
    }

    /**
     * @param  Collection<int, DoorClockin>  $rows
     * @return Collection<int, HrAttendanceSession>
     */
    private function sessionsFor(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $userIds = $rows->pluck('user_id')->unique()->values();
        $first = $rows->min(fn (DoorClockin $row): string => $row->day->format('Y-m-d'));
        $start = CarbonImmutable::createFromFormat('Y-m-d', $first, DoorClockinRules::timezone())->startOfDay()->subDay()->utc();

        return HrAttendanceSession::query()
            ->whereIn('user_id', $userIds)
            ->where(fn ($query) => $query
                ->whereIn('shift_id', $rows->pluck('shift_id')->filter()->unique()->values())
                ->orWhere('clock_in_at', '>=', $start))
            ->orderBy('clock_in_at')
            ->get(['id', 'user_id', 'shift_id', 'site_id', 'clock_in_at', 'clock_out_at', 'source', 'created_by']);
    }

    /** @param  Collection<int, HrAttendanceSession>  $sessions */
    private function sessionFrom(Collection $sessions, DoorClockin $row): ?HrAttendanceSession
    {
        if ($row->attendance_session_id !== null && ($own = $sessions->firstWhere('id', (int) $row->attendance_session_id)) !== null) {
            return $own;
        }
        if ($row->shift_id !== null) {
            return $sessions->first(fn (HrAttendanceSession $session): bool => (int) $session->user_id === (int) $row->user_id && (int) $session->shift_id === (int) $row->shift_id);
        }

        return $sessions->first(fn (HrAttendanceSession $session): bool => (int) $session->user_id === (int) $row->user_id
            && (int) $session->site_id === (int) $row->site_id
            && DoorClockinRules::local($session->clock_in_at)->toDateString() === $row->day->format('Y-m-d'));
    }

    /**
     * This week (Monday to now, NZ): how each rostered start was clocked into.
     *
     * @param  list<int>  $siteIds
     * @return array<string, mixed>|null
     */
    private function week(array $siteIds, CarbonImmutable $now): ?array
    {
        if ($siteIds === []) {
            return null;
        }
        $monday = DoorClockinRules::local($now)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $rows = DoorClockin::query()
            ->whereIn('site_id', $siteIds)
            ->where('kind', DoorClockin::ROSTERED)
            ->where(fn ($query) => $query->whereNull('close_reason')->orWhere('close_reason', '!=', 'shift_changed'))
            ->whereBetween('shift_starts_at', [$monday->utc(), $now->subMinutes(DoorClockinRules::GAP_AFTER_MINUTES)])
            ->get(['id', 'user_id', 'site_id', 'shift_id', 'day', 'attendance_session_id']);
        if ($rows->isEmpty()) {
            return ['from' => $monday->toDateString(), 'to' => DoorClockinRules::today($now), 'starts' => 0, 'badge' => 0, 'other_way' => 0, 'by_manager' => 0, 'none' => 0];
        }
        $sessions = $this->sessionsFor($rows);
        $tally = ['badge' => 0, 'other_way' => 0, 'by_manager' => 0, 'none' => 0];
        foreach ($rows as $row) {
            $session = $this->sessionFrom($sessions, $row);
            $key = match (true) {
                $session === null => 'none',
                $session->source === 'door_badge' => 'badge',
                $session->created_by !== null && (int) $session->created_by !== (int) $row->user_id => 'by_manager',
                default => 'other_way',
            };
            $tally[$key]++;
        }

        return ['from' => $monday->toDateString(), 'to' => DoorClockinRules::today($now), 'starts' => $rows->count(), ...$tally];
    }

    /**
     * The person's morning, in order (pop-up 89).
     *
     * @param  list<string>  $toldNames
     * @return list<array{at: string, text: string}>
     */
    private function morning(DoorClockin $row, ?HrAttendanceSession $session, ?string $how, array $toldNames): array
    {
        $door = $row->door?->name !== null ? 'the '.mb_strtolower(mb_substr($row->door->name, 0, 1)).mb_substr($row->door->name, 1) : 'a door';
        $first = DoorClockinRules::firstName($row->user?->name);
        $items = [];
        if ($row->badge_at !== null) {
            $items[] = [$row->badge_at, "Badged in at {$door}".($row->suggested_at !== null ? ' · clock-in suggested' : ' · nothing suggested')];
        }
        if ($row->reminded_at !== null) {
            $items[] = [$row->reminded_at, 'Reminder sent: not confirmed yet'];
        }
        if ($row->manager_told_at !== null) {
            $who = $toldNames === [] ? 'The house manager' : implode(' and ', array_filter([implode(', ', array_slice($toldNames, 0, -1)), end($toldNames)]));
            $items[] = [$row->manager_told_at, "{$who} told: ".($row->kind === DoorClockin::NOT_ROSTERED ? 'not on the roster' : 'no badge and no clock-in')];
        }
        if ($row->answered_at !== null && in_array($row->answer, ['badge', 'now', 'not_working'], true)) {
            $items[] = [$row->answered_at, match ($row->answer) {
                'badge' => "{$first} confirmed the clock-in at the badge time",
                'now' => "{$first} chose to clock in now instead",
                default => "{$first} said they’re not working",
            }];
        }
        if ($session?->clock_in_at !== null && $session->source !== 'door_badge') {
            $items[] = [$session->clock_in_at, 'Clocked in '.($how ?? 'the usual way')];
        }
        if ($session?->clock_out_at !== null) {
            $items[] = [$session->clock_out_at, 'Clocked out'];
        }
        if ($row->reviewed_at !== null) {
            $by = $row->reviewer?->name ?? 'A manager';
            $items[] = [$row->reviewed_at, match ($row->review) {
                'kept' => "{$by} kept the clock-in",
                'asked' => "{$by} asked {$first} about it",
                default => "{$by} marked it not work",
            }];
        }
        usort($items, fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        return array_map(fn (array $item): array => ['at' => $item[0]->toIso8601String(), 'text' => $item[1]], $items);
    }

    /** @param  Collection<int, DoorClockinDoor>  $doors */
    public static function state(Collection $doors, string $today): string
    {
        if ($doors->isEmpty()) {
            return 'off';
        }

        return $doors->contains(fn (DoorClockinDoor $door): bool => $door->starts_on->format('Y-m-d') <= $today) ? 'on' : 'starting';
    }

    /** @param  Collection<int, DoorClockinDoor>  $doors  the first day a door counted, once one has */
    public static function since(Collection $doors, string $today): ?string
    {
        $started = $doors->filter(fn (DoorClockinDoor $door): bool => $door->starts_on->format('Y-m-d') <= $today);

        return $started->isEmpty() ? null : (string) $started->min(fn (DoorClockinDoor $door): string => $door->starts_on->format('Y-m-d'));
    }

    /**
     * Each Site with its counted doors, by name.
     *
     * @param  Collection<int, DoorClockinDoor>  $doors
     * @return list<array{site: string, doors: list<string>}>
     */
    public static function places(Collection $doors): array
    {
        return $doors->groupBy('site_id')
            ->map(fn (Collection $rows): array => [
                'site' => (string) ($rows->first()->site?->name ?? 'Site'),
                'doors' => $rows->map(fn (DoorClockinDoor $row): string => (string) ($row->door?->name ?? 'A door'))->sort()->values()->all(),
            ])
            ->sortBy('site')
            ->values()
            ->all();
    }
}
