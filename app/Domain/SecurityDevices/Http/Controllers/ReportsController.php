<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports surface for Security & Devices.
 *
 * This replaces the generic section-shell placeholder at /security-devices/reports.
 * Three exports at v1:
 *   • Device inventory
 *   • Device events (rolling 90 days)
 *   • Maintenance records
 *
 * Exports are streamed as CSV, so they remain cheap on large tenants.
 * Report design favours stability over breadth — a broader per-domain
 * reporting surface will live in a dedicated Reporting module when that
 * exists.
 */
class ReportsController extends Controller
{
    private const EVENTS_WINDOW_DAYS = 90;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.reports.view'), 403);

        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');

        $stats = [
            'devices' => (clone $visibleDeviceIds)->count(),
            'events_90d' => DeviceEvent::query()
                ->whereIn('device_id', clone $visibleDeviceIds)
                ->where('occurred_at', '>=', now()->subDays(self::EVENTS_WINDOW_DAYS))
                ->count(),
            'maintenance' => DeviceMaintenanceRecord::query()
                ->whereIn('device_id', clone $visibleDeviceIds)
                ->count(),
        ];

        return Inertia::render('security-devices/reports', [
            'stats' => $stats,
            'windowDays' => self::EVENTS_WINDOW_DAYS,
        ]);
    }

    public function exportDevices(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.reports.view'), 403);

        $filename = 'security-devices-inventory-'.now()->format('Y-m-d').'.csv';
        $selectedIds = $this->selectedDeviceIds($request);

        $columns = [
            'id',
            'device_uid',
            'name',
            'domain',
            'category',
            'subcategory',
            'manufacturer',
            'model',
            'serial_number',
            'mac_address',
            'imei',
            'asset_tag',
            'firmware_version',
            'status',
            'health_status',
            'provider',
            'battery_level',
            'last_seen_at',
            'last_signal_at',
            'next_service_due',
            'created_at',
        ];

        $query = $this->access->visibleDevices($user)
            ->when($selectedIds !== null, fn ($q) => $q->whereIn('id', $selectedIds))
            ->orderBy('id');

        return $this->streamCsv($filename, $columns, $query->cursor(), function (Device $d) use ($columns) {
            $row = [];
            foreach ($columns as $col) {
                $value = $d->{$col} ?? null;
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

    public function exportEvents(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.reports.view'), 403);

        $filters = $request->validate([
            'domain' => ['nullable', Rule::enum(DeviceDomain::class)],
            'device_id' => ['nullable', 'integer', 'min:1'],
            'severity' => ['nullable', 'string', 'max:50'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);
        $visibleDeviceIds = $this->access->visibleDevices($user)
            ->when($filters['domain'] ?? null, fn ($query, string $domain) => $query->where('domain', $domain))
            ->when($filters['device_id'] ?? null, fn ($query, int $deviceId) => $query->whereKey($deviceId))
            ->select('devices.id');
        $since = now()->subDays(self::EVENTS_WINDOW_DAYS);
        $filename = 'security-devices-events-'.self::EVENTS_WINDOW_DAYS.'d-'.now()->format('Y-m-d').'.csv';

        $columns = [
            'id',
            'device_id',
            'device_name',
            'event_type',
            'severity',
            'source',
            'occurred_at',
            'processed_at',
        ];

        $query = DeviceEvent::query()
            ->whereIn('device_id', $visibleDeviceIds)
            ->where('occurred_at', '>=', $since)
            ->when($filters['severity'] ?? null, fn ($query, string $severity) => $query->where('severity', $severity))
            ->when($filters['event_type'] ?? null, fn ($query, string $eventType) => $query->where('event_type', $eventType))
            ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
            ->with(['device:id,name,tenant_id'])
            ->orderBy('occurred_at', 'desc');

        return $this->streamCsv($filename, $columns, $query->cursor(), function (DeviceEvent $e) {
            return [
                $e->id,
                $e->device_id,
                $e->device?->name,
                $e->event_type,
                $e->severity,
                $e->source,
                $e->occurred_at?->format('Y-m-d H:i:s'),
                $e->processed_at?->format('Y-m-d H:i:s'),
            ];
        });
    }

    public function exportMaintenance(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.reports.view'), 403);

        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');
        $filename = 'security-devices-maintenance-'.now()->format('Y-m-d').'.csv';

        $columns = [
            'id',
            'device_id',
            'device_name',
            'type',
            'status',
            'description',
            'scheduled_for',
            'completed_at',
            'performed_by',
            'vendor_reference',
            'cost',
        ];

        $query = DeviceMaintenanceRecord::query()
            ->whereIn('device_id', $visibleDeviceIds)
            ->with(['device:id,name', 'performedBy:id,name'])
            ->orderBy('scheduled_for', 'desc');

        return $this->streamCsv($filename, $columns, $query->cursor(), function (DeviceMaintenanceRecord $m) {
            return [
                $m->id,
                $m->device_id,
                $m->device?->name,
                $m->type,
                $m->status,
                $m->description,
                $m->scheduled_for?->format('Y-m-d H:i:s'),
                $m->completed_at?->format('Y-m-d H:i:s'),
                $m->performedBy?->name,
                $m->vendor_reference,
                $m->cost,
            ];
        });
    }

    /**
     * Stream a CSV with the given filename, header row, and row generator.
     */
    private function streamCsv(string $filename, array $header, iterable $source, \Closure $rowMapper): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];

        return response()->streamDownload(function () use ($header, $source, $rowMapper) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 CSVs cleanly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($source as $row) {
                fputcsv($out, $rowMapper($row));
            }
            fclose($out);
        }, $filename, $headers);
    }

    /** @return array<int, int>|null */
    private function selectedDeviceIds(Request $request): ?array
    {
        if (! $request->has('ids')) {
            return null;
        }

        $validated = $request->validate([
            'ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/'],
        ]);

        $ids = collect(explode(',', $validated['ids']))
            ->map(fn (string $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        abort_if($ids->count() > 500, 422, 'Select no more than 500 devices per export.');

        return $ids->all();
    }
}
