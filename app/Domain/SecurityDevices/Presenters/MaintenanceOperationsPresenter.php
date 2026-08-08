<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;

class MaintenanceOperationsPresenter
{
    private const ROW_LIMIT = 200;

    /** @var list<string> */
    public const TYPES = [
        'scheduled_service',
        'repair',
        'firmware_update',
        'configuration_change',
        'inspection',
        'replacement',
        'calibration',
        'connectivity_check',
        'battery_replacement',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function present(User $viewer, array $filters = []): array
    {
        $visibleDevices = $this->access->visibleDevices($viewer)
            ->with(['assignments' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();
        $siteContext = $this->siteContext($visibleDevices);
        $sitesByDevice = $visibleDevices->mapWithKeys(fn (Device $device): array => [
            $device->id => $this->siteForDevice($device, $siteContext),
        ]);
        $records = $visibleDevices->isEmpty()
            ? collect()
            : DeviceMaintenanceRecord::query()
                ->whereIn('device_id', $visibleDevices->pluck('id'))
                ->with(['device:id,name,device_uid,domain,category', 'performedBy:id,name'])
                ->orderByRaw('scheduled_for IS NULL')
                ->orderBy('scheduled_for')
                ->orderByDesc('created_at')
                ->get();
        $mapped = $records->map(fn (DeviceMaintenanceRecord $record): array => $this->mapRecord(
            $record,
            $sitesByDevice->get($record->device_id),
        ));
        $activeTab = $this->tab($filters['tab'] ?? null);
        $filtered = $this->filterRows($mapped, $filters, $activeTab);
        $shown = $filtered->take(self::ROW_LIMIT)->values();
        $today = now()->startOfDay();
        $dueSoon = now()->addDays(14)->endOfDay();

        return [
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'due', 'label' => 'Due & overdue'],
                ['key' => 'planned', 'label' => 'Planned'],
                ['key' => 'in-progress', 'label' => 'In progress'],
                ['key' => 'completed', 'label' => 'Completed'],
                ['key' => 'calibration', 'label' => 'Calibration'],
                ['key' => 'firmware-configuration', 'label' => 'Firmware & configuration'],
            ],
            'active_tab' => $activeTab,
            'boundary' => [
                'title' => 'One operational maintenance record',
                'description' => 'Servicing, inspection, calibration, repair, battery, connectivity, firmware, and approved configuration work stay attached to the canonical device and site.',
                'finance_note' => 'Costs shown in legacy maintenance history are informational. Finance remains the authoritative financial record.',
            ],
            'summary' => [
                'total' => $records->count(),
                'overdue' => $records->filter(fn (DeviceMaintenanceRecord $record): bool => $record->status === 'scheduled'
                    && $record->scheduled_for?->lt($today))->count(),
                'due_soon' => $records->filter(fn (DeviceMaintenanceRecord $record): bool => $record->status === 'scheduled'
                    && $record->scheduled_for?->betweenIncluded($today, $dueSoon))->count(),
                'planned' => $records->filter(fn (DeviceMaintenanceRecord $record): bool => $record->status === 'scheduled'
                    && (! $record->scheduled_for || $record->scheduled_for->gt($dueSoon)))->count(),
                'in_progress' => $records->where('status', 'in_progress')->count(),
                'completed' => $records->where('status', 'completed')->count(),
                'calibration' => $records->where('type', 'calibration')->count(),
                'firmware_configuration' => $records->whereIn('type', ['firmware_update', 'configuration_change'])->count(),
            ],
            'records' => $shown,
            'inventory' => [
                'total' => $filtered->count(),
                'shown' => $shown->count(),
                'truncated' => $filtered->count() > self::ROW_LIMIT,
            ],
            'filters' => [
                'search' => $this->stringFilter($filters['search'] ?? null),
                'status' => $this->stringFilter($filters['status'] ?? null),
                'type' => $this->stringFilter($filters['type'] ?? null),
                'site_id' => $this->integerFilter($filters['site_id'] ?? null),
                'device_id' => $this->integerFilter($filters['device_id'] ?? null),
                'domain' => $this->domainFilter($filters['domain'] ?? null),
            ],
            'filter_options' => [
                'statuses' => ['open', 'overdue', 'scheduled', 'in_progress', 'completed', 'cancelled'],
                'types' => self::TYPES,
                'sites' => $sitesByDevice->filter()->unique('id')->sortBy('name')->map(fn (Site $site): array => [
                    'value' => $site->id,
                    'label' => $site->name,
                ])->values(),
                'devices' => $visibleDevices->map(fn (Device $device): array => [
                    'value' => $device->id,
                    'label' => $device->name,
                ])->values(),
            ],
            'permissions' => [
                'manage' => $viewer->canDo('securityDevices.maintenance.manage'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mapRecord(DeviceMaintenanceRecord $record, ?Site $site): array
    {
        $today = now()->startOfDay();
        $dueSoon = now()->addDays(14)->endOfDay();
        $scheduleState = match (true) {
            $record->status === 'completed' => 'completed',
            $record->status === 'in_progress' => 'in_progress',
            $record->status === 'cancelled' => 'cancelled',
            ! $record->scheduled_for => 'planned_unscheduled',
            $record->scheduled_for->lt($today) => 'overdue',
            $record->scheduled_for->lte($dueSoon) => 'due_soon',
            default => 'planned',
        };

        return [
            'id' => $record->id,
            'type' => $record->type,
            'status' => $record->status,
            'schedule_state' => $scheduleState,
            'description' => $record->description,
            'scheduled_for' => $record->scheduled_for?->toDateString(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'performed_by' => $record->performedBy?->name,
            'vendor_reference' => $record->vendor_reference,
            'device' => [
                'id' => $record->device_id,
                'name' => $record->device?->name,
                'href' => "/security-devices/devices/{$record->device_id}",
                'domain' => $record->device?->domain,
                'category' => $record->device?->category,
            ],
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'href' => "/security-devices/sites/{$site->id}",
            ] : null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @param array<string, mixed> $filters */
    private function filterRows(Collection $rows, array $filters, string $activeTab): Collection
    {
        $search = mb_strtolower($this->stringFilter($filters['search'] ?? null) ?? '');
        $status = $this->stringFilter($filters['status'] ?? null);
        $type = $this->stringFilter($filters['type'] ?? null);
        $siteId = $this->integerFilter($filters['site_id'] ?? null);
        $deviceId = $this->integerFilter($filters['device_id'] ?? null);
        $domain = $this->domainFilter($filters['domain'] ?? null);

        return $rows->filter(function (array $row) use ($search, $status, $type, $siteId, $deviceId, $domain, $activeTab): bool {
            $matchesTab = match ($activeTab) {
                'due' => in_array($row['schedule_state'], ['overdue', 'due_soon'], true),
                'planned' => in_array($row['schedule_state'], ['planned', 'planned_unscheduled'], true),
                'in-progress' => $row['status'] === 'in_progress',
                'completed' => $row['status'] === 'completed',
                'calibration' => $row['type'] === 'calibration',
                'firmware-configuration' => in_array($row['type'], ['firmware_update', 'configuration_change'], true),
                default => true,
            };
            $matchesStatus = match ($status) {
                'open' => ! in_array($row['status'], ['completed', 'cancelled'], true),
                'overdue' => $row['schedule_state'] === 'overdue',
                null => true,
                default => $row['status'] === $status,
            };

            return $matchesTab
                && ($search === '' || str_contains(mb_strtolower(implode(' ', [
                    $row['description'],
                    $row['device']['name'],
                    $row['site']['name'] ?? '',
                    $row['vendor_reference'] ?? '',
                ])), $search))
                && $matchesStatus
                && (! $type || $row['type'] === $type)
                && (! $siteId || ($row['site']['id'] ?? null) === $siteId)
                && (! $deviceId || $row['device']['id'] === $deviceId)
                && (! $domain || $row['device']['domain'] === $domain);
        })->values();
    }

    private function domainFilter(mixed $value): ?string
    {
        $domain = $this->stringFilter($value);

        return $domain !== null && DeviceDomain::tryFrom($domain) !== null
            ? $domain
            : null;
    }

    /** @param Collection<int, Device> $devices @return array<string, Collection> */
    private function siteContext(Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $siteIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_SITE)->pluck('assignable_id')->unique();
        $roomIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_ROOM)->pluck('assignable_id')->unique();

        return [
            'sites' => $siteIds->isEmpty() ? collect() : Site::query()->whereIn('id', $siteIds)->get(['id', 'name'])->keyBy('id'),
            'rooms' => $roomIds->isEmpty() ? collect() : SiteRoom::query()->whereIn('id', $roomIds)->with('site:id,name')->get()->keyBy('id'),
        ];
    }

    /** @param array<string, Collection> $context */
    private function siteForDevice(Device $device, array $context): ?Site
    {
        $assignment = $device->assignments->first(fn (DeviceAssignment $candidate): bool => in_array(
            $candidate->assignable_type,
            [DeviceAssignment::TARGET_SITE, DeviceAssignment::TARGET_ROOM],
            true,
        ));

        if (! $assignment) {
            return null;
        }

        return $assignment->assignable_type === DeviceAssignment::TARGET_SITE
            ? $context['sites']->get($assignment->assignable_id)
            : $context['rooms']->get($assignment->assignable_id)?->site;
    }

    private function tab(mixed $value): string
    {
        $tab = $this->stringFilter($value) ?? 'overview';

        return in_array($tab, ['overview', 'due', 'planned', 'in-progress', 'completed', 'calibration', 'firmware-configuration'], true)
            ? $tab
            : 'overview';
    }

    private function stringFilter(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && $value !== 'all' ? trim($value) : null;
    }

    private function integerFilter(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }
}
