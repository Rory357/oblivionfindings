<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DiscoveryCollectorController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.integrations.view'), 403);

        $tenantId = (int) ($user->organization_id ?: 1);
        $collectors = MonitoringCollector::query()
            ->forTenant($tenantId)
            ->with('site:id,name')
            ->withCount('monitors')
            ->orderBy('name')
            ->get()
            ->map(fn (MonitoringCollector $collector): array => [
                'id' => $collector->id,
                'uuid' => $collector->collector_uuid,
                'name' => $collector->name,
                'site' => $collector->site ? [
                    'id' => $collector->site->id,
                    'name' => $collector->site->name,
                ] : null,
                'status' => $collector->status,
                'last_seen_at' => $collector->last_seen_at?->toIso8601String(),
                'monitor_count' => (int) $collector->monitors_count,
                'is_stale' => $collector->last_seen_at === null
                    || $collector->last_seen_at->lt(now()->subMinutes(5)),
            ]);

        $monitors = Monitor::query()->forTenant($tenantId);

        return Inertia::render('security-devices/discovery', [
            'collectors' => $collectors,
            'summary' => [
                'collectors' => $collectors->count(),
                'online' => $collectors->where('status', 'online')->count(),
                'stale' => $collectors->where('is_stale', true)->count(),
                'monitors' => (clone $monitors)->count(),
                'unassigned_monitors' => (clone $monitors)->whereNull('collector_id')->count(),
            ],
        ]);
    }
}
