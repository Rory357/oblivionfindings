<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.reports.view'), 403);

        $tenantId = $this->resolveTenantId($user);

        $stats = [
            'devices' => Device::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->count(),
            'events_90d' => DeviceEvent::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('occurred_at', '>=', now()->subDays(self::EVENTS_WINDOW_DAYS))
                ->count(),
            'maintenance' => DeviceMaintenanceRecord::query()
                ->whereHas('device', fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q)
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

        $tenantId = $this->resolveTenantId($user);
        $filename = 'security-devices-inventory-'.now()->format('Y-m-d').'.csv';

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

        $query = Device::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
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

        $tenantId = $this->resolveTenantId($user);
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
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('occurred_at', '>=', $since)
            ->with(['device:id,name'])
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

        $tenantId = $this->resolveTenantId($user);
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
            ->whereHas('device', fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q)
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

    private function resolveTenantId($user): ?int
    {
        $tenantId = $user->tenant_id ?? $user->organization_id ?? null;

        return $tenantId !== null ? (int) $tenantId : null;
    }
}
