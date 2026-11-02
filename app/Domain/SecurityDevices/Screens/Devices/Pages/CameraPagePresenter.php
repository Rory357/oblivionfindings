<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyHours;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Cctv\Services\CctvAuthority;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Domain\SecurityDevices\Cctv\Services\CctvSessions;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The camera's page (S&D rebuild Phase E, drawings pg-sdr-4, 4b and 4c): Live · Recordings ·
 * Checks & alerts · Settings. The History tab (3e) is round 3 and not approved, so it isn't built.
 *
 * Privacy first: a reason before every live view (D6), the latest picture only when someone asks
 * for it, and during the camera's privacy hours no picture at all, enforced by every picture
 * endpoint (CameraPrivacy), not just this page. Everything is scoped by SecurityDevicesAccessService
 * before it gets here; a number with no data behind it is null ("Not available"). Only the open
 * tab's data is read.
 */
final class CameraPagePresenter
{
    public const array TAB_LABELS = [
        'live' => 'Live',
        'recordings' => 'Recordings',
        'checks' => 'Checks & alerts',
        'settings' => 'Settings',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly DeviceChecksTab $checks,
        private readonly CameraPrivacy $privacy,
    ) {}

    public static function serves(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::Camera;
    }

    /**
     * @param  array{tab?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $siteId = $entry?->siteId;
        $siteName = $entry?->siteName;
        $retired = $state === DeviceState::Retired;

        $canSeeEvents = $viewer->canDo('securityDevices.events.view');
        $canSeePicture = $viewer->canDo('securityDevices.cctv.media.view');
        $canSeeClips = $viewer->canDo(CctvRecordingReferences::VIEW);

        $settings = $this->privacy->settings((int) $device->id);
        $until = $this->privacy->until((int) $device->id, $now);
        $next = $settings['enabled'] && $until === null ? CameraPrivacy::nextStart($settings['ranges'], $now) : null;

        $monitors = $device->monitors()
            ->where('is_enabled', true)
            ->with([
                'profile:id,name,interval_seconds,stale_after_seconds,failure_confirmations,failure_duration_seconds',
                'collector:id,name,site_id,status',
            ])
            ->orderBy('name')
            ->get();

        $tabs = array_values(array_filter([
            'live',
            $canSeeClips ? 'recordings' : null,
            $canSeeEvents ? 'checks' : null,
            'settings',
        ]));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'live';

        $source = CctvSource::query()->where('camera_id', $device->id)->where('enabled', true)->orderByDesc('revision')->first();
        $recorder = $this->recorder($viewer, $device, $source);
        $live = $canSeePicture && ! $retired ? $this->live($viewer, $device, $source, $until !== null) : null;

        $can = [
            'edit' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
            'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
            'upload' => false,
            'move' => $viewer->canDo('securityDevices.devices.assign'),
            'assign' => $viewer->canDo('securityDevices.devices.assign'),
            'retire' => $viewer->canDo('securityDevices.devices.delete') && ! $retired,
            'see_events' => $canSeeEvents,
            'see_picture' => $canSeePicture && ! $retired,
            'watch_live' => (bool) ($live['available'] ?? false),
            'see_clips' => $canSeeClips,
            // The pop-up reads the recorder link again before it saves; this only decides the button.
            'save_clip' => $viewer->canDo(CctvRecordingReferences::CREATE) && ! $retired && $source?->provider === 'unifi',
            'privacy' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
        ];

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::Camera->value,
                'kind_label' => DeviceKind::Camera->label(),
                'group' => 'camera',
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $siteName,
                'site_id' => $siteId,
                'address' => $device->ip_address ? (string) $device->ip_address : null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($viewer, $device, $entry, $monitors, $siteName),
                'back' => ['href' => '/security-devices/devices?tab=cameras', 'label' => 'Devices'],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'meters' => $this->meters($viewer, $device, $entry, $state, $until, $next, $recorder, $canSeeEvents, $canSeeClips, $now),
            'can' => $can,
            'actions' => [
                'ping' => $device->ip_address !== null && $device->ip_address !== '',
                'backup' => null,
                'remote_href' => null,
                'recorder_href' => $recorder !== null ? '/security-devices/devices/'.$recorder['id'] : null,
            ],
            'privacy' => [
                'enabled' => $settings['enabled'],
                'ranges' => $settings['ranges'],
                'active' => $until !== null,
                'until' => $until?->toIso8601String(),
                'next_start' => $next?->toIso8601String(),
            ],
            'live' => $live,
            'overview' => $tab === 'live' ? $this->overview($viewer, $device, $recorder, $source, $canSeeEvents, $canSeePicture) : null,
            'recordings' => $tab === 'recordings' ? $this->recordings($viewer, $device, $recorder) : null,
            'checks' => $tab === 'checks'
                ? [
                    ...$this->checks->present($viewer, $device, DeviceKind::Camera, $monitors, $siteId, $siteName, DeviceObservations::custodyStart($device), $now),
                    'backups' => [],
                ]
                : null,
            'settings' => $tab === 'settings' ? $this->settings($device, $siteId, $siteName, $recorder) : null,
        ];
    }

    /**
     * Model · Site · spot · who checked it and when. Times are filled in on the page.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return list<array{text: string, at: ?string}>
     */
    private function subline(User $viewer, Device $device, ?DeviceEntry $entry, Collection $monitors, ?string $siteName): array
    {
        $parts = [['text' => $entry?->model() ?? DeviceKind::Camera->label(), 'at' => null]];
        if ($siteName !== null) {
            $spot = trim((string) $device->location_description);
            $parts[] = ['text' => $spot !== '' ? $siteName.' · '.$spot : $siteName, 'at' => null];
        }
        $by = $this->checkedBy($viewer, $monitors);
        if ($entry?->state === DeviceState::Offline && $entry->offlineSince !== null) {
            $parts[] = ['text' => 'offline since {time}', 'at' => $entry->offlineSince->toIso8601String()];
        } elseif ($entry?->lastCheckedAt !== null) {
            $parts[] = ['text' => 'checked {ago} by '.$by, 'at' => $entry->lastCheckedAt->toIso8601String()];
        }

        return $parts;
    }

