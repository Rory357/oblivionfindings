<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\DeviceUpdates;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A network device's Settings tab (S&D v13, pg-sdr-3f), in Fleet's settings pattern: titled groups
 * of short rows and switches, a Review changes bar that flags a change loosening a safety check.
 * Everything saves through routes that already exist: the device update (name, room), Move
 * (pop-up 72), Update login (pop-up 63), watching on (the offline-alert route, pop-up 9) and off
 * (each check's own deactivate route, with its reason code), and Remove device (pop-up 11; its
 * history stays). Nothing here invents a setting Oblivion doesn't keep.
 */
final class DeviceSettingsTab
{
    /** Kinds others rely on: stopping watching them leaves the devices behind them unwatched. */
    private const array RELIED_ON = [DeviceKind::Switch, DeviceKind::Router, DeviceKind::SensorGateway, DeviceKind::NetworkDevice];

    /** Check kinds in words, for "Read through". */
    private const array READ_WORDS = [
        'icmp' => 'ping',
        'tcp' => 'a service port',
        'http' => 'its web page',
        'tls' => 'its certificate',
        'dns' => 'a name lookup',
        'snmp' => 'SNMP',
        'snmp_interface' => 'SNMP',
        'ssh_inventory' => 'SSH',
        'winrm_inventory' => 'Windows remote reads',
        'provider' => 'its connected service',
        'collector' => 'the hub',
    ];

    public const string SSH_USER_PATTERN = '/\A[a-zA-Z0-9._-]{1,64}\z/';

