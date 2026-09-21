<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Http\Requests\Operations\StoreClientLocationZoneDraftRequest;
use App\Models\Client;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationZoneDraftService;
use App\Services\Tracking\ClientZoneMonitoringService;
use Illuminate\Http\Request;

class ClientLocationZoneDraftController extends Controller
{
    public function monitoring(Request $request, Client $client, int $zone, ClientZoneMonitoringService $monitoring)
    {
        $input = $request->validate([
            'action' => ['required', 'in:activate,pause'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'monitor_id' => ['required_if:action,pause', 'nullable', 'integer', 'min:1'],
            'access_fingerprint' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'reviewed' => ['accepted'],
        ]);

        return response()->json(['zone' => $monitoring->change($request->user(), $client, $zone, $input)])
            ->withHeaders(ClientLocationAccessService::headers());
    }

    public function searchAddress(Request $request, Client $client, ClientLocationAccessService $access, SiteGeocodingController $geocoder)
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'access_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $access->recheck($request->user(), $client, $input['access_fingerprint'], true);
        if (! config('fleet.maps.client_zone_address_search_enabled', false)) {
            return response()->json(['message' => 'Address search is not enabled yet. You can still draw a boundary or move the map manually.'], 503)
                ->withHeaders(ClientLocationAccessService::headers());
        }
        try {
            // Reuse the application's address provider. Only the typed query
            // reaches it, never the client, tracker or current coordinates.
            $response = $geocoder->search($request, true);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => 'Address search is temporarily unavailable. Try again, or move the map manually.'], 503)
                ->withHeaders(ClientLocationAccessService::headers());
        }
        $access->recheck($request->user(), $client, $input['access_fingerprint'], true);

        return $response->withHeaders(ClientLocationAccessService::headers());
    }

    public function index(Request $request, Client $client, ClientLocationZoneDraftService $drafts)
    {
        return response()->json($drafts->read($request->user(), $client))->withHeaders(ClientLocationAccessService::headers());
    }

    public function store(StoreClientLocationZoneDraftRequest $request, Client $client, ClientLocationZoneDraftService $drafts)
    {
        return response()->json(['zone' => $drafts->save($request->user(), $client, $request->validated())], 201)->withHeaders(ClientLocationAccessService::headers());
    }

    public function update(StoreClientLocationZoneDraftRequest $request, Client $client, int $zone, ClientLocationZoneDraftService $drafts)
    {
        return response()->json(['zone' => $drafts->save($request->user(), $client, $request->validated(), $zone)])->withHeaders(ClientLocationAccessService::headers());
    }
}