    /** @param Collection<int, Monitor> $monitors */
    private function checkedBy(User $viewer, Collection $monitors): string
    {
        $monitor = DeviceObservations::availabilityMonitors($monitors)->first() ?? $monitors->first();
        if (! $monitor?->collector instanceof MonitoringCollector) {
            return 'the Oblivion server';
        }

        return in_array((int) $monitor->collector->site_id, $this->access->accessibleSiteIds($viewer), true)
            ? CoveringHubResolver::label($monitor->collector)
            : 'its hub';
    }

    /**
     * The header numbers: the picture, where it records, motion today. value null = "Not available".
     *
     * @param  array{id: int, name: string, model: string, state: string}|null  $recorder
     * @return list<array<string, mixed>>
     */
    private function meters(User $viewer, Device $device, ?DeviceEntry $entry, DeviceState $state, ?CarbonImmutable $until, ?CarbonImmutable $next, ?array $recorder, bool $canSeeEvents, bool $canSeeClips, CarbonImmutable $now): array
    {
        $picture = match (true) {
            $until !== null => self::meter('picture', 'Picture', 'Off', ['text' => 'Privacy hours until {time}', 'at' => $until->toIso8601String()], 'brand', 'settings'),
            $state === DeviceState::Online => self::meter('picture', 'Picture', 'On', $next !== null
                ? ['text' => 'Privacy hours from {time}', 'at' => $next->toIso8601String()]
                : ['text' => $entry?->lastCheckedAt ? 'Camera answered {ago}' : 'Camera answering', 'at' => $entry?->lastCheckedAt?->toIso8601String()], 'success', 'live'),
            $state === DeviceState::Offline => self::meter('picture', 'Picture', 'No picture', $entry?->offlineSince
                ? ['text' => 'Offline since {time}', 'at' => $entry->offlineSince->toIso8601String()]
                : ['text' => 'The camera isn’t answering', 'at' => null], 'critical', 'live'),
            default => self::meter('picture', 'Picture', null, ['text' => $state === DeviceState::CantCheck ? 'Can’t check: something in front of it is down' : 'Not checked by Oblivion', 'at' => null], 'brand', 'live'),
        };

        $recording = self::meter('recording', 'Recording', $recorder['name'] ?? null, [
            'text' => $recorder !== null ? 'Clips stay on the recorder' : 'No recorder linked to it',
            'at' => null,
        ], 'brand', $canSeeClips ? 'recordings' : 'live');

        $motion = self::meter('motion', 'Motion today', null, ['text' => 'You can’t see this camera’s events', 'at' => null], 'brand', 'live');
        if ($canSeeEvents) {
            $events = fn () => $this->access
                ->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                ->where('event_type', 'like', '%motion%');
            $last = $events()->latest('occurred_at')->first(['occurred_at']);
            $dayStart = $now->setTimezone(CameraPrivacy::ZONE)->startOfDay()->utc();
            $today = $last === null ? 0 : $events()->where('occurred_at', '>=', $dayStart)->count();
            $motion = $last === null
                ? self::meter('motion', 'Motion today', null, ['text' => 'Not reported by this camera', 'at' => null], 'brand', 'live')
                : self::meter('motion', 'Motion today', (string) $today, ['text' => 'Last at {time}', 'at' => $last->occurred_at?->toIso8601String()], 'brand', 'live');
        }

        return [$picture, $recording, $motion];
    }