    private const array NETWORK_KINDS = [
        DeviceKind::Switch,
        DeviceKind::Router,
        DeviceKind::WifiPoint,
        DeviceKind::NetworkDevice,
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    public static function supports(Device $device): bool
    {
        return in_array(DeviceKind::of($device), self::NETWORK_KINDS, true);
    }

    public static function tellItWhenUpdateReady(Device $device): bool
    {
        $meta = is_array($device->meta) ? $device->meta : [];

        return ($meta['tell_it_update_ready'] ?? true) !== false;
    }

    public static function sshUser(Device $device): ?string
    {
        $user = trim((string) (data_get($device->config ?? [], 'ssh.user') ?? ''));

        return $user !== '' ? $user : null;
    }

    /**
     * @param  Collection<int, Monitor>  $monitors  its enabled checks, with profile and collector
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, DeviceKind $kind, ?DeviceEntry $entry, Collection $monitors, ?int $siteId, ?string $siteName, string $noun): array
    {
        $direct = NativeMonitoringDefinitionService::directKindValues();
        $stoppable = $monitors->filter(fn (Monitor $monitor): bool => in_array($monitor->kind?->value, $direct, true))->values();
        $availability = $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Icmp && (bool) $monitor->affects_availability)->values();
        $backup = $monitors->first(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SshInventory);
        $hub = $siteId !== null ? $this->hubs->forSite($siteId) : null;
        $checkedBy = $hub !== null ? CoveringHubResolver::label($hub) : 'the Oblivion server';
        $every = $availability->map(fn (Monitor $monitor): ?int => $monitor->profile?->interval_seconds)->filter()->min();
        $dependents = $this->dependents($viewer, $device);
        $watched = $entry !== null && $entry->state !== DeviceState::NotWatched;
        $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
        $provider = strtolower(trim((string) $device->provider));
        $canEdit = $viewer->canDo('securityDevices.devices.update');
        $canTerminal = $viewer->canDo('securityDevices.terminal.use');
        $sshUser = $canTerminal ? self::sshUser($device) : null;
        $lookedAfterBy = $this->lookedAfterBy($viewer, $device);
        $readyUpdate = DeviceUpdates::available($device);
        $software = $device->firmware_version ? (string) $device->firmware_version : 'Not available';
        if ($readyUpdate !== null && $readyUpdate['next'] !== null) {
            $software = $device->firmware_version
                ? "{$device->firmware_version} · {$readyUpdate['next']} ready"
                : "{$readyUpdate['next']} ready";
        }

        return [
            'noun' => $noun,
            'name' => (string) $device->name,
            'site' => $siteName,
            'site_id' => $siteId,
            'room' => trim((string) $device->location_description) ?: null,
            'checked_by' => $siteId === null ? null : $checkedBy,
            'address' => $device->ip_address ? (string) $device->ip_address : null,
            'read_through' => $this->readThrough($monitors, $provider),
            'watching' => [
                'on' => $watched,
                // Reports from a connected service (UniFi and the like) keep it watched without checks here.
                'by_service' => $watched && $monitors->isEmpty(),
                'every' => is_int($every) && $every > 0 ? self::every($every) : null,
                'checked_by' => $checkedBy,
                'offline_alert' => $availability->isNotEmpty(),
                'offline_ids' => $availability->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'stop_ids' => $stoppable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                // Checks this tab can't stop (ports, a connected service's reports): Checks & alerts lists them.
                'other_checks' => $monitors->count() - $stoppable->count(),
                'backup' => $backup === null ? null : [
                    'every' => $backup->profile?->interval_seconds ? self::every((int) $backup->profile->interval_seconds) : null,
                ],
                'tell_it_update_ready' => self::tellItWhenUpdateReady($device),
                'relied_on' => in_array($kind, self::RELIED_ON, true) || $dependents > 0,
                'dependents' => $dependents,
            ],
            'about' => [
                ...$this->about($device, $model, $kind),
                'looked_after_by' => $lookedAfterBy,
            ],
            'staff_options' => $canEdit ? $this->staffOptions($viewer, $siteId) : [],
            // Shown and edited only by IT admins (the people who may use the terminal); never a password.
            'ssh' => $canTerminal ? ['user' => $sshUser] : null,
            'it' => array_values(array_filter([
                $device->mac_address ? ['MAC', (string) $device->mac_address] : null,
                ['Software', $software],
                ! in_array($provider, ['', 'manual', 'native', 'oblivion', 'oblivion_native'], true)
                    ? ['Managed by', match ($provider) {
                        'unifi', 'ubiquiti' => 'UniFi Network',
                        'milesight' => 'Milesight',
                        'hikvision' => 'Hikvision',
                        default => 'A connected service',
                    }]
                    : null,
                $canTerminal ? ['SSH user', $sshUser !== null ? $sshUser : 'From the saved login'] : null,
            ])),
            // The device update route needs its current kind sent back unchanged with a new name or room.
            'edit' => $canEdit ? [
                'domain' => (string) ($device->getRawOriginal('domain') ?? ''),
                'category' => (string) ($device->getRawOriginal('category') ?? ''),
                'subcategory' => $device->getRawOriginal('subcategory') !== null ? (string) $device->getRawOriginal('subcategory') : null,
            ] : null,
        ];
    }

    /**
     * Save per-device Settings extras (pg-sdr-3f): "Tell IT when an update is ready", "Looked after by",
     * and the IT-admin-only SSH user (never a password). Every change is audited.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: true, changed: list<string>}
     */
    public function save(User $actor, Device $device, array $input): array
    {
        abort_unless($actor->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless(self::supports($device), 404);

        foreach (['password', 'passwd', 'ssh_password', 'secret', 'passphrase'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw ValidationException::withMessages([
                    'ssh_user' => 'SSH user takes a username only, never a password.',
                ]);
            }
        }

        $hasUpdateReady = array_key_exists('tell_it_update_ready', $input);
        $hasLookedAfterBy = array_key_exists('looked_after_by_user_id', $input);
        $hasSshUser = array_key_exists('ssh_user', $input);

        if ($hasUpdateReady) {
            abort_unless(
                $actor->canDo('securityDevices.monitoring.manage') || $actor->canDo('securityDevices.devices.update'),
                403,
            );
            if (! is_bool($input['tell_it_update_ready']) && ! in_array($input['tell_it_update_ready'], [0, 1, '0', '1'], true)) {
                throw ValidationException::withMessages([
                    'tell_it_update_ready' => 'Choose whether IT should be told in the 6:00 am summary when an update is ready.',
                ]);
            }
        }

        $nextStaff = null;
        if ($hasLookedAfterBy) {
            abort_unless($actor->canDo('securityDevices.devices.update'), 403);
            $rawId = $input['looked_after_by_user_id'];
            if ($rawId !== null && $rawId !== '') {
                if (! is_int($rawId) && (! is_string($rawId) || ! ctype_digit($rawId)) || (int) $rawId < 1) {
                    throw ValidationException::withMessages([
                        'looked_after_by_user_id' => 'Choose a staff member from your Sites.',
                    ]);
                }
                $nextStaff = $this->findStaffOption($actor, (int) $rawId);
                if ($nextStaff === null) {
                    throw ValidationException::withMessages([
                        'looked_after_by_user_id' => 'Choose an active staff member from your Sites.',
                    ]);
                }
            }
        }

        $nextSshUser = null;
        if ($hasSshUser) {
            abort_unless($actor->canDo('securityDevices.terminal.use'), 403);
            $rawSsh = $input['ssh_user'];
            if ($rawSsh !== null && ! is_string($rawSsh)) {
                throw ValidationException::withMessages([
                    'ssh_user' => 'Enter an SSH username (letters, numbers, dots, hyphens or underscores only — never a password).',
                ]);
            }
            $trimmed = trim((string) ($rawSsh ?? ''));
            if ($trimmed !== '') {
                if (preg_match(self::SSH_USER_PATTERN, $trimmed) !== 1
                    || in_array(strtolower($trimmed), ['password', 'passwd', 'secret', 'passphrase'], true)) {
                    throw ValidationException::withMessages([
                        'ssh_user' => 'Enter an SSH username (letters, numbers, dots, hyphens or underscores only, up to 64 characters — never a password).',
                    ]);
                }
                $nextSshUser = $trimmed;
            }
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);
            $siteId = $siteId > 0 ? $siteId : null;
        } catch (Throwable) {
            $siteId = null;
        }

        return DB::transaction(function () use (
            $actor,
            $device,
            $siteId,
            $hasUpdateReady,
            $hasLookedAfterBy,
            $hasSshUser,
            $input,
            $nextStaff,
            $nextSshUser,
        ): array {
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $config = is_array($locked->config) ? $locked->config : [];
            $changes = [];

            if ($hasUpdateReady) {
                $previous = self::tellItWhenUpdateReady($locked);
                $next = filter_var($input['tell_it_update_ready'], FILTER_VALIDATE_BOOLEAN);
                if ($previous !== $next) {
                    $meta['tell_it_update_ready'] = $next;
                    if ($next) {
                        unset($meta['update_ready_notified_version']);
                    }
                    $changes['tell_it_update_ready'] = ['from' => $previous, 'to' => $next];
                }
            }

            if ($hasLookedAfterBy) {
                $previousId = is_numeric($meta['looked_after_by_user_id'] ?? null) ? (int) $meta['looked_after_by_user_id'] : null;
                $nextId = $nextStaff['id'] ?? null;
                if ($previousId !== $nextId) {
                    $previousStaff = $previousId !== null ? $this->findStaffOption($actor, $previousId) : null;
                    if ($nextId === null) {
                        unset($meta['looked_after_by_user_id']);
                    } else {
                        $meta['looked_after_by_user_id'] = $nextId;
                    }
                    $changes['looked_after_by_user_id'] = [
                        'from' => $previousId,
                        'to' => $nextId,
                        'from_label' => $previousStaff['label'] ?? null,
                        'to_label' => $nextStaff['label'] ?? null,
                    ];
                }
            }

            if ($hasSshUser) {
                $previousSsh = self::sshUser($locked);
                if ($previousSsh !== $nextSshUser) {
                    $ssh = is_array($config['ssh'] ?? null) ? $config['ssh'] : [];
                    if ($nextSshUser === null) {
                        unset($ssh['user']);
                    } else {
                        $ssh['user'] = $nextSshUser;
                    }
                    if ($ssh === []) {
                        unset($config['ssh']);
                    } else {
                        $config['ssh'] = $ssh;
                    }
                    $changes['ssh_user'] = [
                        'from' => $previousSsh,
                        'to' => $nextSshUser,
                    ];
                }
            }

            if ($changes === []) {
                return ['ok' => true, 'changed' => []];
            }

            $locked->forceFill([
                'meta' => $meta !== [] ? $meta : null,
                'config' => $config !== [] ? $config : null,
            ])->saveQuietly();

            $auditMeta = [
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'fields' => array_keys($changes),
            ];
            if (isset($changes['tell_it_update_ready'])) {
                $auditMeta['before'] = ['is_enabled' => (bool) $changes['tell_it_update_ready']['from']];
                $auditMeta['after'] = ['is_enabled' => (bool) $changes['tell_it_update_ready']['to']];
            }

            AuditLogger::logOrFail('security_devices.device.settings_updated', $locked, $auditMeta);

            return ['ok' => true, 'changed' => array_keys($changes)];
        }, 3);
    }

    private static function every(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => 'every '.$seconds.' s',
            $seconds === 60 => 'every minute',
            $seconds < 3600 => 'every '.intdiv($seconds, 60).' min',
            $seconds === 3600 => 'every hour',
            $seconds < 86_400 => 'every '.intdiv($seconds, 3600).' h',
            $seconds === 86_400 => 'every day',
            default => 'every '.intdiv($seconds, 86_400).' days',
        };
    }

