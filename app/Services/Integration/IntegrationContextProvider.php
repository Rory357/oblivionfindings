<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Support\Carbon;

class IntegrationContextProvider
{
    /**
     * Build LLM-ready context for AI narrative generation.
     *
     * Inferred Presence data requires explicit permission + tenant-wide toggle (config features.face_recognition).
     */
    public function getContext(
        int $tenantId,
        int $siteId,
        ?int $roomId = null,
        ?string $subject = null,
        ?array $timeWindow = null,
    ): array {
        $from = $timeWindow['from'] ?? Carbon::now()->subDay();
        $to = $timeWindow['to'] ?? Carbon::now();
        $allowLegacyNullTenant = $tenantId === 1;

        // Site IDs are globally addressable, so validate the tenant boundary before
        // reading any site-derived context. Tenant 1 alone may read deliberately
        // unassigned legacy rows; the verified site ID still contains that fallback.
        Site::query()
            ->whereKey($siteId)
            ->where(function ($tenantQuery) use ($tenantId, $allowLegacyNullTenant) {
                $tenantQuery->where('tenant_id', $tenantId);
                if ($allowLegacyNullTenant) {
                    $tenantQuery->orWhereNull('tenant_id');
                }
            })
            ->firstOrFail();

        // Query integration events for the site within the time window
        $eventsQuery = IntegrationEvent::where('site_id', $siteId)
            ->where(function ($tenantQuery) use ($tenantId, $allowLegacyNullTenant) {
                $tenantQuery->where('tenant_id', $tenantId);
                if ($allowLegacyNullTenant) {
                    $tenantQuery->orWhereNull('tenant_id');
                }
            })
            ->whereBetween('occurred_at', [$from, $to]);

        if ($roomId) {
            SiteRoom::query()
                ->whereKey($roomId)
                ->where('site_id', $siteId)
                ->where(function ($tenantQuery) use ($tenantId, $allowLegacyNullTenant) {
                    $tenantQuery->where('tenant_id', $tenantId);
                    if ($allowLegacyNullTenant) {
                        $tenantQuery->orWhereNull('tenant_id');
                    }
                })
                ->firstOrFail();
            $eventsQuery->where('room_id', $roomId);
        }

        $relationScope = function ($query) use ($tenantId, $siteId, $allowLegacyNullTenant): void {
            $query->where('site_id', $siteId)
                ->where(function ($tenantQuery) use ($tenantId, $allowLegacyNullTenant) {
                    $tenantQuery->where('tenant_id', $tenantId);
                    if ($allowLegacyNullTenant) {
                        $tenantQuery->orWhereNull('tenant_id');
                    }
                });
        };
        $events = $eventsQuery->with([
            'hardware' => $relationScope,
            'room' => $relationScope,
        ])->get();

        // Query open alerts from canonical ControlRoomAlert for integration sources
        $alerts = ControlRoomAlert::where('site_id', $siteId)
            ->where('source', 'like', 'integration_%')
            ->actionable()
            ->get();

        $openAlertCount = $alerts->count();
        $criticalAlertCount = $alerts->where('severity', 'critical')->count();

        // Query device status for the site (canonical Security & Devices registry).
        $siteDevices = app(DeviceRegistryService::class)->forSite($tenantId, $siteId);
        $hardwareCount = (clone $siteDevices)->count();
        $onlineCount = (clone $siteDevices)->where('status', DeviceStatus::Active->value)->count();
        $offlineCount = (clone $siteDevices)->where('status', DeviceStatus::Offline->value)->count();

        // Query rooms for the site
        $rooms = SiteRoom::where('site_id', $siteId)
            ->where(function ($tenantQuery) use ($tenantId, $allowLegacyNullTenant) {
                $tenantQuery->where('tenant_id', $tenantId);
                if ($allowLegacyNullTenant) {
                    $tenantQuery->orWhereNull('tenant_id');
                }
            })
            ->withCount(['hardware as scoped_hardware_count' => $relationScope])
            ->get();

        return [
            'site_summary' => [
                'hardware_total' => $hardwareCount,
                'hardware_online' => $onlineCount,
                'hardware_offline' => $offlineCount,
                'open_alerts' => $openAlertCount,
                'critical_alerts' => $criticalAlertCount,
            ],
            'recent_events' => $events->map(fn ($e) => [
                'type' => $e->event_type,
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'hardware' => $e->hardware?->name,
                'room' => $e->room?->name,
                'provider' => $e->provider,
            ])->toArray(),
            'open_alerts' => $alerts->map(fn ($a) => [
                'title' => $a->context['normalized_data']['title'] ?? $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'created_at' => ($a->triggered_at ?? $a->created_at)->toIso8601String(),
            ])->toArray(),
            'rooms' => $rooms->map(fn ($r) => [
                'name' => $r->name,
                'hardware_count' => (int) $r->scoped_hardware_count,
            ])->toArray(),
            'providers' => $events->pluck('provider')->unique()->values()->toArray(),
        ];
    }
}