    /**
     * @param  array{text: string, at: ?string}  $caption
     * @return array<string, mixed>
     */
    private static function meter(string $key, string $label, ?string $value, array $caption, string $tone, string $tab): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'from' => null, 'ago' => null, 'caption' => $caption, 'tone' => $tone, 'tab' => $tab, 'bar' => null];
    }

    /**
     * The recorder it records to: from its camera source, or a link to a recorder. Visible ones only.
     *
     * @return array{id: int, name: string, model: string, state: string}|null
     */
    private function recorder(User $viewer, Device $device, ?CctvSource $source): ?array
    {
        $ids = collect([$source?->recorder_device_id])
            ->merge($device->parentRelationships()->pluck('parent_device_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return null;
        }
        $entry = $this->directory->entries($viewer, null, $ids->all())
            ->first(fn (DeviceEntry $row): bool => $row->kind === DeviceKind::Recorder);

        return $entry === null ? null : [
            'id' => $entry->id(),
            'name' => $entry->name(),
            'model' => $entry->model(),
            'state' => $entry->state->label(),
        ];
    }

    /**
     * Whether live viewing can start, from today's CCTV session owner (it checks the viewer, the
     * camera source and the Site hub). Never available inside privacy hours.
     *
     * @return array<string, mixed>
     */
    private function live(User $viewer, Device $device, ?CctvSource $source, bool $private): array
    {
        $via = null;
        if ($source?->collector_id !== null) {
            $collector = MonitoringCollector::query()->find($source->collector_id);
            $via = $collector instanceof MonitoringCollector ? CoveringHubResolver::label($collector) : null;
        }
        $base = ['available' => false, 'reason' => 'not_set_up', 'actor_id' => (int) $viewer->id, 'source_revision' => null, 'start_href' => null, 'via' => $via, 'max_seconds' => null];
        if ($private) {
            return [...$base, 'reason' => CameraPrivacy::REFUSAL];
        }
        try {
            $request = Request::create('/security-devices/devices/'.$device->id.'/live', 'GET');
            $request->setUserResolver(fn () => $viewer);
            $page = app(CctvSessions::class)->page($request, (int) $device->id);
        } catch (Throwable) {
            return [...$base, 'reason' => 'unavailable'];
        }
        $ready = (bool) data_get($page, 'live.available') && (bool) data_get($page, 'live.can_start');

        return [
            ...$base,
            'available' => $ready,
            'reason' => $ready ? null : match (data_get($page, 'live.reason_code')) {
                CameraPrivacy::REFUSAL => CameraPrivacy::REFUSAL,
                'authority_lost' => 'no_access',
                default => $source === null ? 'not_set_up' : 'unavailable',
            },
            'source_revision' => $ready ? (int) data_get($page, 'live.source_revision') : null,
            'start_href' => $ready ? data_get($page, 'live.start_href') : null,
            'max_seconds' => (int) data_get($page, 'live.max_session_seconds'),
        ];
    }

    /**
     * The Live tab: what happened lately (who watched live and why, motion, events) and the facts.
     *
     * @param  array{id: int, name: string, model: string, state: string}|null  $recorder
     * @return array<string, mixed>
     */
    private function overview(User $viewer, Device $device, ?array $recorder, ?CctvSource $source, bool $canSeeEvents, bool $canSeePicture): array
    {
        $events = $canSeeEvents
            ? $this->access->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                ->latest('occurred_at')
                ->limit(6)
                ->get(['id', 'event_type', 'severity', 'occurred_at', 'source'])
                ->map(fn (DeviceEvent $event): array => DevicePageWords::event($event))
            : collect();

        // Who watched live, with the reason they gave (D6). Only for people who may see the picture.
        $views = $canSeePicture
            ? CctvLiveSession::query()
                ->where('camera_id', $device->id)
                ->latest('id')
                ->limit(6)
                ->get(['id', 'actor_id', 'purpose', 'created_at'])
            : collect();
        $names = $views->isEmpty() ? collect() : User::query()->whereKey($views->pluck('actor_id')->unique()->all())->pluck('name', 'id');
        $viewLines = $views->map(fn (CctvLiveSession $view): array => [
            'key' => 'live-'.$view->id,
            'at' => $view->created_at?->toIso8601String(),
            'tone' => 'info',
            'title' => 'Watching live: '.($names[$view->actor_id] ?? 'a former user'),
            'sub' => 'Reason: '.Str::lcfirst((string) $view->purpose),
        ]);

        $privacyLines = $this->privacyLines($device);

        $address = trim(implode(' · ', array_filter([(string) $device->ip_address, (string) $device->mac_address])));
        $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
        $lastView = $views->first();

        return [
            'events' => $events->concat($viewLines)->concat($privacyLines)
                ->sortByDesc(fn (array $item): string => (string) $item['at'])
                ->take(6)
                ->values()
                ->all(),
            'last_view' => $lastView === null ? null : [
                'at' => $lastView->created_at?->toIso8601String(),
                'who' => $names[$lastView->actor_id] ?? 'a former user',
                'reason' => (string) $lastView->purpose,
            ],
            'facts' => [
                ['Model', $model !== '' ? $model : '—'],
                ['Spot', trim((string) $device->location_description) ?: '—'],
                ['Recorder', $recorder !== null ? $recorder['name'] : 'Not linked'],
                ['Software', $device->firmware_version ? (string) $device->firmware_version : 'Not available'],
            ],
            'it' => [
                ['Connects by', $this->connectsBy($device, $source)],
                ['Address', $address !== '' ? $address : '—'],
                ['Live viewing', $source === null ? 'Not set up' : 'Set up'],
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function privacyLines(Device $device): Collection
    {
        $row = CameraPrivacyHours::query()->where('device_id', $device->id)->first(['id']);
        if ($row === null) {
            return collect();
        }
        $logs = AuditLog::query()
            ->where('action', CameraPrivacy::AUDIT_ACTION)
            ->where('auditable_type', (new CameraPrivacyHours)->getMorphClass())
            ->where('auditable_id', $row->id)
            ->latest('id')
            ->limit(3)
            ->get(['id', 'user_id', 'meta', 'created_at']);
        $names = User::query()->whereKey($logs->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return $logs->map(function (AuditLog $log) use ($names): array {
            $meta = is_array($log->meta) ? $log->meta : [];
            $was = (bool) data_get($meta, 'before.enabled');
            $is = (bool) data_get($meta, 'after.enabled');

            return [
                'key' => 'privacy-'.$log->id,
                'at' => $log->created_at?->toIso8601String(),
                'tone' => 'brand',
                'title' => match (true) {
                    ! $was && $is => 'Privacy hours turned on',
                    $was && ! $is => 'Privacy hours turned off',
                    default => 'Privacy hours changed',
                },
                'sub' => 'By '.($names[$log->user_id] ?? 'someone no longer here'),
            ];
        });
    }

    private function connectsBy(Device $device, ?CctvSource $source): string
    {
        $provider = strtolower(trim((string) ($source?->provider ?? $device->provider)));

        return match (true) {
            str_starts_with($provider, 'unifi') => 'UniFi Protect',
            str_starts_with($provider, 'hikvision') => 'Hikvision',
            $provider === 'onvif' => 'ONVIF',
            in_array($provider, ['', 'manual', 'native', 'oblivion', 'oblivion_native'], true) => 'Added by hand',
            default => 'A connected service',
        };
    }

    /**
     * The Recordings tab: clip references (where a clip is on the recorder; the video stays there)
     * read through today's recording-reference authority, newest first.
     *
     * @param  array{id: int, name: string, model: string, state: string}|null  $recorder
     * @return array<string, mixed>
     */
    private function recordings(User $viewer, Device $device, ?array $recorder): array
    {
        $clips = [];
        $unavailable = false;
        try {
            $clips = DB::transaction(function () use ($viewer, $device): array {
                $actor = app(CctvAuthority::class)->actor((int) $viewer->id, false, true);
                abort_unless($actor->canDo(CctvRecordingReferences::VIEW), 404, 'unavailable');
                [, $siteId] = app(CctvAuthority::class)->referenceDevice($actor, (int) $device->id);
                $recorders = $this->access->visibleDevicesForSite($actor, $siteId)->where('devices.status', '!=', 'decommissioned')->select('devices.id');

                return CctvRecordingReference::query()
                    ->where('camera_id', $device->id)
                    ->where('site_id', $siteId)
                    ->whereIn('recorder_device_id', $recorders)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(function (CctvRecordingReference $row): ?array {
                        $meta = $row->metadata;
                        if (! is_array($meta) || ! hash_equals((string) $row->metadata_hash, CctvContract::hash($meta)) || ($meta['reference_uuid'] ?? null) !== $row->uuid) {
                            return null;
                        }

                        return [
                            'key' => (string) $row->uuid,
                            'start' => (string) $meta['requested_start_at'],
                            'end' => (string) $meta['requested_end_at'],
                            'why' => (string) ($meta['purpose'] ?? ''),
                            'by' => (string) data_get($meta, 'created_by.name', ''),
                            'href' => '/security-devices/cctv/recording-references/'.$row->uuid,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (Throwable) {
            $unavailable = true;
        }

        return [
            'clips' => $clips,
            'unavailable' => $unavailable,
            'facts' => [
                ['Recorder', $recorder !== null ? trim($recorder['name'].($recorder['model'] !== '' ? ' · '.$recorder['model'] : '')) : 'Not linked'],
                ['Recorder state', $recorder['state'] ?? 'Not available'],
                ['Keeps', 'Not read from the recorder'],
            ],
        ];
    }

    /**
     * The Settings tab (4c): the camera's details for the form, its recorder and login, and who can
     * see its pictures.
     *
     * @param  array{id: int, name: string, model: string, state: string}|null  $recorder
     * @return array<string, mixed>
     */
    private function settings(Device $device, ?int $siteId, ?string $siteName, ?array $recorder): array
    {
        $roles = Role::query()->with('permissions:id,key')->get();
        $holders = function (string $key) use ($roles): string {
            $names = $roles
                ->filter(fn (Role $role): bool => $role->permissions->contains('key', $key))
                ->map(fn (Role $role): string => (string) ($role->label ?: ucfirst(str_replace('_', ' ', (string) $role->name))))
                ->values()
                ->all();

            return match (count($names)) {
                0 => 'Nobody yet',
                1 => $names[0],
                default => implode(', ', array_slice($names, 0, -1)).' and '.end($names),
            };
        };

        return [
            'camera' => [
                'name' => (string) $device->name,
                'site_id' => $siteId,
                'site' => $siteName,
                'location' => (string) ($device->location_description ?? ''),
                'address' => (string) ($device->ip_address ?? ''),
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'notes' => $device->notes,
                'domain' => $device->domain instanceof \BackedEnum ? $device->domain->value : (string) $device->domain,
                'category' => (string) $device->category,
                'subcategory' => $device->subcategory,
            ],
            'recorder' => $recorder !== null ? $recorder['name'] : null,
            'login' => $this->login($device, $siteId),
            'who' => [
                ['Live', $holders('securityDevices.cctv.live.watch')],
                ['Recordings', $holders(CctvRecordingReferences::VIEW)],
            ],
        ];
    }

    private function login(Device $device, ?int $siteId): string
    {
        $provider = strtolower(trim((string) $device->provider));
        $key = match (true) {
            str_starts_with($provider, 'unifi') => 'unifi_protect',
            str_starts_with($provider, 'hikvision') => 'hikvision',
            default => null,
        };
        if ($key === null || $siteId === null) {
            return 'Not needed or kept elsewhere · see Setup › Services & logins';
        }
        $saved = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', $key)
            ->where('status', CredentialReferenceStatus::Active)
            ->exists();

        return $saved ? 'Saved · stored encrypted in Oblivion' : 'Not saved yet · add it in Setup › Services & logins';
    }
}
