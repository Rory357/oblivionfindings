<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialDeviceBindingEvent;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandReconciliation;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\Integration\IntegrationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The door's page (S&D rebuild Phase E, drawings pg-sdr-6 Overview, 6b Events, 6c Access): locked or
 * not, who came in, and who can open it.
 *
 * Privacy (D19, D14): a resident's fob is "Resident fob", never their name, and their fobs are a count.
 * A name shows only when it is a staff member at this Site; anyone Oblivion can't place shows no name,
 * because that could be a resident too. Names show for 90 days, then only the time and how.
 *
 * "Unlock for N seconds" is today's governed access.door.unlock_timed (step-up, approval, hub-first,
 * read-back that it locked again); the seconds are the door's own approved setting. "Keep unlocked"
 * has no governed command yet, so it is never offered here. The door never unlocks by schedule or
 * automation (D12). The Settings tab has no approved drawing yet, so it isn't built.
 */
final class DoorPagePresenter
{
    public const array TAB_LABELS = [
        'overview' => 'Overview',
        'events' => 'Events',
        'access' => 'Access',
    ];

    public const int NAME_DAYS = 90;

    private const int PER_PAGE = 25;

    private const array RANGES = ['today' => 'Today', '7d' => '7 days', '90d' => '90 days'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly SafetyKitCommands $commands,
    ) {}

    public static function serves(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::Door;
    }

