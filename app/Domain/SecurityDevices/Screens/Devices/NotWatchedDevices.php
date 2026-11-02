<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DeviceLogbook;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Devices › Upkeep › Not watched (S&D v13, pg-sdr-2e): devices Oblivion knows but doesn't check,
 * each with why and since when. The why is the reason kept when watching stopped (the stored
 * reason code in plain words, NativeMonitoringDefinitionService) or how it was added without
 * checks, plus the device's own note. Only the devices DeviceDirectory gives this viewer; a
 * reason written at a Site the viewer can't see is never shown.
 */
final class NotWatchedDevices
{
    public const int PER_PAGE = 25;

    /** How far back "Watching changed lately" looks. */
    private const int RECENT_DAYS = 30;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * @param  Collection<int, DeviceEntry>  $filtered  the viewer's devices after the page's filters
     * @param  Collection<int, DeviceEntry>  $scoped  the viewer's devices at the chosen Site (for the recent log)
     * @return array<string, mixed>
     */
    public function present(User $viewer, Collection $filtered, Collection $scoped, int $page): array
    {
        $entries = $filtered
            ->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::NotWatched)
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower((string) $entry->siteName).'|'.mb_strtolower($entry->name()))
            ->values();
        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $slice = $entries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $accessible = $this->access->accessibleSiteIds($viewer);

        $ids = $slice->map(fn (DeviceEntry $entry): int => $entry->id())->all();
        $stops = $this->lastStops($ids, $accessible);
        $added = $this->added($ids);
        $links = $this->links($ids, $scoped);
        $names = $this->names([
            ...array_map(fn (array $stop): int => $stop['user_id'], $stops),
            ...array_map(fn (array $row): int => $row['by'], $added),
        ]);

        return [
            'rows' => $slice->map(function (DeviceEntry $entry) use ($stops, $added, $links, $names): array {
                $id = $entry->id();
                $stop = $stops[$id] ?? null;
                $add = $added[$id] ?? null;
                $note = trim((string) $entry->device->notes);
                $note = $note === '' ? null : mb_strimwidth($note, 0, 140, '…');
                $who = $stop !== null ? ($names[$stop['user_id']] ?? null) : ($add !== null ? ($names[$add['by']] ?? null) : null);

                $why = match (true) {
                    $stop !== null => trim(($who !== null ? 'Stopped by '.$who : 'Watching stopped').($stop['reason'] !== null ? ' · '.$stop['reason'] : '')),
                    $add !== null && $add['scan'] => 'Added from a scan'.($who !== null ? ' by '.$who : '').', never watched',
                    $who !== null => 'Added by '.$who.' with checks off',
                    default => 'Added with checks off',
                };

                return [
                    'id' => $id,
                    'name' => $entry->name(),
                    'model' => $entry->model(),
                    'kind' => $entry->kind->value,
                    'state' => $entry->state->value,
                    'sub' => $entry->model().(isset($links[$id]) ? ' · '.$links[$id] : ''),
                    'where' => implode(' · ', array_filter([$entry->siteName, trim((string) $entry->device->location_description) ?: null])) ?: null,
                    'why' => $why,
                    'note' => $note,
                    'since' => $stop !== null ? $stop['at'] : ($add['at'] ?? null),
                    'seen_at' => $entry->lastSeenAt()?->toIso8601String(),
                    'site' => $entry->siteName,
                    'site_id' => $entry->siteId,
                    'address' => $entry->device->ip_address,
                    'watching' => 'Nothing',
                    'href' => '/security-devices/devices/'.$id,
                ];
            })->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
                'to' => min($total, $page * self::PER_PAGE),
            ],
            'recent' => $this->recent($scoped, $accessible),
        ];
    }

    /**
     * Each device's last "watching stopped", with the reason code in words. A stop recorded at a
     * Site the viewer can't see is left out.
     *
     * @param  list<int>  $deviceIds
     * @param  list<int>  $accessible
     * @return array<int, array{at: ?string, user_id: int, reason: ?string}>
     */
    private function lastStops(array $deviceIds, array $accessible): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $monitorDevices = Monitor::query()->whereIn('device_id', $deviceIds)->pluck('device_id', 'id');
        if ($monitorDevices->isEmpty()) {
            return [];
        }
        $out = [];
        AuditLog::query()
            ->where('auditable_type', (new Monitor)->getMorphClass())
            ->whereIn('auditable_id', $monitorDevices->keys()->all())
            ->where('action', 'monitoring.monitor.deactivated')
            ->latest('created_at')
            ->latest('id')
            ->get(['id', 'user_id', 'auditable_id', 'meta', 'created_at'])
            ->each(function (AuditLog $audit) use ($monitorDevices, $accessible, &$out): void {
                $deviceId = (int) ($monitorDevices[$audit->auditable_id] ?? 0);
                $site = data_get($audit->meta, 'site_id');
                if ($deviceId === 0 || isset($out[$deviceId])) {
                    return;
                }
                if (is_numeric($site) && ! in_array((int) $site, $accessible, true)) {
                    // The latest stop was at a Site this viewer can't see: say only that watching stopped.
                    $out[$deviceId] = ['at' => null, 'user_id' => 0, 'reason' => null];

                    return;
                }
                $out[$deviceId] = [
                    'at' => $audit->created_at?->toIso8601String(),
                    'user_id' => (int) $audit->user_id,
                    'reason' => DeviceLogbook::REASON_WORDS[(string) data_get($audit->meta, 'reason_code')] ?? null,
                ];
            });

        return $out;
    }

    /**
     * When and how each device came to Oblivion: from a scan (an adopted discovery candidate) or
     * added by hand.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, array{at: ?string, by: int, scan: bool}>
     */
    private function added(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $adopted = DiscoveryCandidate::query()
            ->whereIn('canonical_device_id', $deviceIds)
            ->where('review_action', 'adopted')
            ->orderBy('reviewed_at')
            ->get(['canonical_device_id', 'reviewed_by_user_id', 'reviewed_at'])
            ->keyBy('canonical_device_id');

        return Device::query()
            ->whereKey($deviceIds)
            ->get(['id', 'created_at', 'created_by_user_id'])
            ->mapWithKeys(function (Device $device) use ($adopted): array {
                $candidate = $adopted->get($device->id);

                return [(int) $device->id => [
                    'at' => ($candidate?->reviewed_at ?? $device->created_at)?->toIso8601String(),
                    'by' => (int) ($candidate?->reviewed_by_user_id ?? $device->created_by_user_id ?? 0),
                    'scan' => $candidate !== null,
                ]];
            })
            ->all();
    }

    /**
     * "on Aurora House switch · port 16", from the switch's own link when the viewer can see the switch.
     *
     * @param  list<int>  $deviceIds
     * @param  Collection<int, DeviceEntry>  $scoped
     * @return array<int, string>
     */
    private function links(array $deviceIds, Collection $scoped): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $names = $scoped->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $out = [];
        DeviceRelationship::query()
            ->active()
            ->whereIn('child_device_id', $deviceIds)
            ->whereNotNull('port')
            ->orderBy('id')
            ->get(['parent_device_id', 'child_device_id', 'port'])
            ->each(function (DeviceRelationship $link) use ($names, &$out): void {
                $parent = $names[(int) $link->parent_device_id] ?? null;
                if ($parent === null || isset($out[(int) $link->child_device_id])) {
                    return;
                }
                $port = trim((string) $link->port);
                $out[(int) $link->child_device_id] = 'on '.$parent.($port !== '' ? ' · port '.$port : '');
            });

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * Watching changed lately: checks stopped or started on the viewer's devices, and devices added
     * without checks, in the last 30 days, newest first.
     *
     * @param  Collection<int, DeviceEntry>  $scoped
     * @param  list<int>  $accessible
     * @return list<array<string, mixed>>
     */
    private function recent(Collection $scoped, array $accessible): array
    {
        $since = CarbonImmutable::now()->subDays(self::RECENT_DAYS);
        $byId = $scoped->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        if ($byId->isEmpty()) {
            return [];
        }
        $monitorDevices = Monitor::query()->whereIn('device_id', $byId->keys()->all())->pluck('device_id', 'id');
        $lines = collect();

        if ($monitorDevices->isNotEmpty()) {
            $audits = AuditLog::query()
                ->where('auditable_type', (new Monitor)->getMorphClass())
                ->whereIn('auditable_id', $monitorDevices->keys()->all())
                ->whereIn('action', ['monitoring.monitor.created', 'monitoring.monitor.deactivated', 'monitoring.monitor.reactivated'])
                ->where('created_at', '>=', $since)
                ->latest('created_at')
                ->latest('id')
                ->limit(60)
                ->get(['id', 'user_id', 'action', 'auditable_id', 'meta', 'created_at']);
            $names = $this->names($audits->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all());
            $lines = $audits
                ->filter(function (AuditLog $audit) use ($accessible): bool {
                    $site = data_get($audit->meta, 'site_id');

                    return ! is_numeric($site) || in_array((int) $site, $accessible, true);
                })
                ->groupBy(fn (AuditLog $audit): string => ($monitorDevices[$audit->auditable_id] ?? 0).'|'.$audit->action.'|'.$audit->created_at?->format('Y-m-d H:i'))
                ->map(function (Collection $group) use ($monitorDevices, $byId, $names): ?array {
                    /** @var AuditLog $audit */
                    $audit = $group->first();
                    $entry = $byId->get((int) ($monitorDevices[$audit->auditable_id] ?? 0));
                    if ($entry === null || $audit->created_at === null) {
                        return null;
                    }
                    $stopped = $audit->action === 'monitoring.monitor.deactivated';
                    $reason = DeviceLogbook::REASON_WORDS[(string) data_get($audit->meta, 'reason_code')] ?? null;

                    return [
                        'key' => 'watch-'.$audit->id,
                        'at' => $audit->created_at->toIso8601String(),
                        'tone' => $stopped ? 'none' : 'success',
                        'title' => $entry->name().' · '.($stopped ? 'watching stopped' : ($audit->action === 'monitoring.monitor.reactivated' ? 'watched again' : 'watching started')),
                        'sub' => implode(' · ', array_filter([$names[(int) $audit->user_id] ?? null, $entry->siteName, $stopped ? $reason : null])) ?: null,
                    ];
                })
                ->filter()
                ->values();
        }

        // Devices added in the last 30 days that still have no checks.
        $notWatched = $scoped->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::NotWatched)->map(fn (DeviceEntry $entry): int => $entry->id())->values()->all();
        $added = $this->added($notWatched);
        $names = $this->names(array_map(fn (array $row): int => $row['by'], $added));
        foreach ($added as $id => $row) {
            if ($row['at'] === null || CarbonImmutable::parse($row['at'])->lessThan($since)) {
                continue;
            }
            $entry = $byId->get($id);
            $lines->push([
                'key' => 'added-'.$id,
                'at' => $row['at'],
                'tone' => 'none',
                'title' => $entry->name().' added, not watched',
                'sub' => implode(' · ', array_filter([$names[$row['by']] ?? null, $row['scan'] ? 'from a scan of '.($entry->siteName ?? 'its Site') : $entry->siteName])) ?: null,
            ]);
        }

        return $lines->sortByDesc('at')->take(5)->values()->all();
    }
}
