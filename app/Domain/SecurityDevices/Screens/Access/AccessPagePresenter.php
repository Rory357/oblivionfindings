<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Access (S&D rebuild drawings pg-sdr-19, 19b, 19c, 19d, 19f): who can open which doors, their
 * cards, visitor passes, door groups and the door log, from each Site's UniFi Access console as
 * its hub last read it. Everything is scoped to the viewer's Sites; another Site's console never
 * shows. Clock-ins from doors (19e) belong to another part of the build and aren't here yet.
 */
final class AccessPagePresenter
{
    public const array TABS = ['doors', 'people', 'visitors', 'schedules', 'activity'];

    public const array WHEN = ['today', 'coming', 'ended'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly AccessDoorsPresenter $doors,
        private readonly AccessPeoplePresenter $people,
        private readonly AccessActivityPresenter $activity,
    ) {}

    public static function canOpen(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.viewAny') && $viewer->canDo('securityDevices.accessControl.view');
    }

    public static function canManage(User $viewer): bool
    {
        return self::canOpen($viewer) && $viewer->canDo('securityDevices.accessControl.manage');
    }

    /**
     * The consoles the viewer may see: their Sites' only, by Site name.
     *
     * @return Collection<int, AccessConsole>
     */
    public function consoles(User $viewer): Collection
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return collect();
        }

        return AccessConsole::query()->with('site:id,name')->whereIn('site_id', $siteIds)->get()
            ->sortBy(fn (AccessConsole $console): string => mb_strtolower((string) ($console->site?->name ?? '')))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $query = [], ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $tab = in_array($query['tab'] ?? null, self::TABS, true) ? (string) $query['tab'] : 'doors';
        $all = $this->consoles($viewer);
        $siteFilter = is_numeric($query['site'] ?? null) && $all->contains('site_id', (int) $query['site']) ? (int) $query['site'] : null;
        $consoles = $siteFilter === null ? $all : $all->where('site_id', $siteFilter)->values();
        $search = is_string($query['q'] ?? null) ? trim(mb_substr($query['q'], 0, 100)) : '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $state = in_array($query['state'] ?? null, AccessDoorsPresenter::STATES, true) ? (string) $query['state'] : null;
        $status = in_array($query['status'] ?? null, AccessPeoplePresenter::STATUSES, true) ? (string) $query['status'] : null;
        $when = in_array($query['when'] ?? null, self::WHEN, true) ? (string) $query['when'] : null;
        $ids = $consoles->pluck('id')->all();
        $manage = self::canManage($viewer);

        return [
            'tab' => $tab,
            'filters' => [
                'site' => $siteFilter === null ? 'all' : (string) $siteFilter,
                'q' => $search === '' ? null : $search,
                'state' => $state ?? 'all',
                'status' => $status ?? 'all',
                'when' => $when ?? 'all',
            ],
            'sites' => $all->map(fn (AccessConsole $console): array => ['value' => (string) $console->site_id, 'label' => (string) ($console->site?->name ?? 'Site')])->values()->all(),
            'consoles' => $this->consoleInfo($all, $now),
            'counts' => $this->counts($ids, $now),
            'meters' => $this->meters($ids, $now),
            'doors' => $tab === 'doors' ? $this->doors->present($viewer, $consoles, $siteFilter, $state, $search, $now) : null,
            'people' => $tab === 'people' ? $this->people->present($consoles, $status, $search, $page, $now) : null,
            'visitors' => $tab === 'visitors' ? $this->visitors($consoles, $when, $search, $now) : null,
            'schedules' => $tab === 'schedules' ? $this->schedules($consoles, $search) : null,
            'activity' => $tab === 'activity' ? $this->activity->present($consoles, [
                'door' => $query['door'] ?? null,
                'kind' => $query['kind'] ?? null,
                'days' => $query['days'] ?? null,
                'person' => $query['person'] ?? null,
            ], $search, $page, $now) : null,
            'can' => [
                'manage' => $manage,
                'roll_call' => $all->isNotEmpty(),
            ],
        ];
    }

    /**
     * Staff at each Site the viewer can see, for pop-ups 81 and 90 (loaded when one opens).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function staffOptions(User $viewer): array
    {
        return self::canManage($viewer) ? $this->people->staffOptions($this->consoles($viewer)) : [];
    }

    /**
     * Each console's Site, how it's doing, its doors with their readers and its door groups: what
     * the pop-ups choose from.
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return list<array<string, mixed>>
     */
    private function consoleInfo(Collection $consoles, CarbonImmutable $now): array
    {
        $ids = $consoles->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $doors = AccessDoor::query()->whereIn('console_id', $ids)->orderBy('name')->get(['id', 'console_id', 'external_id', 'name', 'readers']);
        $policies = AccessPolicy::query()->whereIn('console_id', $ids)->orderBy('name')->get(['console_id', 'external_id', 'name', 'door_ids']);
        $people = AccessPerson::query()->whereIn('console_id', $ids)->whereIn('status', ['active', 'pending'])->get(['console_id', 'policy_ids']);

        return $consoles->map(function (AccessConsole $console) use ($doors, $policies, $people): array {
            $mine = $doors->where('console_id', $console->id)->values();
            $names = $mine->pluck('name', 'external_id');

            return [
                'id' => (int) $console->id,
                'site_id' => (int) $console->site_id,
                'site' => (string) ($console->site?->name ?? 'Site'),
                'state' => (string) $console->state,
                'synced_at' => $console->last_synced_at?->toIso8601String(),
                'doors' => $mine->map(fn (AccessDoor $door): array => [
                    'id' => (string) $door->external_id,
                    'name' => (string) $door->name,
                    'readers' => collect((array) ($door->readers ?? []))->filter(fn (mixed $reader): bool => is_array($reader) && is_string($reader['id'] ?? null))
                        ->map(fn (array $reader): array => ['id' => (string) $reader['id'], 'name' => trim((string) ($reader['name'] ?? '')) !== '' ? (string) $reader['name'] : (string) $door->name.' reader'])
                        ->values()->all(),
                ])->all(),
                'policies' => $policies->where('console_id', $console->id)->map(fn (AccessPolicy $policy): array => [
                    'id' => (string) $policy->external_id,
                    'name' => (string) $policy->name,
                    'doors' => collect((array) ($policy->door_ids ?? []))->map(fn (mixed $id): ?string => $names->get($id))->filter()->implode(', ') ?: 'No doors',
                    'people' => $people->where('console_id', $console->id)->filter(fn (AccessPerson $person): bool => in_array($policy->external_id, (array) ($person->policy_ids ?? []), true))->count(),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * The rail's counts: doors, people who can get in, and visitor passes for today.
     *
     * @param  list<int>  $ids
     * @return array{doors: int, people: int, visitors: int}
     */
    private function counts(array $ids, CarbonImmutable $now): array
    {
        if ($ids === []) {
            return ['doors' => 0, 'people' => 0, 'visitors' => 0];
        }
        $dayStart = AccessDoorsPresenter::dayStart($now);

        return [
            'doors' => AccessDoor::query()->whereIn('console_id', $ids)->count(),
            'people' => AccessPerson::query()->whereIn('console_id', $ids)->whereIn('status', ['active', 'pending'])->count(),
            'visitors' => AccessVisitor::query()->whereIn('console_id', $ids)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<', $dayStart->addDay()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $dayStart))
                ->count(),
        ];
    }

    /**
     * The header numbers, each from real data: doors locked, held open today, cards turned off this
     * month and people to match. Clock-ins from a badge come with the Clock-ins tab.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function meters(array $ids, CarbonImmutable $now): array
    {
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $doors = $ids === [] ? collect() : AccessDoor::query()->with('console.site:id,name')->whereIn('console_id', $ids)->orderBy('name')->get(['id', 'console_id', 'name', 'lock_state']);
        $unlocked = $doors->where('lock_state', 'unlocked')->values();
        $unknown = $doors->whereNull('lock_state')->count();
        $held = $ids === [] ? collect() : AccessEvent::query()->with(['door:id,name', 'console.site:id,name'])->whereIn('console_id', $ids)
            ->where('kind', 'held_open')->where('occurred_at', '>=', AccessDoorsPresenter::dayStart($now))->orderByDesc('occurred_at')->limit(200)->get();
        $latestHeld = $held->first();
        $monthStart = $now->setTimezone($zone)->startOfMonth()->utc();
        $offs = $ids === [] ? collect() : AccessJob::query()->whereIn('console_id', $ids)->whereIn('kind', ['card_remove', 'user_status'])
            ->where('state', 'succeeded')->where('completed_at', '>=', $monthStart)->limit(1000)->get()
            ->filter(function (AccessJob $job): bool {
                try {
                    return $job->kind === 'card_remove' || (($job->params['status'] ?? null) === 'deactivated');
                } catch (Throwable) {
                    return false;
                }
            });
        $toMatch = $ids === [] ? 0 : AccessPerson::query()->whereIn('console_id', $ids)->whereIn('match_state', ['unmatched', 'suggested'])
            ->where('kind', '!=', 'resident')->whereNull('client_id')->where('status', '!=', 'deactivated')->count();
        $doorWhere = fn (AccessDoor $door): string => $door->name.' at '.($door->console?->site?->name ?? 'Site');

        return [
            'locked' => [
                'count' => $doors->where('lock_state', 'locked')->count(),
                'of' => $doors->count(),
                'caption' => match (true) {
                    $doors->isEmpty() => 'No doors in UniFi Access yet',
                    $unlocked->isNotEmpty() => $doorWhere($unlocked->first()).' unlocked'.($unlocked->count() > 1 ? ' and '.($unlocked->count() - 1).' more' : ''),
                    $unknown > 0 => $unknown.' not known',
                    default => 'Every door locked',
                },
            ],
            'held_open' => [
                'count' => $held->count(),
                'caption' => $latestHeld === null
                    ? ['text' => 'None today', 'at' => null]
                    : ['text' => ($latestHeld->door?->name ?? 'A door').' at '.($latestHeld->console?->site?->name ?? 'Site').', {time}', 'at' => $latestHeld->occurred_at->toIso8601String()],
            ],
            'turned_off' => [
                'count' => $offs->count(),
                'caption' => $this->turnedOffWords($offs),
            ],
            'to_match' => [
                'count' => $toMatch,
                'caption' => $toMatch === 0 ? 'Everyone in UniFi is linked' : 'In UniFi, not linked to staff yet',
            ],
        ];
    }

    /** @param Collection<int, AccessJob> $offs */
    private function turnedOffWords(Collection $offs): string
    {
        if ($offs->isEmpty()) {
            return 'None this month';
        }
        $leavers = $offs->filter(fn (AccessJob $job): bool => str_starts_with((string) $job->reason, 'Left:'))->count();
        $byReason = $offs->reject(fn (AccessJob $job): bool => str_starts_with((string) $job->reason, 'Left:'))
            ->groupBy(fn (AccessJob $job): string => mb_strtolower(trim((string) $job->reason)) ?: 'other')
            ->map(fn (Collection $jobs): int => $jobs->count());
        $parts = [];
        foreach (['lost', 'stolen', 'broken'] as $word) {
            if (($byReason[$word] ?? 0) > 0) {
                $parts[] = $byReason[$word].' '.$word;
            }
        }
        $other = $byReason->except(['lost', 'stolen', 'broken'])->sum();
        if ($other > 0) {
            $parts[] = $other.' other';
        }
        if ($leavers > 0) {
            $parts[] = $leavers.' '.($leavers === 1 ? 'leaver' : 'leavers');
        }

        return 'This month · '.implode(', ', $parts);
    }

    /**
     * Visitor passes: today first, then coming up, then the last 7 days.
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return array<string, mixed>
     */
    private function visitors(Collection $consoles, ?string $when, string $search, CarbonImmutable $now): array
    {
        $ids = $consoles->pluck('id')->all();
        $sites = $consoles->mapWithKeys(fn (AccessConsole $console): array => [(int) $console->id => ['id' => (int) $console->site_id, 'name' => (string) ($console->site?->name ?? 'Site')]]);
        $dayStart = AccessDoorsPresenter::dayStart($now);
        $dayEnd = $dayStart->addDay();
        $rows = $ids === [] ? collect() : AccessVisitor::query()->whereIn('console_id', $ids)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $dayStart->subDays(7)))
            ->limit(500)->get()
            ->map(function (AccessVisitor $visitor) use ($sites, $now, $dayStart, $dayEnd): array {
                $starts = $visitor->starts_at;
                $ends = $visitor->ends_at;
                $phase = match (true) {
                    $ends !== null && $ends->lt($now) => 'ended',
                    $starts !== null && $starts->gte($dayEnd) => 'coming',
                    default => 'today',
                };
                $status = strtolower((string) $visitor->status);

                return [
                    'id' => (int) $visitor->id,
                    'name' => (string) $visitor->name,
                    'site_id' => $sites[$visitor->console_id]['id'] ?? null,
                    'site' => $sites[$visitor->console_id]['name'] ?? 'Site',
                    'starts_at' => $starts?->toIso8601String(),
                    'ends_at' => $ends?->toIso8601String(),
                    'phase' => $phase,
                    'badge' => match (true) {
                        $status === 'cancelled' => ['tone' => 'neutral', 'text' => 'Cancelled', 'at' => null],
                        $phase === 'ended' => ['tone' => 'neutral', 'text' => in_array($status, ['visited', 'visiting'], true) ? 'Ended · used' : 'Ended', 'at' => null],
                        $phase === 'coming' => ['tone' => 'neutral', 'text' => 'Starts {date}', 'at' => $starts?->toIso8601String()],
                        $status === 'visiting' => ['tone' => 'success', 'text' => 'Here now', 'at' => null],
                        $starts !== null && $starts->gt($now) => ['tone' => 'info', 'text' => 'Starts {time}', 'at' => $starts->toIso8601String()],
                        default => ['tone' => 'success', 'text' => 'Working now', 'at' => null],
                    },
                    'can_cancel' => $phase !== 'ended' && $status !== 'cancelled',
                    'sort' => match ($phase) {
                        'today' => '0'.($starts?->getTimestamp() ?? 0),
                        'coming' => '1'.($starts?->getTimestamp() ?? 0),
                        default => '2'.(PHP_INT_MAX - ($ends?->getTimestamp() ?? 0)),
                    },
                ];
            })
            ->sortBy('sort')->values();
        $needle = mb_strtolower($search);
        $shown = $rows->filter(fn (array $row): bool => ($needle === '' || str_contains(mb_strtolower($row['name'].' '.$row['site']), $needle))
            && ($when === null || $row['phase'] === $when))->values();

        return ['rows' => $shown->map(fn (array $row): array => collect($row)->except('sort')->all())->all(), 'total' => $rows->count()];
    }

    /**
     * Door groups (UniFi Access policies): which doors, and how many people are in each. Read only.
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return array<string, mixed>
     */
    private function schedules(Collection $consoles, string $search): array
    {
        $ids = $consoles->pluck('id')->all();
        if ($ids === []) {
            return ['rows' => []];
        }
        $sites = $consoles->mapWithKeys(fn (AccessConsole $console): array => [(int) $console->id => (string) ($console->site?->name ?? 'Site')]);
        $siteIds = $consoles->mapWithKeys(fn (AccessConsole $console): array => [(int) $console->id => (int) $console->site_id]);
        $doors = AccessDoor::query()->whereIn('console_id', $ids)->get(['console_id', 'external_id', 'name']);
        $people = AccessPerson::query()->whereIn('console_id', $ids)->whereIn('status', ['active', 'pending'])->get(['console_id', 'policy_ids', 'kind', 'client_id']);
        $needle = mb_strtolower($search);
        $rows = AccessPolicy::query()->whereIn('console_id', $ids)->orderBy('name')->get()
            ->map(function (AccessPolicy $policy) use ($sites, $siteIds, $doors, $people): array {
                $names = $doors->where('console_id', $policy->console_id)->whereIn('external_id', (array) ($policy->door_ids ?? []))->pluck('name')->sort()->values();
                $members = $people->where('console_id', $policy->console_id)->filter(fn (AccessPerson $person): bool => in_array($policy->external_id, (array) ($person->policy_ids ?? []), true));
                $residents = $members->filter(fn (AccessPerson $person): bool => AccessNames::isResident($person))->count();
                $count = $members->count();
                $site = $sites[$policy->console_id] ?? 'Site';

                return [
                    'id' => $policy->console_id.'-'.$policy->external_id,
                    'name' => (string) $policy->name,
                    'site' => $site,
                    'site_id' => $siteIds[$policy->console_id] ?? null,
                    'sub' => ($count === 1 ? '1 person' : $count.' people').($residents > 0 ? ' · '.($residents === 1 ? '1 resident fob' : $residents.' resident fobs').', not named here' : ''),
                    'doors' => $names->isEmpty() ? 'No doors' : $site.': '.$names->implode(', '),
                    'door_count' => $names->count(),
                    'when' => is_array($policy->schedule) && isset($policy->schedule['id']) ? 'On a schedule set in UniFi Access' : 'Not available',
                ];
            })
            ->filter(fn (array $row): bool => $needle === '' || str_contains(mb_strtolower($row['name'].' '.$row['doors']), $needle))
            ->values();

        return ['rows' => $rows->all()];
    }
}