    /** @param Collection<int, Monitor> $monitors */
    private function readThrough(Collection $monitors, string $provider): ?string
    {
        $words = $monitors->map(fn (Monitor $monitor): ?string => self::READ_WORDS[$monitor->kind?->value ?? ''] ?? null)->filter()->unique()->values()->all();
        if (in_array($provider, ['unifi', 'ubiquiti'], true)) {
            array_unshift($words, 'UniFi Network');
        }
        $words = array_values(array_unique($words));
        if ($words === []) {
            return null;
        }
        if (count($words) === 1) {
            return ucfirst($words[0]);
        }
        $last = array_pop($words);

        return ucfirst(implode(', ', $words).' and '.$last);
    }

    /** Devices the viewer can see that are wired behind it. */
    private function dependents(User $viewer, Device $device): int
    {
        $ids = $device->childRelationships()->pluck('child_device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return $ids === [] ? 0 : $this->access->visibleDevices($viewer)->whereKey($ids)->count();
    }

    /** @return array{model: string, added_at: ?string, added_by: ?string, from_scan: bool} */
    private function about(Device $device, string $model, DeviceKind $kind): array
    {
        $adopted = DiscoveryCandidate::query()
            ->where('canonical_device_id', $device->id)
            ->where('review_action', 'adopted')
            ->orderBy('reviewed_at')
            ->first(['reviewed_by_user_id', 'reviewed_at']);
        $byId = $adopted?->reviewed_by_user_id ?? $device->created_by_user_id;
        $by = $byId ? User::query()->whereKey($byId)->value('name') : null;
        $at = $adopted?->reviewed_at ?? $device->created_at;

        return [
            'model' => $model !== '' ? $model : $kind->label(),
            'added_at' => $at?->toIso8601String(),
            'added_by' => is_string($by) && $by !== '' ? $by : null,
            'from_scan' => $adopted !== null,
        ];
    }

    /** @return array{id: int, label: string}|null */
    private function lookedAfterBy(User $viewer, Device $device): ?array
    {
        $raw = is_array($device->meta) ? ($device->meta['looked_after_by_user_id'] ?? null) : null;
        if (! is_numeric($raw) || (int) $raw < 1) {
            return null;
        }
        $option = $this->findStaffOption($viewer, (int) $raw);

        return $option === null ? null : ['id' => $option['id'], 'label' => $option['label']];
    }

    /**
     * Active approved staff at the viewer's Sites, with people covering this device's Site listed first.
     *
     * @return list<array{id: int, name: string, sub: string, label: string}>
     */
    private function staffOptions(User $viewer, ?int $siteId): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return [];
        }
        $profiles = $this->scopedStaffProfiles($siteIds)
            ->limit(300)
            ->get(['user_id', 'primary_site_id', 'secondary_site_ids', 'position_title']);
        if ($profiles->isEmpty()) {
            return [];
        }
        $names = User::query()
            ->whereIn('id', $profiles->pluck('user_id')->all())
            ->whereNotNull('approved_at')
            ->pluck('name', 'id');

