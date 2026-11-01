<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Access › Doors (pg-sdr-19): each UniFi Access door as a card, problems first. Every number comes
 * from what the Site's console last said through its hub: lock state, open or closed, who can open
 * it (people whose door groups include it) and entries per hour over the last 24 hours.
 */
final class AccessDoorsPresenter
{
    public const array STATES = ['attention', 'locked', 'unlocked'];

    /** Readers' model codes in words; anything else is "UniFi Access reader". */
    private const array READERS = [
        'UA-G2' => 'UniFi Access G2 reader',
        'UA-G2-PRO' => 'UniFi Access G2 Reader Pro',
        'UA-G2-MINI' => 'UniFi Access G2 Mini reader',
        'UA-G3' => 'UniFi Access G3 reader',
        'UA-G3-PRO' => 'UniFi Access G3 Reader Pro',
        'UA-G3-FLEX' => 'UniFi Access G3 Flex reader',
        'UA-LITE' => 'UniFi Access Reader Lite',
        'UA-PRO' => 'UniFi Access Reader Pro',
        'UA-INTERCOM' => 'UniFi Access intercom',
        'UA-HUB' => 'UniFi Access hub',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * @param  Collection<int, AccessConsole>  $consoles
     * @return array<string, mixed>
     */
    public function present(User $viewer, Collection $consoles, ?int $siteFilter, ?string $state, string $search, CarbonImmutable $now): array
    {
        $ids = $consoles->pluck('id')->all();
        $doors = $ids === [] ? collect() : AccessDoor::query()->whereIn('console_id', $ids)->orderBy('name')->get();
        $events = $this->recentEvents($ids, $now);
        $dayStart = self::dayStart($now);
        $canOpen = $this->canOpenCounts($ids);
        $links = $this->deviceLinks($viewer, $doors);
        $byConsole = $consoles->keyBy('id');

        $cards = $doors->map(function (AccessDoor $door) use ($byConsole, $events, $dayStart, $canOpen, $links, $now): array {
            /** @var AccessConsole $console */
            $console = $byConsole->get($door->console_id);

            return $this->card($door, $console, $events->get($door->id, collect()), $dayStart, (int) ($canOpen[$door->id] ?? 0), $links[$door->id] ?? null, $now);
        });

        $needle = mb_strtolower($search);
        $shown = $cards->filter(fn (array $card): bool => ($needle === '' || str_contains(mb_strtolower($card['name'].' '.$card['site']), $needle))
            && match ($state) {
                'attention' => $card['group'] === 'attention',
                'locked' => $card['value'] === 'Locked',
                'unlocked' => $card['value'] === 'Unlocked',
                default => true,
            })->values();
        $attention = $shown->where('group', 'attention')->values();
        $normal = $shown->where('group', 'normal')->values();

        return [
            'groups' => [
                [
                    'key' => 'attention',
                    'title' => 'Needs a look',
                    'count' => $attention->count(),
                    'sub' => $this->reasons($attention),
                    'items' => $attention->all(),
                ],
                ['key' => 'normal', 'title' => 'Working normally', 'count' => $normal->count(), 'sub' => null, 'items' => $normal->all()],
            ],
            'total' => $cards->count(),
            'emergency' => $consoles->map(fn (AccessConsole $console): array => [
                'site_id' => (int) $console->site_id,
                'site' => (string) ($console->site?->name ?? 'Site'),
                'doors' => $doors->where('console_id', $console->id)->count(),
                'mode' => in_array($console->emergency_mode, ['lockdown', 'evacuation'], true) ? $console->emergency_mode : 'normal',
                'known' => $console->state === 'ok',
            ])->values()->all(),
            'not_managed' => $this->notManaged($viewer, $siteFilter),
        ];
    }

    /** Midnight today, New Zealand time, as UTC. */
    public static function dayStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc();
    }

    /**
     * Door events from the last 24 hours (and since midnight, whichever is longer), by door.
     *
     * @param  list<int>  $consoleIds
     * @return Collection<int, Collection<int, AccessEvent>>
     */
    private function recentEvents(array $consoleIds, CarbonImmutable $now): Collection
    {
        if ($consoleIds === []) {
            return collect();
        }
        $since = $now->subHours(24)->min(self::dayStart($now));

        return AccessEvent::query()
            ->with(['person:id,kind,client_id,match_state,user_id', 'person.user:id,name'])
            ->whereIn('console_id', $consoleIds)
            ->whereNotNull('door_id')
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->limit(20_000)
            ->get(['id', 'console_id', 'door_id', 'person_id', 'kind', 'method', 'actor_name', 'occurred_at'])
            ->groupBy('door_id');
    }