    /**
     * @param  array{tab?: mixed, range?: mixed, page?: mixed, step_up_at?: ?CarbonImmutable}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('Pacific/Auckland')->startOfDay()->utc();
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $siteId = $entry?->siteId;
        $siteName = $entry?->siteName;
        $canSeeEvents = $viewer->canDo('securityDevices.events.view');

        $tabs = array_values(array_filter(['overview', $canSeeEvents ? 'events' : null, 'access']));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'overview';
        $range = is_string($input['range'] ?? null) && array_key_exists($input['range'], self::RANGES) ? $input['range'] : '7d';

        $people = $siteId !== null ? $this->people($siteId) : ['staff' => [], 'residents' => []];
        // Read up to 90 days: the furthest any view goes, and how long names are kept on screen.
        $events = $canSeeEvents ? $this->events($viewer, $device, $now->subDays(self::NAME_DAYS), $people, $now) : collect();
        $stepUpAt = $input['step_up_at'] ?? null;
        $unlock = $this->commands->action($viewer, $device, $siteId, 'access.door.unlock_timed', $stepUpAt);
        $seconds = data_get($device->config ?? [], 'management.unifi_access.unlock_duration_seconds');
        $seconds = is_int($seconds) && $seconds >= 5 && $seconds <= 60 ? $seconds : null;
        $credentials = $siteId !== null ? $this->credentials($device, $siteId) : collect();
        $lock = $this->lockState($device, $events);

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::Door->value,
                'kind_label' => DeviceKind::Door->label(),
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $siteName,
                'site_id' => $siteId,
                'address' => $device->ip_address ? (string) $device->ip_address : null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($device, $entry, $siteName, $lock, $unlock),
                'back' => ['href' => '/security-devices/devices?tab=alarms_doors', 'label' => 'Devices'],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'lock' => $lock,
            'meters' => $this->meters($events, $credentials, $canSeeEvents, $today),
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update') && $state !== DeviceState::Retired,
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'see_events' => $canSeeEvents,
                'unlock' => $unlock !== null && $unlock['available'] && $seconds !== null,
            ],
            'control' => [
                'unlock' => $unlock === null ? null : [
                    ...$unlock,
                    'available' => $unlock['available'] && $seconds !== null,
                    'reason' => $unlock['reason'] ?? ($seconds === null ? 'The door’s unlock time isn’t set up safely yet, so Oblivion won’t unlock it.' : null),
                ],
                'seconds' => $seconds,
                'commands' => $this->commands->recent($viewer, $device, ['access.door.unlock_timed'], $now),
                'confirm_identity_url' => route('security-devices.devices.safety.confirm-identity', ['device' => $device->id], false),
            ],
            'overview' => $tab === 'overview' ? [
                'today' => $canSeeEvents ? $events->filter(fn (array $row): bool => $row['at'] >= $today->toIso8601String())->take(8)->values()->all() : [],
                'who' => $this->whoCanOpen($credentials),
                'week' => $canSeeEvents ? $this->week($events, $now) : null,
                'facts' => $this->about($device, $unlock),
            ] : null,
            'events' => $tab === 'events' ? $this->eventsTab($events, $range, $input['page'] ?? 1, $now, $today) : null,
            'access' => $tab === 'access' ? [
                'groups' => $this->groups($credentials),
                'schedules' => $credentials->pluck('schedule')->filter()->unique()->values()->all(),
            ] : null,
        ];
    }

    /**
     * S&D Phase F: the door's lock right now, worked out exactly as its page does, for a house mode's
     * "Check doors are locked". No viewer and no names: only whether it is locked. `locked` is null
     * when the door hasn't reported its lock.
     *
     * @return array{locked: ?bool, title: string, checked_at: ?string}
     */
    public function lockNow(Device $device): array
    {
        $now = CarbonImmutable::now();
        $custody = DeviceObservations::custodyStart($device);
        $since = $now->subDays(self::NAME_DAYS);
        $from = $custody !== null && $custody->greaterThan($since) ? $custody : $since;
        $nobody = ['staff' => [], 'residents' => []];
        $rows = DeviceEvent::query()->where('device_id', $device->id)->where('occurred_at', '>=', $from)
            ->latest('occurred_at')->limit(200)->get(['id', 'event_type', 'payload', 'occurred_at'])
            ->map(fn (DeviceEvent $event): array => $this->row('d'.$event->id, (string) $event->event_type, $event->occurred_at, is_array($event->payload) ? $event->payload : [], $nobody, $now))
            ->concat(IntegrationEvent::query()->where('canonical_device_id', $device->id)->where('occurred_at', '>=', $from)
                ->latest('occurred_at')->limit(200)->get(['id', 'event_type', 'normalized_payload', 'occurred_at'])
                ->map(fn (IntegrationEvent $event): array => $this->row('i'.$event->id, (string) $event->event_type, $event->occurred_at, is_array($event->normalized_payload) ? $event->normalized_payload : [], $nobody, $now)))
            ->filter(fn (array $row): bool => $row['kind'] !== 'heartbeat')
            ->sortByDesc('at')
            ->values();
        $lock = $this->lockState($device, $rows);

        return [
            'locked' => match ($lock['chip']['label']) {
                'Locked' => true,
                'Unlocked' => false,
                // Forced or open: not locked shut, whatever the lock says.
                'Forced open', 'Open' => false,
                default => null,
            },
            'title' => (string) $lock['title'],
            'checked_at' => $lock['checked_at'],
        ];
    }