        return $profiles
            ->filter(fn (HrEmployeeProfile $profile): bool => $names->has($profile->user_id))
            ->map(function (HrEmployeeProfile $profile) use ($names, $siteId): array {
                $coversDeviceSite = $siteId !== null && (
                    (int) $profile->primary_site_id === $siteId
                    || in_array($siteId, array_map('intval', (array) ($profile->secondary_site_ids ?? [])), true)
                );

                return [
                    'order' => $coversDeviceSite ? 0 : 1,
                    ...self::formatStaffOption((int) $profile->user_id, (string) $names->get($profile->user_id), $profile->position_title),
                ];
            })
            ->sortBy(fn (array $row): string => $row['order'].'|'.mb_strtolower($row['name']).'|'.$row['id'])
            ->map(function (array $row): array {
                unset($row['order']);

                return $row;
            })
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string, sub: string, label: string}|null */
    private function findStaffOption(User $viewer, int $userId): ?array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return null;
        }
        $profile = $this->scopedStaffProfiles($siteIds)
            ->where('user_id', $userId)
            ->first(['user_id', 'position_title']);
        if (! $profile instanceof HrEmployeeProfile) {
            return null;
        }
        $name = User::query()
            ->whereKey($userId)
            ->whereNotNull('approved_at')
            ->value('name');
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return self::formatStaffOption($userId, $name, $profile->position_title);
    }

    /** @param list<int> $siteIds */
    private function scopedStaffProfiles(array $siteIds): \Illuminate\Database\Eloquent\Builder
    {
        return HrEmployeeProfile::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where(function ($dates): void {
                $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($dates): void {
                $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today());
            })
            ->where(function ($sites) use ($siteIds): void {
                $sites->whereIn('primary_site_id', $siteIds);
                foreach ($siteIds as $id) {
                    $sites->orWhereJsonContains('secondary_site_ids', $id);
                }
            });
    }

    /** @return array{id: int, name: string, sub: string, label: string} */
    private static function formatStaffOption(int $id, string $name, ?string $positionTitle): array
    {
        $title = trim((string) ($positionTitle ?? ''));

        return [
            'id' => $id,
            'name' => $name,
            'sub' => $title !== '' ? $title : 'Staff',
            'label' => $title !== '' ? "{$name}, {$title}" : $name,
        ];
    }
}
