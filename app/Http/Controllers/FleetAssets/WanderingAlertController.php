<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LocationHardware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class WanderingAlertController extends Controller
{
    public function index(Request $request)
    {
        // Query control room alerts from resident tracker sources
        $query = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNotIn('status', ['closed', 'resolved']);
        }

        $alerts = $query->latest('triggered_at')
            ->paginate(25)
            ->withQueryString();

        // Load client data
        $clientIds = $alerts->getCollection()->pluck('client_id')->unique()->filter();
        $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');

        // Load tracker data for last known location
        $trackersByClient = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where('linked_person_type', 'client')
            ->whereIn('linked_person_id', $clientIds)
            ->get()
            ->keyBy('linked_person_id');

        $alertData = $alerts->getCollection()->map(function ($alert) use ($clients, $trackersByClient) {
            $client = $clients->get($alert->client_id);
            $tracker = $trackersByClient->get($alert->client_id);
            $meta = $tracker?->meta ?? [];
            $context = $alert->context ?? [];

            return [
                'id' => $alert->id,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'triggered_at' => optional($alert->triggered_at)->toISOString(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
                'resolved_at' => optional($alert->resolved_at)->toISOString(),
                'notes' => $alert->notes,
                'context' => $context,
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                    'photo' => $client->profile_photo_url,
                    'house' => $client->site?->name ?? 'Unknown',
                ] : null,
                'last_lat' => $meta['lat'] ?? $meta['latitude'] ?? $context['lat'] ?? null,
                'last_lng' => $meta['lng'] ?? $meta['longitude'] ?? $context['lng'] ?? null,
                'geofence_name' => $context['geofence_name'] ?? $context['zone_name'] ?? null,
            ];
        })->values();

        // Stats
        $activeAlerts = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id')
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $resolvedToday = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id')
            ->where('status', 'resolved')
            ->whereDate('resolved_at', today())
            ->count();

        $totalThisWeek = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id')
            ->where('triggered_at', '>=', now()->startOfWeek())
            ->count();

        return Inertia::render('fleet-assets/wandering/index', [
            'alerts' => [
                'data' => $alertData,
                'links' => $alerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'last_page' => $alerts->lastPage(),
                    'total' => $alerts->total(),
                ],
            ],
            'stats' => [
                'active_alerts' => $activeAlerts,
                'resolved_today' => $resolvedToday,
                'total_this_week' => $totalThisWeek,
            ],
            'filters' => $request->only(['status']),
        ]);
    }
}
