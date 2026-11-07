<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * S&D Phase G (pg-sdr-2d, pop-up 62): which devices have newer software from the maker, only from
 * what a source really said. Today that is UniFi: its device list says "updateAvailable" and which
 * version (meta.firmware_status and meta.firmware_update_version, kept by the UniFi sync). The
 * RouterOS and Hikvision update checks aren't built, so those devices are never listed as up to date
 * or out of date; the page says they can't be checked here yet.
 *
 * Oblivion shows updates and doesn't install them: pop-up 62 opens UniFi.
 */
final class DeviceUpdates
{
    public const string UNIFI_URL = 'https://unifi.ui.com';

    /** Kinds whose software a maker's update check could cover (UniFi, RouterOS, Hikvision). */
    private const array UPDATABLE = [
        DeviceKind::Switch, DeviceKind::Router, DeviceKind::WifiPoint, DeviceKind::NetworkDevice,
        DeviceKind::Camera, DeviceKind::Recorder, DeviceKind::Hub,
    ];

    /** Whether a source tells Oblivion about this device's updates at all. */
    public static function reports(Device $device): bool
    {
        $meta = is_array($device->meta) ? $device->meta : [];

        return self::unifi($device) && is_string($meta['firmware_status'] ?? null) && trim($meta['firmware_status']) !== '';
    }

    /**
     * The update a source says is ready, or null.
     *
     * @return array{source: string, source_label: string, maker: string, current: ?string, next: ?string, notes: null, current_since: ?string, install: array{label: string, href: string}}|null
     */
    public static function available(Device $device): ?array
    {
        $meta = is_array($device->meta) ? $device->meta : [];
        if (! self::unifi($device) || ($meta['firmware_status'] ?? null) !== 'updateAvailable') {
            return null;
        }
        $current = is_scalar($device->firmware_version) && trim((string) $device->firmware_version) !== '' ? trim((string) $device->firmware_version) : null;
        $next = is_scalar($meta['firmware_update_version'] ?? null) ? trim((string) $meta['firmware_update_version']) : '';
        $observed = is_array($device->provider_observed_state) ? $device->provider_observed_state : [];
        $since = data_get($observed, 'firmware_version.source') === 'unifi' ? data_get($observed, 'firmware_version.observed_at') : null;

        return [
            'source' => 'unifi',
            'source_label' => 'UniFi',
            'maker' => 'Ubiquiti',
            'current' => $current,
            // A version UniFi named that isn't the one it's on; otherwise "a newer version".
            'next' => $next !== '' && $next !== $current ? $next : null,
            // UniFi's device list doesn't carry the maker's notes; nothing else stores them.
            'notes' => null,
            'current_since' => is_string($since) ? CarbonImmutable::parse($since)->toIso8601String() : null,
            'install' => ['label' => 'Open in UniFi', 'href' => self::UNIFI_URL],
        ];
    }

    /**
     * Devices › Upkeep › Updates: the rows, how many devices can't be checked here, and the plan for
     * each (its quiet time, or why it can't be checked now).
     *
     * @param  Collection<int, DeviceEntry>  $entries  already scoped to what the viewer may see
     * @return array{source: bool, rows: list<array<string, mixed>>, unchecked: int}
     */
    public function present(User $viewer, Collection $entries, CarbonImmutable $now): array
    {
        $ids = $entries->map(fn (DeviceEntry $entry): int => $entry->id())->all();
        // The directory doesn't read when a version was first seen; only listed devices need it.
        $candidates = $entries->filter(fn (DeviceEntry $entry): bool => self::unifi($entry->device)
            && data_get($entry->device->meta, 'firmware_status') === 'updateAvailable');
        $observed = $candidates->isEmpty() ? collect() : Device::query()
            ->whereKey($candidates->map(fn (DeviceEntry $entry): int => $entry->id())->all())
            ->pluck('provider_observed_state', 'id');

        $quiet = $this->quietTimes($candidates, $now);
        $rows = [];
        foreach ($candidates as $entry) {
            $device = $entry->device;
            $device->setAttribute('provider_observed_state', $observed->get($entry->id()));
            $update = self::available($device);
            if ($update === null) {
                continue;
            }
            $rows[] = [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'model' => $entry->model(),
                'kind' => $entry->kind->value,
                'domain' => (string) $device->domain,
                'where' => $entry->siteName,
                'site_id' => $entry->siteId,
                'state' => $entry->state->value,
                'badge' => ['label' => $entry->state->label(), 'tone' => $entry->state->tone()],
                'href' => '/security-devices/devices/'.$entry->id(),
                ...$update,
                'plan' => $this->plan($entry, $quiet[$entry->id()] ?? null),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['plan']['order'], mb_strtolower($a['name'])] <=> [$b['plan']['order'], mb_strtolower($b['name'])]);

        $unchecked = $entries->filter(fn (DeviceEntry $entry): bool => in_array($entry->kind, self::UPDATABLE, true)
            && $entry->state !== DeviceState::Retired
            && ! self::reports($entry->device))->count();

        return [
            'source' => $ids !== [] && $entries->contains(fn (DeviceEntry $entry): bool => self::reports($entry->device)),
            'rows' => $rows,
            'unchecked' => $unchecked,
        ];
    }

