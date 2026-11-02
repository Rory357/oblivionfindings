<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Access › Activity (pg-sdr-19f): the door log, newest first. Only staff matched at that Site are
 * named, and only for 90 days (D14); residents are "Resident fob" (D19). Searching never looks at a
 * name the page wouldn't show, so a hidden name can't be found by searching for it.
 */
final class AccessActivityPresenter
{
    public const array KINDS = ['opened', 'denied', 'forced', 'held_open', 'unlocked'];

    public const array RANGES = ['today', '7', '30', '90', 'all'];

    public const int PER_PAGE = 25;

    /**
     * @param  Collection<int, AccessConsole>  $consoles
     * @param  array{door?: mixed, kind?: mixed, days?: mixed, person?: mixed}  $filters
     * @return array<string, mixed>
     */
    public function present(Collection $consoles, array $filters, string $search, int $page, CarbonImmutable $now): array
    {
        $ids = $consoles->pluck('id')->all();
        $doors = $ids === [] ? collect() : AccessDoor::query()->whereIn('console_id', $ids)->orderBy('name')->get(['id', 'console_id', 'name']);
        $sites = $consoles->mapWithKeys(fn (AccessConsole $console): array => [(int) $console->id => (string) ($console->site?->name ?? 'Site')]);
        $door = is_numeric($filters['door'] ?? null) && $doors->contains('id', (int) $filters['door']) ? (int) $filters['door'] : null;
        $kind = in_array($filters['kind'] ?? null, self::KINDS, true) ? (string) $filters['kind'] : null;
        $range = in_array((string) ($filters['days'] ?? ''), self::RANGES, true) ? (string) $filters['days'] : 'today';
        $person = $this->namedPerson($ids, $filters['person'] ?? null);

        $query = AccessEvent::query()
            ->with(['door:id,name', 'person:id,kind,client_id,match_state,user_id', 'person.user:id,name'])
            ->whereIn('console_id', $ids === [] ? [0] : $ids)
            ->when($door !== null, fn (Builder $builder) => $builder->where('door_id', $door))
            ->when($kind !== null, fn (Builder $builder) => $builder->where('kind', $kind))
            ->when($person !== null, fn (Builder $builder) => $builder->where('person_id', $person->id)->where('occurred_at', '>=', $now->subDays(AccessNames::KEEP_DAYS)))
            ->when($range !== 'all', fn (Builder $builder) => $builder->where('occurred_at', '>=', $range === 'today' ? AccessDoorsPresenter::dayStart($now) : $now->subDays((int) $range)));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            // Door names, and names the page would show: matched staff, within 90 days.
            $query->where(fn (Builder $match) => $match
                ->whereHas('door', fn (Builder $doorQuery) => $doorQuery->where('name', 'like', $like))
                ->orWhere(fn (Builder $named) => $named->where('occurred_at', '>=', $now->subDays(AccessNames::KEEP_DAYS))
                    ->whereHas('person', fn (Builder $who) => $who->where('kind', 'staff')->where('match_state', 'matched')->whereNull('client_id')
                        ->whereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))));
        }
        $paginator = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'rows' => collect($paginator->items())->map(fn (AccessEvent $event): array => $this->row($event, $sites, $now))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'last_page' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
                'from' => (int) ($paginator->firstItem() ?? 0),
                'to' => (int) ($paginator->lastItem() ?? 0),
            ],
            'filters' => ['door' => $door !== null ? (string) $door : 'all', 'kind' => $kind ?? 'all', 'days' => $range, 'person' => $person !== null ? (string) $person->id : null],
            'person' => $person !== null ? AccessNames::staffName($person) : null,
            'doors' => $doors->map(fn (AccessDoor $row): array => ['value' => (string) $row->id, 'label' => $row->name.' · '.($sites[$row->console_id] ?? 'Site')])->values()->all(),
            'worth_a_look' => $this->worthALook($ids, $sites, $now),
            'how_in' => $this->howIn($ids, $now),
            'events_read' => $consoles->contains(fn (AccessConsole $console): bool => $console->events_until !== null),
        ];
    }

    /**
     * @param  Collection<int, string>  $sites
     * @return array<string, mixed>
     */
    public function row(AccessEvent $event, Collection $sites, CarbonImmutable $now): array
    {
        $who = AccessNames::who($event, $event->person, $now);

        return [
            'id' => (int) $event->id,
            'at' => $event->occurred_at->toIso8601String(),
            'door_id' => $event->door_id !== null ? (int) $event->door_id : null,
            'door' => (string) ($event->door?->name ?? 'A door no longer in UniFi'),
            'site' => (string) ($sites[$event->console_id] ?? 'Site'),
            'who' => $who,
            'how' => self::how($event),
            'what' => self::what($event),
        ];
    }

    public static function how(AccessEvent $event): string
    {
        if (in_array($event->kind, ['forced', 'held_open'], true)) {
            return 'Door sensor';
        }

        return match ($event->method) {
            'card' => AccessNames::isResident($event->person) ? 'Fob' : 'Card',
            'pin' => 'PIN',
            'app' => 'Phone',
            'face' => 'Face',
            'fingerprint' => 'Fingerprint',
            'button' => 'Exit button',
            'remote' => 'Unlocked remotely',
            default => '—',
        };
    }

    /** @return array{text: string, badge: array{tone: string, text: string}|null} */
    public static function what(AccessEvent $event): array
    {
        return match ($event->kind) {
            'opened' => ['text' => $event->method === 'button' ? 'Let out by the exit button' : 'Came in', 'badge' => null],
            'denied' => ['text' => 'Refused', 'badge' => ['tone' => 'critical', 'text' => 'Refused']],
            'forced' => ['text' => 'Forced open', 'badge' => ['tone' => 'critical', 'text' => 'Forced open']],
            'held_open' => ['text' => 'Held open', 'badge' => ['tone' => 'warning', 'text' => 'Held open']],
            'unlocked' => ['text' => 'Unlocked remotely', 'badge' => ['tone' => 'info', 'text' => 'Unlocked remotely']],
            default => ['text' => 'Other door event', 'badge' => null],
        };
    }

    /** A person whose door log may be opened on its own: staff matched at one of these consoles (never a resident). */
    private function namedPerson(array $consoleIds, mixed $id): ?AccessPerson
    {
        if (! is_numeric($id) || $consoleIds === []) {
            return null;
        }
        $person = AccessPerson::query()->with('user:id,name')->whereIn('console_id', $consoleIds)->find((int) $id);

        return AccessNames::isMatchedStaff($person) ? $person : null;
    }

    /**
     * Today's door events worth a look (forced, held open, refused, unlocked remotely) and cards
     * turned off today, newest first.
     *
     * @param  list<int>  $consoleIds
     * @param  Collection<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function worthALook(array $consoleIds, Collection $sites, CarbonImmutable $now): array
    {
        if ($consoleIds === []) {
            return [];
        }
        $since = AccessDoorsPresenter::dayStart($now);
        $items = AccessEvent::query()
            ->with(['door:id,name', 'person:id,kind,client_id,match_state,user_id', 'person.user:id,name'])
            ->whereIn('console_id', $consoleIds)
            ->whereIn('kind', ['forced', 'held_open', 'denied', 'unlocked'])
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(6)
            ->get()
            ->map(function (AccessEvent $event) use ($sites, $now): array {
                $who = AccessNames::who($event, $event->person, $now);
                $door = (string) ($event->door?->name ?? 'A door');
                $site = (string) ($sites[$event->console_id] ?? 'Site');

                return [
                    'key' => 'event-'.$event->id,
                    'at' => $event->occurred_at->toIso8601String(),
                    'tone' => match ($event->kind) {
                        'forced', 'denied' => 'critical',
                        'held_open' => 'warning',
                        default => 'info',
                    },
                    'title' => match ($event->kind) {
                        'forced' => $door.' forced open',
                        'held_open' => $door.' held open',
                        'denied' => 'Refused at '.$door,
                        default => $door.' unlocked remotely',
                    },
                    'sub' => $site.($who['named'] ? ' · '.$who['text'] : ($event->kind === 'denied' && $who['text'] !== '—' ? ' · '.$who['text'] : '')),
                ];
            });

        $jobs = AccessJob::query()->with('requestedBy:id,name')
            ->whereIn('console_id', $consoleIds)
            ->whereIn('kind', ['card_remove', 'user_status'])
            ->where('state', 'succeeded')
            ->where('completed_at', '>=', $since)
            ->orderByDesc('completed_at')
            ->limit(6)
            ->get()
            ->filter(function (AccessJob $job): bool {
                try {
                    return $job->kind === 'card_remove' || (($job->params['status'] ?? null) === 'deactivated');
                } catch (Throwable) {
                    return false;
                }
            })
            ->map(fn (AccessJob $job): array => [
                'key' => 'job-'.$job->id,
                'at' => ($job->completed_at ?? $now)->toIso8601String(),
                'tone' => 'info',
                'title' => $job->kind === 'card_remove' ? 'A card was turned off' : 'Someone was turned off in UniFi Access',
                'sub' => trim(($sites[$job->console_id] ?? 'Site').($job->reason ? ' · '.$job->reason : '').($job->requestedBy?->name ? ' · by '.$job->requestedBy->name : '')),
            ]);

        return $items->concat($jobs)->sortByDesc('at')->take(6)->values()->all();
    }

    /**
     * How people got in today, by way in. Residents' fobs count as "Resident fob" (D19).
     *
     * @param  list<int>  $consoleIds
     * @return array{total: int, parts: list<array{label: string, value: int}>}|null
     */
    private function howIn(array $consoleIds, CarbonImmutable $now): ?array
    {
        if ($consoleIds === []) {
            return null;
        }
        $events = AccessEvent::query()
            ->with('person:id,kind,client_id')
            ->whereIn('console_id', $consoleIds)
            ->whereIn('kind', ['opened', 'unlocked'])
            ->where(fn (Builder $query) => $query->whereNull('method')->orWhere('method', '!=', 'button'))
            ->where('occurred_at', '>=', AccessDoorsPresenter::dayStart($now))
            ->limit(20_000)
            ->get(['id', 'person_id', 'kind', 'method']);
        $parts = [];
        foreach ($events as $event) {
            $label = match (true) {
                $event->kind === 'unlocked' || $event->method === 'remote' => 'Unlocked remotely',
                AccessNames::isResident($event->person) => 'Resident fob',
                $event->method === 'card' => 'Card',
                $event->method === 'app' => 'Phone',
                $event->method === 'pin' => 'PIN',
                in_array($event->method, ['face', 'fingerprint'], true) => 'Face or fingerprint',
                default => 'Other',
            };
            $parts[$label] = ($parts[$label] ?? 0) + 1;
        }
        arsort($parts);

        return [
            'total' => array_sum($parts),
            'parts' => array_map(fn (string $label, int $value): array => ['label' => $label, 'value' => $value], array_keys($parts), array_values($parts)),
        ];
    }
}