    /**
     * How many people can open each door: active people whose door groups include it.
     *
     * @param  list<int>  $consoleIds
     * @return array<int, int> door id => people
     */
    private function canOpenCounts(array $consoleIds): array
    {
        if ($consoleIds === []) {
            return [];
        }
        $doors = AccessDoor::query()->whereIn('console_id', $consoleIds)->get(['id', 'console_id', 'external_id']);
        $policies = AccessPolicy::query()->whereIn('console_id', $consoleIds)->get(['console_id', 'external_id', 'door_ids']);
        $people = AccessPerson::query()->whereIn('console_id', $consoleIds)->whereIn('status', ['active', 'pending'])->get(['console_id', 'policy_ids']);
        $counts = [];
        foreach ($doors as $door) {
            $groups = $policies->where('console_id', $door->console_id)
                ->filter(fn (AccessPolicy $policy): bool => in_array($door->external_id, (array) ($policy->door_ids ?? []), true))
                ->pluck('external_id')->all();
            $counts[(int) $door->id] = $groups === [] ? 0 : $people->where('console_id', $door->console_id)
                ->filter(fn (AccessPerson $person): bool => array_intersect((array) ($person->policy_ids ?? []), $groups) !== [])
                ->count();
        }

        return $counts;
    }

    /**
     * The door's device page, only when the viewer can see that device.
     *
     * @param  Collection<int, AccessDoor>  $doors
     * @return array<int, string> door id => href
     */
    private function deviceLinks(User $viewer, Collection $doors): array
    {
        $deviceIds = $doors->pluck('device_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($deviceIds === []) {
            return [];
        }
        $visible = $this->access->visibleDevices($viewer)->whereIn('devices.id', $deviceIds)->pluck('devices.id')->map(fn (mixed $id): int => (int) $id)->all();
        $links = [];
        foreach ($doors as $door) {
            if ($door->device_id !== null && in_array((int) $door->device_id, $visible, true)) {
                $links[(int) $door->id] = '/security-devices/devices/'.$door->device_id;
            }
        }

        return $links;
    }

    /**
     * @param  Collection<int, AccessEvent>  $events
     * @return array<string, mixed>
     */
    private function card(AccessDoor $door, AccessConsole $console, Collection $events, CarbonImmutable $dayStart, int $canOpen, ?string $href, CarbonImmutable $now): array
    {
        $today = $events->filter(fn (AccessEvent $event): bool => $event->occurred_at->gte($dayStart));
        $forced = $today->where('kind', 'forced')->last();
        $held = $today->where('kind', 'held_open')->last();
        $unlockedBy = $today->where('kind', 'unlocked')->last();
        $site = (string) ($console->site?->name ?? 'Site');
        $consoleOk = $console->state === 'ok';

        [$group, $reason, $badge, $note] = match (true) {
            ! $consoleOk => ['attention', 'cant_check', ['tone' => 'neutral', 'text' => 'Can’t check'], [
                'text' => $console->last_synced_at ? 'UniFi Access at '.$site.' isn’t answering · last read {time}' : 'UniFi Access at '.$site.' hasn’t been read yet',
                'at' => $console->last_synced_at?->toIso8601String(),
            ]],
            ! $door->hub_bound => ['attention', 'not_connected', ['tone' => 'warning', 'text' => 'Not connected'], [
                'text' => 'Not linked to a UniFi Access hub, so it can’t lock or unlock', 'at' => null,
            ]],
            $forced !== null => ['attention', 'forced', ['tone' => 'critical', 'text' => 'Forced open today'], [
                'text' => 'At {time} · opened while it was locked', 'at' => $forced->occurred_at->toIso8601String(),
            ]],
            $held !== null => ['attention', 'held', ['tone' => 'warning', 'text' => 'Held open today'], [
                'text' => 'At {time} · left open after someone came through', 'at' => $held->occurred_at->toIso8601String(),
            ]],
            $door->door_state === 'open' && $door->lock_state === 'locked' => ['attention', 'open', ['tone' => 'warning', 'text' => 'Open now'], [
                'text' => 'The door sensor says it’s open', 'at' => null,
            ]],
            $door->lock_state === 'unlocked' => ['attention', 'unlocked', ['tone' => 'info', 'text' => 'Unlocked'], $unlockedBy !== null ? [
                'text' => 'Unlocked remotely at {time}'.$this->byName($unlockedBy, $now), 'at' => $unlockedBy->occurred_at->toIso8601String(),
            ] : ['text' => 'Unlocked in UniFi, by a schedule or by hand', 'at' => null]],
            default => ['normal', null, ['tone' => $door->lock_state === null ? 'neutral' : 'success', 'text' => 'Online'], [
                'text' => 'Checked {ago}', 'at' => $console->last_synced_at?->toIso8601String(),
            ]],
        };

        $value = match ($door->lock_state) {
            'locked' => 'Locked',
            'unlocked' => 'Unlocked',
            default => 'Not available',
        };
        $position = match ($door->door_state) {
            'closed' => 'Closed',
            'open' => 'Open',
            default => 'Open or closed not known',
        };
        $people = $canOpen === 1 ? '1 can open it' : $canOpen.' can open it';

        return [
            'id' => (int) $door->id,
            'external_id' => (string) $door->external_id,
            'console_id' => (int) $door->console_id,
            'site_id' => (int) $console->site_id,
            'name' => (string) $door->name,
            'sub' => $this->readerWords($door),
            'icon' => match (true) {
                $door->lock_state === 'unlocked' => 'lock-open',
                $door->door_state === 'open' => 'door-open',
                $door->lock_state === 'locked' => 'door-closed',
                default => 'lock',
            },
            'group' => $group,
            'reason' => $reason,
            'badge' => $badge,
            'note' => $note,
            'value' => $value,
            'value_sub' => $position.' · '.$people,
            'can_open' => $canOpen,
            'graph' => $this->graph($events, $console, $now),
            'site' => $site,
            'seen' => ['label' => 'Seen', 'at' => $door->seen_at?->toIso8601String()],
            'href' => $href,
        ];
    }

