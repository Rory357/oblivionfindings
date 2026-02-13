<?php

namespace App\Services\Integration;

use App\Models\ControlRoom\Alert;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
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

        // Query integration events for the site within the time window
        $eventsQuery = IntegrationEvent::where('site_id', $siteId)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($roomId) {
            $eventsQuery->where('room_id', $roomId);
        }

        $events = $eventsQuery->with(['hardware', 'room'])->get();

        // Query open alerts for the site
        $alerts = Alert::where('site_id', $siteId)
            ->where('status', '!=', 'closed')
            ->get();

        $openAlertCount = $alerts->count();
        $criticalAlertCount = $alerts->where('severity', 'critical')->count();

        // Query hardware status for the site
        $hardwareQuery = LocationHardware::where('site_id', $siteId);
        $hardwareCount = $hardwareQuery->count();
        $onlineCount = (clone $hardwareQuery)->where('status', 'online')->count();
        $offlineCount = (clone $hardwareQuery)->where('status', 'offline')->count();

        // Query rooms for the site
        $rooms = SiteRoom::where('site_id', $siteId)->get();

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
                'title' => $a->title,
                'severity' => $a->severity,
                'status' => $a->status,
                'created_at' => $a->created_at->toIso8601String(),
            ])->toArray(),
            'rooms' => $rooms->map(fn ($r) => [
                'name' => $r->name,
                'hardware_count' => $r->hardware()->count(),
            ])->toArray(),
            'providers' => $events->pluck('provider')->unique()->values()->toArray(),
        ];
    }
}
