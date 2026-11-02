<?php

namespace App\Domain\SecurityDevices\Reports;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Support\LazyCollection;

/**
 * "Export" on the Devices and Alerts lists, and Overview › Reports › Other downloads (pg-sdr-18b).
 * They replace today's /security-devices/reports page with the same three CSVs, built and scoped
 * exactly as its ReportsController builds them: the device list (optionally only chosen devices),
 * device events for the last 90 days (in their Site custody at the time) and the upkeep log.
 * The controller streams them and neutralises formulas in every cell.
 */
final class ListExports
{
    public const int EVENTS_WINDOW_DAYS = 90;

    public const int MAX_IDS = 500;

    public const array DEVICE_COLUMNS = [
        'id', 'device_uid', 'name', 'domain', 'category', 'subcategory', 'manufacturer', 'model', 'serial_number',
        'mac_address', 'imei', 'asset_tag', 'firmware_version', 'status', 'health_status', 'provider', 'battery_level',
        'last_seen_at', 'last_signal_at', 'next_service_due', 'created_at',
    ];

    public const array EVENT_COLUMNS = ['id', 'device_id', 'device_name', 'event_type', 'severity', 'source', 'occurred_at', 'processed_at'];

    public const array UPKEEP_COLUMNS = [
        'id', 'device_id', 'device_name', 'type', 'status', 'description', 'scheduled_for', 'completed_at', 'performed_by',
        'vendor_reference', 'cost',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * @param  list<int>|null  $ids  only these devices (the Devices list's selection)
     * @return LazyCollection<int, list<mixed>>
     */
    public function devices(User $user, ?array $ids = null): LazyCollection
    {
        return $this->access->visibleDevices($user)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->cursor()
            ->map(function (Device $device): array {
                $row = [];
                foreach (self::DEVICE_COLUMNS as $column) {
                    $value = $device->{$column} ?? null;
                    if ($value instanceof \BackedEnum) {
                        $value = $value->value;
                    }
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    }
                    $row[] = $value ?? '';
                }

                return $row;
            });
    }

    /**
     * @param  array{domain?: string|null, device_id?: int|null, severity?: string|null, event_type?: string|null, source?: string|null}  $filters
     * @return LazyCollection<int, list<mixed>>
     */
    public function events(User $user, array $filters = []): LazyCollection
    {
        $asOf = now();
        $since = $asOf->copy()->subDays(self::EVENTS_WINDOW_DAYS);
        $visibleDeviceNames = $this->access->visibleDevices($user)->select('devices.id', 'devices.name');
        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');

        return $this->access->applyTemporalEventCustodyScope(DeviceEvent::query(), $user)
            ->leftJoinSub($visibleDeviceNames, 'permitted_export_device', fn ($join) => $join
                ->on('permitted_export_device.id', '=', 'device_events.device_id'))
            ->select('device_events.*', 'permitted_export_device.name as permitted_device_name')
            ->when($filters['domain'] ?? null, fn ($query, string $domain) => $query
                ->whereHas('device', fn ($device) => $device->where('domain', $domain)->whereIn('devices.id', clone $visibleDeviceIds)))
            ->when($filters['device_id'] ?? null, fn ($query, int $deviceId) => $query->where('device_id', $deviceId))
            ->whereBetween('occurred_at', [$since, $asOf])
            ->when($filters['severity'] ?? null, fn ($query, string $severity) => $query->where('severity', $severity))
            ->when($filters['event_type'] ?? null, fn ($query, string $eventType) => $query->where('event_type', $eventType))
            ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
            ->orderBy('occurred_at', 'desc')
            ->cursor()
            ->map(fn (DeviceEvent $event): array => [
                $event->id,
                $event->device_id,
                $event->permitted_device_name,
                $event->event_type,
                $event->severity,
                $event->source,
                $event->occurred_at?->format('Y-m-d H:i:s'),
                $event->processed_at?->format('Y-m-d H:i:s'),
            ]);
    }

    /** @return LazyCollection<int, list<mixed>> */
    public function upkeep(User $user): LazyCollection
    {
        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');

        return DeviceMaintenanceRecord::query()
            ->whereIn('device_id', $visibleDeviceIds)
            ->with(['device:id,name', 'performedBy:id,name'])
            ->orderBy('scheduled_for', 'desc')
            ->cursor()
            ->map(fn (DeviceMaintenanceRecord $record): array => [
                $record->id,
                $record->device_id,
                $record->device?->name,
                $record->type,
                $record->status,
                $record->description,
                $record->scheduled_for?->format('Y-m-d H:i:s'),
                $record->completed_at?->format('Y-m-d H:i:s'),
                $record->performedBy?->name,
                $record->vendor_reference,
                $record->cost,
            ]);
    }
}
