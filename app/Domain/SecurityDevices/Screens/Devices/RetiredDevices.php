<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DeviceLogbook;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DevicePagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Devices › Upkeep › Retired (S&D v13, pg-sdr-2f). One meaning only (Stephan, 1 Nov): Remove
 * device takes a device off every list and its history stays, read-only
 * (DeviceRegistryService::decommission keeps it). There is no Retire action and nothing here puts a
 * device back. A removed device is listed only when its last place was one of the viewer's Sites,
 * under a kind of assignment they may see; its logbook keeps only the lines from while it was at
 * one of their Sites (DeviceCustody).
 */
final class RetiredDevices
{
    public const int PER_PAGE = 25;

    /** Lines sent for the chosen device; the page shows the first few, Whole history the rest. */
    public const int LINES = 60;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceLogbook $logbook,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, ?int $siteId, string $search, int $page, mixed $selected): array
    {
        $base = $this->query($viewer, $siteId, $search);
        $total = (clone $base)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $devices = (clone $base)
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'name', 'domain', 'category', 'subcategory', 'manufacturer', 'model', 'location_description', 'notes', 'deleted_at', 'status']);

        $ids = $devices->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $places = $this->lastPlaces($ids);
        $removedBy = $this->removedBy($ids);
        $kept = $this->kept($ids, $places);

        $rows = $devices->map(function (Device $device) use ($places, $removedBy, $kept): array {
            $id = (int) $device->id;
            $kind = DeviceKind::of($device);
            $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
            $note = trim((string) $device->notes);
            $place = $places[$id] ?? null;

            return [
                'id' => $id,
                'name' => (string) $device->name,
                'kind' => $kind->value,
                'model' => $model !== '' ? $model : $kind->label(),
                'sub' => implode(' · ', array_filter([$model !== '' ? $model : $kind->label(), $note !== '' ? mb_strimwidth($note, 0, 80, '…') : null])),
                'was_at' => implode(' · ', array_filter([$place['where'] ?? null, trim((string) $device->location_description) ?: null])) ?: null,
                'removed_at' => ($removedBy[$id]['at'] ?? null) ?? $device->deleted_at?->toIso8601String(),
                'by' => $removedBy[$id]['by'] ?? null,
                'kept' => $kept[$id] ?? 'Logbook',
            ];
        })->values()->all();

        $chosen = null;
        $selectedId = is_numeric($selected) ? (int) $selected : ($rows[0]['id'] ?? null);
        if ($selectedId !== null) {
            $device = (clone $base)->whereKey($selectedId)->first();
            if ($device instanceof Device) {
                $place = $places[(int) $device->id] ?? $this->lastPlaces([(int) $device->id])[(int) $device->id] ?? null;
                $removal = $removedBy[(int) $device->id] ?? $this->removedBy([(int) $device->id])[(int) $device->id] ?? null;
                $chosen = [
                    'id' => (int) $device->id,
                    'name' => (string) $device->name,
                    'removed_at' => $removal['at'] ?? $device->deleted_at?->toIso8601String(),
                    'by' => $removal['by'] ?? null,
                    'lines' => $this->logbook->lines(
                        $viewer,
                        $device,
                        $place['site_id'] ?? null,
                        'all',
                        null,
                        self::LINES,
                        DevicePagePresenter::group(DeviceKind::of($device)) === 'network',
                    ),
                ];
            }
        }

        return [
            'rows' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
                'to' => min($total, $page * self::PER_PAGE),
            ],
            'selected' => $chosen,
        ];
    }

    /**
     * How many devices were removed from one of the viewer's Sites, with the first few names (a Site's
     * Devices tab, 12g). The same rule as the Retired list: their last place was that Site.
     *
     * @return array{count: int, names: list<string>}
     */
    public function atSite(User $viewer, int $siteId, int $names = 3): array
    {
        $query = $this->query($viewer, $siteId, '');
        $count = (clone $query)->count();

        return [
            'count' => $count,
            'names' => $count === 0 ? [] : $query->orderBy('name')->limit($names)->pluck('name')->map(fn (mixed $name): string => (string) $name)->all(),
        ];
    }

    /**
     * Removed devices whose last place was one of the viewer's Sites (or the chosen one).
     *
     * @return Builder<Device>
     */
    private function query(User $viewer, ?int $siteId, string $search): Builder
    {
        $sites = $this->access->accessibleSiteIds($viewer);
        if ($siteId !== null) {
            $sites = in_array($siteId, $sites, true) ? [$siteId] : [];
        }
        $types = DeviceCustody::targetTypes($viewer);
        $query = Device::onlyTrashed()->where('status', DeviceStatus::Decommissioned->value);
        if ($sites === []) {
            return $query->whereRaw('1 = 0');
        }
        $last = static fn (string $column): string => "(SELECT last_custody.{$column} FROM device_assignments AS last_custody
            WHERE last_custody.device_id = devices.id
            ORDER BY last_custody.assigned_at DESC, last_custody.id DESC LIMIT 1)";
        $query->whereRaw($last('custody_site_id').' IN ('.implode(',', array_fill(0, count($sites), '?')).')', $sites)
            ->whereRaw($last('assignable_type').' IN ('.implode(',', array_fill(0, count($types), '?')).')', $types);

        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(model, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(manufacturer, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(location_description, \'\')) like ?', [$like]));
        }

        return $query;
    }

    /**
     * Each device's last place in words: the Site, and its room when it was in one. A person or a
     * vehicle is never named.
     *
     * @param  list<int>  $ids
     * @return array<int, array{site_id: int, where: string}>
     */
    private function lastPlaces(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $last = DeviceAssignment::query()
            ->whereIn('device_id', $ids)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get(['id', 'device_id', 'assignable_type', 'assignable_id', 'custody_site_id'])
            ->unique('device_id')
            ->keyBy('device_id');
        $sites = Site::query()->whereKey($last->pluck('custody_site_id')->filter()->unique()->all())->pluck('name', 'id');
        $roomIds = $last->where('assignable_type', DeviceAssignment::TARGET_ROOM)->pluck('assignable_id')->filter()->unique()->all();
        $rooms = $roomIds === [] ? collect() : SiteRoom::query()->whereKey($roomIds)->pluck('name', 'id');

        return $last->map(fn (DeviceAssignment $assignment): array => [
            'site_id' => (int) $assignment->custody_site_id,
            'where' => implode(' · ', array_filter([
                (string) ($sites[$assignment->custody_site_id] ?? 'Its Site'),
                $assignment->assignable_type === DeviceAssignment::TARGET_ROOM ? ($rooms[$assignment->assignable_id] ?? null) : null,
            ])),
        ])->all();
    }

    /**
     * Who removed each device and when, from the removal's own audit line.
     *
     * @param  list<int>  $ids
     * @return array<int, array{at: ?string, by: ?string}>
     */
    private function removedBy(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $audits = AuditLog::query()
            ->where('auditable_type', (new Device)->getMorphClass())
            ->whereIn('auditable_id', $ids)
            ->where('action', 'device.decommissioned')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'auditable_id', 'created_at'])
            ->unique('auditable_id');
        $names = User::query()->whereKey($audits->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return $audits->mapWithKeys(fn (AuditLog $audit): array => [(int) $audit->auditable_id => [
            'at' => $audit->created_at?->toIso8601String(),
            'by' => $audit->user_id !== null && isset($names[$audit->user_id]) ? (string) $names[$audit->user_id] : null,
        ]])->all();
    }

    /**
     * What's kept, from what really exists for each device: its logbook always, then readings,
     * alerts, settings backups, clip references, its viewing log and documents.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{site_id: int, where: string}>  $places
     * @return array<int, string>
     */
    private function kept(array $ids, array $places): array
    {
        if ($ids === []) {
            return [];
        }
        $monitors = Monitor::query()->whereIn('device_id', $ids)->pluck('device_id', 'id');
        $readings = $monitors->isEmpty() ? collect() : MonitorObservation::query()
            ->whereIn('monitor_id', $monitors->keys()->all())
            ->distinct()
            ->pluck('monitor_id')
            ->map(fn (mixed $monitorId): int => (int) $monitors[$monitorId])
            ->unique();
        $alerts = DeviceAlert::query()->whereIn('device_id', $ids)->distinct()->pluck('device_id')->map(fn (mixed $id): int => (int) $id);
        $backups = ConfigurationSnapshot::query()->whereIn('device_id', $ids)->get(['device_id', 'site_id'])
            ->filter(fn (ConfigurationSnapshot $copy): bool => (int) $copy->site_id === ($places[(int) $copy->device_id]['site_id'] ?? -1))
            ->pluck('device_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $clips = DB::table('cctv_recording_references')->whereIn('camera_id', $ids)->distinct()->pluck('camera_id')->map(fn (mixed $id): int => (int) $id);
        $viewing = DB::table('cctv_live_sessions')->whereIn('camera_id', $ids)->distinct()->pluck('camera_id')->map(fn (mixed $id): int => (int) $id);
        $documents = DeviceDocument::query()->available()->whereIn('device_id', $ids)->distinct()->pluck('device_id')->map(fn (mixed $id): int => (int) $id);

        $out = [];
        foreach ($ids as $id) {
            $parts = array_values(array_filter([
                $readings->contains($id) ? 'readings' : null,
                $alerts->contains($id) ? 'alerts' : null,
                $backups->contains($id) ? 'settings backups' : null,
                $clips->contains($id) ? 'clip references' : null,
                $viewing->contains($id) ? 'viewing log' : null,
                $documents->contains($id) ? 'documents' : null,
            ]));
            $out[$id] = self::sentence(['Logbook', ...$parts]);
        }

        return $out;
    }

    /** @param list<string> $parts "Logbook, readings and settings backups" */
    private static function sentence(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? 'Logbook';
        }
        $lastPart = array_pop($parts);

        return implode(', ', $parts).' and '.$lastPart;
    }
}