    /** " by Ana Ruha" only for staff matched at that Site, within 90 days (D14). */
    private function byName(AccessEvent $event, CarbonImmutable $now): string
    {
        $who = AccessNames::who($event, $event->person, $now);

        return $who['named'] ? ' by '.$who['text'] : '';
    }

    /**
     * Entries per hour over the last 24 hours, oldest first. An entry is a door opened from
     * outside: by card, PIN, phone, face or fingerprint (the exit button is a way out).
     *
     * @param  Collection<int, AccessEvent>  $events
     * @return array{label: string, note: array{text: string, at: string|null}, spark: list<int>|null}
     */
    private function graph(Collection $events, AccessConsole $console, CarbonImmutable $now): array
    {
        $label = 'Entries per hour, last 24 hours';
        if ($console->events_until === null) {
            return ['label' => $label, 'note' => ['text' => 'Not available · door events not read yet', 'at' => null], 'spark' => null];
        }
        $start = $now->subHours(24);
        $spark = array_fill(0, 24, 0);
        foreach ($events as $event) {
            if ($event->kind !== 'opened' || $event->method === 'button' || $event->occurred_at->lt($start)) {
                continue;
            }
            $hour = (int) floor(($event->occurred_at->getTimestamp() - $start->getTimestamp()) / 3600);
            $spark[max(0, min(23, $hour))]++;
        }
        $total = array_sum($spark);

        return [
            'label' => $label,
            'note' => ['text' => $total === 0 ? 'None in 24 hours' : ($total === 1 ? '1 in 24 hours' : $total.' in 24 hours'), 'at' => null],
            'spark' => $spark,
        ];
    }

    private function readerWords(AccessDoor $door): string
    {
        $readers = array_values(array_filter((array) ($door->readers ?? []), 'is_array'));
        if ($readers === []) {
            return 'No reader listed';
        }
        $first = $readers[0];
        $type = strtoupper(trim((string) ($first['type'] ?? '')));
        $model = self::READERS[$type] ?? 'UniFi Access reader';
        $name = trim((string) ($first['name'] ?? ''));
        $more = count($readers) > 1 ? ' · '.(count($readers) - 1).' more' : '';

        return $model.($name !== '' ? ' · '.$name : '').$more;
    }

    /** @param Collection<int, array<string, mixed>> $cards */
    private function reasons(Collection $cards): ?string
    {
        if ($cards->isEmpty()) {
            return null;
        }
        $words = [
            'forced' => ['forced open today', 'forced open today'],
            'held' => ['held open today', 'held open today'],
            'open' => ['open now', 'open now'],
            'unlocked' => ['unlocked', 'unlocked'],
            'not_connected' => ['not connected', 'not connected'],
            'cant_check' => ['can’t be checked', 'can’t be checked'],
        ];
        $parts = [];
        foreach ($words as $key => [$one, $many]) {
            $count = $cards->where('reason', $key)->count();
            if ($count > 0) {
                $parts[] = $count.' '.($count === 1 ? $one : $many);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Door devices at the viewer's Sites that aren't in UniFi Access, so they're view only here.
     *
     * @return array{count: int, names: list<string>}
     */
    private function notManaged(User $viewer, ?int $siteFilter): array
    {
        $linked = AccessDoor::query()->whereNotNull('device_id')->pluck('device_id')->map(fn (mixed $id): int => (int) $id)->all();
        $query = ($siteFilter === null ? $this->access->visibleDevices($viewer) : $this->access->visibleDevicesForSite($viewer, $siteFilter))
            ->whereIn('devices.category', ['access_control', 'door'])
            ->whereNotIn('devices.status', ['decommissioned', 'quarantined', 'lost'])
            ->when($linked !== [], fn ($builder) => $builder->whereNotIn('devices.id', $linked));
        $count = (clone $query)->count();
        $names = $count === 0 ? [] : (clone $query)->orderBy('devices.name')->limit(3)->pluck('devices.name')->map(fn (mixed $name): string => (string) $name)->all();

        return ['count' => $count, 'names' => $names];
    }
}