    /**
     * For a device page: the same row, or null when no source says an update is ready.
     *
     * @return array<string, mixed>|null
     */
    public function forPage(Device $device, ?DeviceEntry $entry, CarbonImmutable $now): ?array
    {
        $update = self::available($device);
        if ($update === null || $entry === null) {
            return null;
        }
        $quiet = $this->quietTimes(collect([$entry]), $now);

        return [
            'id' => (int) $device->id,
            'name' => (string) $device->name,
            'model' => $entry->model(),
            'kind' => $entry->kind->value,
            'domain' => (string) $device->domain,
            'where' => $entry->siteName,
            'site_id' => $entry->siteId,
            'state' => $entry->state->value,
            'badge' => ['label' => $entry->state->label(), 'tone' => $entry->state->tone()],
            'href' => '/security-devices/devices/'.$device->id,
            ...$update,
            'plan' => $this->plan($entry, $quiet[$entry->id()] ?? null),
        ];
    }

    /**
     * @param  array{starts_at: ?string, ends_at: ?string, by: ?string, now: bool}|null  $quiet
     * @return array{label: string, tone: string, note: string, at: ?string, order: int, quiet: array{starts_at: ?string, ends_at: ?string, by: ?string, now: bool}|null}
     */
    private function plan(DeviceEntry $entry, ?array $quiet): array
    {
        if (in_array($entry->state, [DeviceState::Offline, DeviceState::CantCheck], true)) {
            $since = $entry->offlineSince?->toIso8601String();

            return [
                'label' => DeviceState::CantCheck->label(),
                'tone' => 'neutral',
                'note' => match (true) {
                    $entry->behind !== null => $entry->behind.' is offline · update in UniFi',
                    $since !== null => 'Offline since {time} · update in UniFi',
                    default => 'Offline · update in UniFi',
                },
                'at' => $entry->behind === null ? $since : null,
                'order' => 2,
                'quiet' => $quiet,
            ];
        }
        if ($quiet !== null) {
            return [
                'label' => $quiet['now'] ? 'Quiet time now' : 'Quiet time {time}',
                'tone' => 'info',
                'note' => ($quiet['by'] !== null ? 'Quiet time set by '.$quiet['by'] : 'Quiet time set').' · update in UniFi',
                'at' => $quiet['now'] ? null : $quiet['starts_at'],
                'order' => 0,
                'quiet' => $quiet,
            ];
        }

        return ['label' => 'Not planned', 'tone' => 'neutral', 'note' => 'Update in UniFi', 'at' => null, 'order' => 1, 'quiet' => null];
    }

    /**
     * The quiet time that covers each device now, or its next one this week: one for the device, one
     * of its checks, or one for its whole Site (as the device's Checks tab reads it).
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<int, array{starts_at: ?string, ends_at: ?string, by: ?string, now: bool}>
     */
    private function quietTimes(Collection $entries, CarbonImmutable $now): array
    {
        $siteIds = $entries->map(fn (DeviceEntry $entry): ?int => $entry->siteId)->filter()->unique()->values()->all();
        if ($siteIds === []) {
            return [];
        }
        $windows = MonitoringMaintenanceWindow::query()
            ->where('status', 'active')
            ->whereIn('site_id', $siteIds)
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(7))
            ->orderBy('starts_at')
            ->get(['id', 'site_id', 'device_id', 'monitor_id', 'starts_at', 'ends_at', 'created_by_user_id']);
        $names = User::query()->whereKey($windows->pluck('created_by_user_id')->filter()->unique()->all())->pluck('name', 'id');

        $found = [];
        foreach ($entries as $entry) {
            $window = $windows->first(fn (MonitoringMaintenanceWindow $window): bool => (int) $window->site_id === (int) $entry->siteId
                && (($window->device_id === null && $window->monitor_id === null)
                    || (int) $window->device_id === $entry->id()
                    || in_array((int) $window->monitor_id, array_map('intval', $entry->monitorIds), true)));
            if ($window === null) {
                continue;
            }
            $found[$entry->id()] = [
                'starts_at' => $window->starts_at?->toIso8601String(),
                'ends_at' => $window->ends_at?->toIso8601String(),
                'by' => $window->created_by_user_id ? ($names[$window->created_by_user_id] ?? null) : null,
                'now' => $window->starts_at !== null && $window->starts_at->lte($now),
            ];
        }

        return $found;
    }

    private static function unifi(Device $device): bool
    {
        return in_array(strtolower(trim((string) $device->provider)), ['unifi', 'ubiquiti'], true);
    }
}
