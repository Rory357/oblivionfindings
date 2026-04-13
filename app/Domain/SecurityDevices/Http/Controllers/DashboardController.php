<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.viewAny'), 403);

        $last24h = now()->subHours(24);

        // ── Top-level stats ───────────────────────────────────────

        $stats = [
            'totalDevices' => Device::count(),
            'active' => Device::where('status', DeviceStatus::Active->value)->count(),
            'offline' => Device::where('status', DeviceStatus::Offline->value)->count(),
            'degraded' => Device::where('status', DeviceStatus::Degraded->value)->count(),
            'lowBattery' => Device::lowBattery()->count(),
            'overdueMaintenance' => DeviceMaintenanceRecord::overdue()->count(),
            'criticalEvents24h' => DeviceEvent::since($last24h)->bySeverity('critical')->count(),
            'warningEvents24h' => DeviceEvent::since($last24h)->bySeverity('warning')->count(),
        ];

        // ── Domain distribution ───────────────────────────────────

        $domainDistribution = Device::query()
            ->selectRaw('domain, count(*) as count')
            ->groupBy('domain')
            ->pluck('count', 'domain')
            ->toArray();

        // Map to labels.
        $domainSummary = [];
        foreach (DeviceDomain::cases() as $domain) {
            $domainSummary[] = [
                'domain' => $domain->value,
                'label' => $domain->label(),
                'count' => $domainDistribution[$domain->value] ?? 0,
            ];
        }

        // ── Health distribution ────────────────────────────────────

        $healthDistribution = Device::query()
            ->selectRaw('health_status, count(*) as count')
            ->groupBy('health_status')
            ->pluck('count', 'health_status')
            ->toArray();

        $healthSummary = [];
        foreach (HealthStatus::cases() as $hs) {
            $healthSummary[] = [
                'status' => $hs->value,
                'label' => $hs->label(),
                'count' => $healthDistribution[$hs->value] ?? 0,
            ];
        }

        // ── Devices needing attention (top 10) ────────────────────

        $attentionDevices = Device::needingAttention()
            ->orderByRaw("FIELD(health_status, 'critical', 'warning', 'unknown', 'healthy')")
            ->limit(10)
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'domain' => $d->domain,
                'category' => $d->category,
                'status' => $d->status?->value,
                'health_status' => $d->health_status?->value,
                'battery_level' => $d->battery_level,
                'last_seen_at' => $d->last_seen_at?->toISOString(),
            ]);

        // ── Recent critical/warning events (top 10) ───────────────

        $recentEvents = DeviceEvent::query()
            ->with('device:id,name,device_uid')
            ->whereIn('severity', ['critical', 'warning'])
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (DeviceEvent $e) => [
                'id' => $e->id,
                'device_id' => $e->device_id,
                'device_name' => $e->device?->name,
                'device_uid' => $e->device?->device_uid,
                'event_type' => $e->event_type,
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at?->toISOString(),
            ]);

        // ── Overdue maintenance (top 10) ──────────────────────────

        $overdueMaintenance = DeviceMaintenanceRecord::overdue()
            ->with('device:id,name,device_uid')
            ->orderBy('scheduled_for')
            ->limit(10)
            ->get()
            ->map(fn (DeviceMaintenanceRecord $m) => [
                'id' => $m->id,
                'device_id' => $m->device_id,
                'device_name' => $m->device?->name,
                'device_uid' => $m->device?->device_uid,
                'type' => $m->type,
                'description' => $m->description,
                'scheduled_for' => $m->scheduled_for?->toDateString(),
            ]);

        // ── Group count ───────────────────────────────────────────

        $groupCount = DeviceGroup::count();

        return Inertia::render('security-devices/dashboard', [
            'stats' => $stats,
            'domainSummary' => $domainSummary,
            'healthSummary' => $healthSummary,
            'attentionDevices' => $attentionDevices,
            'recentEvents' => $recentEvents,
            'overdueMaintenance' => $overdueMaintenance,
            'groupCount' => $groupCount,
        ]);
    }
}