    /**
     * Staff (named) and residents (never named) at the Site, as lower-case names for matching what the
     * door's maker reports. A resident match always wins.
     *
     * @return array{staff: array<string, string>, residents: array<string, true>}
     */
    private function people(int $siteId): array
    {
        $residents = [];
        $clientIds = AccessControlCredential::query()
            ->where('site_id', $siteId)
            ->where('holder_type', AccessControlCredential::HOLDER_CLIENT)
            ->pluck('holder_id');
        Client::query()
            ->where(fn ($query) => $query->where('site_id', $siteId)->orWhereIn('id', $clientIds))
            ->get(['id', 'first_name', 'last_name', 'preferred_name'])
            ->each(function (Client $client) use (&$residents): void {
                $first = trim((string) $client->first_name);
                $last = trim((string) $client->last_name);
                $preferred = trim((string) $client->preferred_name);
                foreach ([$first.' '.$last, $preferred.' '.$last, $first, $preferred, $last] as $name) {
                    $key = self::key($name);
                    if ($key !== '') {
                        $residents[$key] = true;
                    }
                }
            });

        $staffIds = AccessControlCredential::query()
            ->where('site_id', $siteId)
            ->where('holder_type', AccessControlCredential::HOLDER_STAFF)
            ->pluck('holder_id')
            ->merge(HrEmployeeProfile::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId))
                ->pluck('user_id'))
            ->filter()
            ->unique();
        $staff = User::query()->whereKey($staffIds->all())->pluck('name')
            ->mapWithKeys(fn (string $name): array => [self::key($name) => $name])
            ->reject(fn (string $name, string $key): bool => $key === '' || isset($residents[$key]))
            ->all();

        return ['staff' => $staff, 'residents' => $residents];
    }

    private static function key(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s\'-]/u', '', $name) ?? '')));
    }

    /**
     * Every entry, unlock and refused card from the last 90 days, newest first, with names only where
     * the privacy rules allow.
     *
     * @param  array{staff: array<string, string>, residents: array<string, true>}  $people
     * @return Collection<int, array<string, mixed>>
     */
    private function events(User $viewer, Device $device, CarbonImmutable $since, array $people, CarbonImmutable $now): Collection
    {
        $custody = DeviceObservations::custodyStart($device);
        $from = $custody !== null && $custody->greaterThan($since) ? $custody : $since;

        $deviceEvents = $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
            ->where('occurred_at', '>=', $from)
            ->latest('occurred_at')
            ->limit(2000)
            ->get(['id', 'event_type', 'severity', 'payload', 'source', 'occurred_at'])
            ->map(fn (DeviceEvent $event): array => $this->row('d'.$event->id, (string) $event->event_type, $event->occurred_at, is_array($event->payload) ? $event->payload : [], $people, $now));

        $providerEvents = IntegrationEvent::query()
            ->where('canonical_device_id', $device->id)
            ->where('occurred_at', '>=', $from)
            ->latest('occurred_at')
            ->limit(2000)
            ->get(['id', 'event_type', 'severity', 'normalized_payload', 'occurred_at'])
            ->map(fn (IntegrationEvent $event): array => $this->row('i'.$event->id, (string) $event->event_type, $event->occurred_at, is_array($event->normalized_payload) ? $event->normalized_payload : [], $people, $now));

        $unlocks = DeviceCommandRequest::query()
            ->with('requestedBy:id,name')
            ->where('device_id', $device->id)
            ->where('capability', 'access.door.unlock_timed')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '>=', $from)
            ->latest('dispatched_at')
            ->limit(200)
            ->get()
            ->map(function (DeviceCommandRequest $request) use ($now): array {
                $seconds = (int) data_get($request->safe_parameter_summary, 'duration_seconds', 0);
                $named = $request->dispatched_at !== null && $request->dispatched_at->greaterThan($now->subDays(self::NAME_DAYS));

                return [
                    'key' => 'c'.$request->id,
                    'at' => $request->dispatched_at?->utc()->toIso8601String(),
                    'kind' => 'unlocked',
                    'tone' => 'info',
                    'title' => $seconds > 0 ? 'Unlocked for '.$seconds.' seconds' : 'Unlocked from Oblivion',
                    'who' => $named ? $request->requestedBy?->name : null,
                    'who_kind' => 'staff',
                    'how' => 'From Oblivion · '.Str::limit((string) $request->reason, 60),
                    'method' => 'oblivion',
                ];
            });

        return $deviceEvents->concat($providerEvents)->concat($unlocks)
            ->filter(fn (array $row): bool => $row['kind'] !== 'heartbeat')
            ->sortByDesc('at')
            ->values();
    }

    /**
     * One door event in plain words.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{staff: array<string, string>, residents: array<string, true>}  $people
     * @return array<string, mixed>
     */
    private function row(string $key, string $type, mixed $at, array $payload, array $people, CarbonImmutable $now): array
    {
        $type = strtolower(trim($type));
        $haystack = $type.' '.strtolower((string) ($payload['provider_event_type'] ?? ''));
        $method = $this->method((string) ($payload['direction'] ?? $payload['method'] ?? ''));
        $actor = is_string($payload['actor_name'] ?? null) ? trim($payload['actor_name']) : '';
        $name = self::key($actor);
        $named = $at !== null && CarbonImmutable::parse($at)->greaterThan($now->subDays(self::NAME_DAYS));
        $whoKind = match (true) {
            $actor === '' => 'none',
            isset($people['residents'][$name]) => 'resident',
            isset($people['staff'][$name]) => 'staff',
            default => 'unknown',
        };
        // A resident's comings and goings are never listed by name (D19); someone Oblivion can't place
        // might be a resident, so they show no name either.
        $who = $whoKind === 'staff' && $named ? $people['staff'][$name] : ($whoKind === 'resident' ? 'Resident fob' : null);
        $how = match (true) {
            $whoKind === 'resident' => 'Resident fob',
            $whoKind === 'staff' && $method === 'Card' => 'Staff card',
            default => $method,
        };

        [$kind, $tone, $title] = match (true) {
            str_contains($haystack, 'heartbeat') => ['heartbeat', 'none', ''],
            str_contains($haystack, 'forced') => ['forced', 'critical', 'Forced open'],
            str_contains($haystack, 'held') => ['held', 'warning', 'Held open'],
            str_contains($haystack, 'denied') || str_contains($haystack, 'refused') || str_contains($haystack, 'reject') => ['refused', 'critical', 'Card refused'],
            $type === 'door_opened' && $actor !== '' => ['came_in', 'success', match ($whoKind) {
                'staff' => $who !== null ? $who.' came in' : 'Came in',
                'resident' => 'Came in with a resident fob',
                default => 'Came in',
            }],
            $type === 'door_opened' => ['opened', 'none', 'Door opened'],
            $type === 'door_closed' => ['closed', 'none', 'Door closed'],
            in_array($type, ['door_locked', 'door.locked', 'locked'], true) => ['locked', 'success', 'Locked'],
            in_array($type, ['door_unlocked', 'door.unlocked', 'unlocked'], true) => ['unlocked', 'info', 'Unlocked'],
            default => ['other', 'info', DevicePageWords::sentence($type)],
        };

        return [
            'key' => $key,
            'at' => $at === null ? null : CarbonImmutable::parse($at)->utc()->toIso8601String(),
            'kind' => $kind,
            'tone' => $tone,
            'title' => $title,
            'who' => $who,
            'who_kind' => $whoKind,
            'how' => $how,
            'method' => $whoKind === 'resident' ? 'resident' : match ($how) {
                'Staff card', 'Card' => 'card',
                'Phone (UniFi app)' => 'phone',
                default => $kind === 'refused' ? 'refused' : 'other',
            },
        ];
    }

    private function method(string $raw): ?string
    {
        $raw = strtolower($raw);

        return match (true) {
            $raw === '' => null,
            str_contains($raw, 'nfc') || str_contains($raw, 'card') => 'Card',
            str_contains($raw, 'mobile') || str_contains($raw, 'app') || str_contains($raw, 'tap') || str_contains($raw, 'bluetooth') || str_contains($raw, 'wave') => 'Phone (UniFi app)',
            str_contains($raw, 'pin') => 'PIN',
            str_contains($raw, 'face') => 'Face',
            str_contains($raw, 'remote') || str_contains($raw, 'api') => 'Remote unlock',
            str_contains($raw, 'button') || str_contains($raw, 'exit') => 'Exit button',
            default => null,
        };
    }

    /**
     * Locked or not, and open or closed, from the door's latest reports and the read-back after
     * Oblivion's own unlocks.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function lockState(Device $device, Collection $events): array
    {
        $lockEvent = $events->first(fn (array $row): bool => in_array($row['kind'], ['locked', 'unlocked'], true));
        $positionEvent = $events->first(fn (array $row): bool => in_array($row['kind'], ['opened', 'closed', 'forced'], true));
        $readBack = DeviceCommandReconciliation::query()
            ->whereHas('request', fn ($query) => $query->where('device_id', $device->id)->where('capability', 'access.door.unlock_timed'))
            ->latest('observed_at')
            ->first(['id', 'device_command_request_id', 'observed_state', 'observed_at']);
        $readLocked = is_array($readBack?->observed_state) && is_bool($readBack->observed_state['locked'] ?? null) ? $readBack->observed_state['locked'] : null;
        $readAt = $readBack?->observed_at?->utc()->toIso8601String();

        $locked = null;
        $lockAt = null;
        if ($lockEvent !== null && ($readAt === null || $lockEvent['at'] >= $readAt)) {
            $locked = $lockEvent['kind'] === 'locked';
            $lockAt = $lockEvent['at'];
        } elseif ($readLocked !== null) {
            $locked = $readLocked;
            $lockAt = $readAt;
        }
        $position = match ($positionEvent['kind'] ?? null) {
            'forced' => 'forced',
            'opened' => 'open',
            'closed' => 'closed',
            default => null,
        };

        [$title, $tone, $chip] = match (true) {
            $position === 'forced' => ['Forced open', 'critical', ['Forced open', 'critical']],
            $locked === true && $position === 'closed' => ['Locked and closed', 'success', ['Locked', 'success']],
            $locked === true && $position === 'open' => ['Locked, but the door is open', 'warning', ['Open', 'warning']],
            $locked === true => ['Locked', 'success', ['Locked', 'success']],
            $locked === false => ['Unlocked', 'warning', ['Unlocked', 'warning']],
            $position === 'open' => ['Open', 'warning', ['Open', 'warning']],
            $position === 'closed' => ['Closed', null, ['Closed', 'neutral']],
            default => ['Lock state not reported yet', null, ['No state yet', 'neutral']],
        };
        $checkedAt = collect([$lockAt, $positionEvent['at'] ?? null])->filter()->max();

        return [
            'title' => $title,
            'tone' => $tone,
            'chip' => ['label' => $chip[0], 'tone' => $chip[1]],
            'checked_at' => $checkedAt,
            'relocks' => strtolower((string) $device->provider) === 'unifi',
        ];
    }

    /**
     * Credentials that open this door now, with their holder kind and schedule.
     *
     * @return Collection<int, array{id: int, kind: string, name: ?string, schedule: ?string}>
     */
    private function credentials(Device $device, int $siteId): Collection
    {
        $bound = AccessControlCredentialDeviceBindingEvent::query()
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->orderBy('access_credential_id')
            ->orderByDesc('sequence')
            ->get(['access_credential_id', 'binding_status', 'sequence'])
            ->unique('access_credential_id')
            ->where('binding_status', AccessControlCredentialDeviceBindingEvent::STATUS_ACTIVE)
            ->pluck('access_credential_id');
        if ($bound->isEmpty()) {
            return collect();
        }
        $now = now();

        return AccessControlCredential::query()
            ->with('schedule:id,name')
            ->whereKey($bound->all())
            ->where('site_id', $siteId)
            ->where('status', AccessControlCredential::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->get(['id', 'holder_type', 'holder_id', 'access_schedule_id'])
            ->map(fn (AccessControlCredential $credential): array => [
                'id' => (int) $credential->id,
                'kind' => $credential->holder_type === AccessControlCredential::HOLDER_CLIENT ? 'resident' : 'staff',
                // Staff only: a resident's name is never read for this page.
                'name' => $credential->holder_type === AccessControlCredential::HOLDER_STAFF ? User::query()->whereKey($credential->holder_id)->value('name') : null,
                'schedule' => $credential->schedule?->name,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  Collection<int, array<string, mixed>>  $credentials
     * @return list<array<string, mixed>>
     */
    private function meters(Collection $events, Collection $credentials, bool $canSeeEvents, CarbonImmutable $today): array
    {
        $todayRows = $events->filter(fn (array $row): bool => $row['at'] >= $today->toIso8601String());
        $held = $todayRows->where('kind', 'held');
        $in = $todayRows->filter(fn (array $row): bool => in_array($row['kind'], ['came_in', 'unlocked'], true));
        $byMethod = $in->countBy('method');
        $words = collect([
            'card' => ['card', 'cards'],
            'resident' => ['resident fob', 'resident fobs'],
            'phone' => ['phone', 'phones'],
            'oblivion' => ['unlock from Oblivion', 'unlocks from Oblivion'],
            'other' => ['other way', 'other ways'],
        ])->filter(fn (array $w, string $key): bool => ($byMethod[$key] ?? 0) > 0)
            ->map(fn (array $w, string $key): string => $byMethod[$key].' '.($byMethod[$key] === 1 ? $w[0] : $w[1]))
            ->join(', ');
        $staff = $credentials->where('kind', 'staff')->count();
        $residents = $credentials->where('kind', 'resident')->count();

        return [
            [
                'key' => 'held', 'label' => 'Held open today', 'tab' => 'events',
                'value' => $canSeeEvents ? (string) $held->count() : null,
                'caption' => $canSeeEvents
                    ? ($held->isEmpty() ? ['text' => 'Not held open today', 'at' => null] : ['text' => 'Last at {time}', 'at' => $held->first()['at']])
                    : ['text' => 'You can’t see this door’s events', 'at' => null],
                'tone' => $held->isNotEmpty() ? 'warning' : 'brand',
            ],
            [
                'key' => 'in', 'label' => 'Came in today', 'tab' => 'events',
                'value' => $canSeeEvents ? (string) $in->count() : null,
                'caption' => ['text' => ! $canSeeEvents ? 'You can’t see this door’s events' : ($words !== '' ? Str::ucfirst($words) : 'Nobody yet today'), 'at' => null],
                'tone' => 'brand',
            ],
            [
                'key' => 'cards', 'label' => 'Cards that open it', 'tab' => 'access',
                'value' => $credentials->isEmpty() ? null : (string) $credentials->count(),
                'caption' => ['text' => $credentials->isEmpty()
                    ? 'No cards are recorded for this door in Oblivion'
                    : implode(' · ', array_filter([
                        $staff > 0 ? $staff.' staff' : null,
                        $residents > 0 ? $residents.' residents’ '.($residents === 1 ? 'fob' : 'fobs') : null,
                    ])), 'at' => null],
                'tone' => 'brand',
            ],
        ];
    }

    /**
     * "Who can open it", side card: staff as a count, residents as a count of fobs (D19).
     *
     * @param  Collection<int, array<string, mixed>>  $credentials
     * @return list<array{0: string, 1: string}>
     */
    private function whoCanOpen(Collection $credentials): array
    {
        $staff = $credentials->where('kind', 'staff')->count();
        $residents = $credentials->where('kind', 'resident')->count();
        $rows = [];
        if ($staff > 0) {
            $rows[] = ['Staff', $staff.' · '.($staff === 1 ? 'card' : 'cards').' and phones'];
        }
        if ($residents > 0) {
            $rows[] = ['Residents', $residents.' '.($residents === 1 ? 'fob' : 'fobs').' · their own home, any time'];
        }
        $rows[] = ['On its own', 'Oblivion never unlocks it by schedule or automation'];

        return $rows;
    }

    /**
     * Comings and goings over the last 7 days, one number per NZ day.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array{labels: list<string>, values: list<int>}
     */
    private function week(Collection $events, CarbonImmutable $now): array
    {
        $labels = [];
        $values = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->setTimezone('Pacific/Auckland')->subDays($i)->startOfDay();
            $start = $day->utc()->toIso8601String();
            $end = $day->addDay()->utc()->toIso8601String();
            $labels[] = $day->format('D');
            $values[] = $events->filter(fn (array $row): bool => in_array($row['kind'], ['came_in', 'unlocked'], true) && $row['at'] >= $start && $row['at'] < $end)->count();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param  array<string, mixed>|null  $unlock
     * @return list<array{0: string, 1: string}>
     */
    private function about(Device $device, ?array $unlock): array
    {
        $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
        $rows = [['Reader', $model !== '' ? $model : '—']];
        if ($unlock !== null && $unlock['via'] !== null) {
            $rows[] = ['Through', $unlock['via']];
        } elseif (strtolower((string) $device->provider) === 'unifi') {
            $rows[] = ['Through', 'UniFi Access'];
        }

        return $rows;
    }

    /**
     * The Events tab: one page of 25, the range, the total, and how people came in over 7 days.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function eventsTab(Collection $events, string $range, mixed $page, CarbonImmutable $now, CarbonImmutable $today): array
    {
        $from = (match ($range) {
            'today' => $today,
            '7d' => $now->subDays(7),
            default => $now->subDays(self::NAME_DAYS),
        })->utc()->toIso8601String();
        $rows = $events->filter(fn (array $row): bool => $row['at'] >= $from)->values();
        $page = max(1, (int) (is_numeric($page) ? $page : 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, [
            'path' => '/security-devices/devices',
            'query' => array_filter(['tab' => 'events', 'range' => $range === '7d' ? null : $range]),
        ]);
        $week = $events->filter(fn (array $row): bool => $row['at'] >= $now->subDays(7)->toIso8601String());
        $ways = [
            ['key' => 'card', 'label' => 'Staff card', 'tone' => 'chart-1'],
            ['key' => 'phone', 'label' => 'Phone', 'tone' => 'chart-2'],
            ['key' => 'resident', 'label' => 'Resident fob', 'tone' => 'chart-3'],
            ['key' => 'oblivion', 'label' => 'From Oblivion', 'tone' => 'chart-4'],
            ['key' => 'refused', 'label' => 'Refused', 'tone' => 'critical'],
        ];
        $counts = $week->filter(fn (array $row): bool => in_array($row['kind'], ['came_in', 'unlocked', 'refused'], true))->countBy('method');

        return [
            'range' => $range,
            'ranges' => collect(self::RANGES)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'rows' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'ways' => array_values(array_filter(array_map(fn (array $way): array => [...$way, 'value' => (int) ($counts[$way['key']] ?? 0)], $ways), fn (array $way): bool => $way['value'] > 0)),
        ];
    }

    /**
     * "Who can open it", by group: staff named (up to three, then a count), residents only as a count.
     *
     * @param  Collection<int, array<string, mixed>>  $credentials
     * @return list<array<string, mixed>>
     */
    private function groups(Collection $credentials): array
    {
        return $credentials
            ->groupBy(fn (array $row): string => $row['kind'].'|'.($row['schedule'] ?? ''))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $resident = $first['kind'] === 'resident';
                $names = $rows->pluck('name')->filter()->unique()->values();

                return [
                    'key' => $first['kind'].'-'.($first['schedule'] ?? 'any'),
                    'group' => $resident ? 'Residents' : ($first['schedule'] ?? 'Staff'),
                    'kind' => $first['kind'],
                    'who' => $resident
                        ? $rows->count().' residents’ '.($rows->count() === 1 ? 'fob' : 'fobs')
                        : ($names->count() > 3 ? $names->take(3)->join(', ').' and '.($names->count() - 3).' more' : ($names->join(', ', ' and ') ?: $rows->count().' staff')),
                    'when' => $resident ? 'Any time · their home' : ($first['schedule'] ?? 'Any time'),
                    'count' => $rows->count(),
                ];
            })
            ->sortBy(fn (array $row): string => ($row['kind'] === 'resident' ? '1' : '0').$row['group'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $lock
     * @param  array<string, mixed>|null  $unlock
     * @return list<array{text: string, at: ?string}>
     */
    private function subline(Device $device, ?DeviceEntry $entry, ?string $siteName, array $lock, ?array $unlock): array
    {
        $parts = [['text' => $entry?->model() ?? DeviceKind::Door->label(), 'at' => null]];
        if ($siteName !== null) {
            $room = trim((string) $device->location_description);
            $parts[] = ['text' => $room !== '' ? $siteName.' · '.$room : $siteName, 'at' => null];
        }
        if ($lock['checked_at'] !== null) {
            $parts[] = ['text' => 'checked {ago}'.($unlock !== null && $unlock['via'] !== null ? ' by '.$unlock['via'] : ''), 'at' => $lock['checked_at']];
        }

        return $parts;
    }
}
