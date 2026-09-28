<?php

namespace App\Services\Fleet;

use App\Models\FleetMapUsageLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/** Explicit user requests only. Never receives identity, assignment or a raw device payload. */
class GoogleMapCapabilities
{
    public function __construct(private readonly FleetMapSettings $settings) {}

    public function request(string $capability, array $input, int $actorId): array
    {
        abort_unless($this->settings->enabled('display') && $this->settings->enabled($capability), 503, 'This map capability is not enabled. Existing records and coordinates remain usable.');
        $scope = 'fleet:google:'.$this->settings->snapshot()['revision'].':'.$capability;
        if (Cache::has($scope.':backoff')) {
            throw new HttpResponseException(response()->json(['message' => 'The provider is temporarily unavailable. Wait before retrying.'], 503)->header('Retry-After', '60'));
        }
        $limit = (int) config('fleet.maps.reverse_geocode_rate_limit_per_minute', 30);
        if ($limit <= 0 || RateLimiter::tooManyAttempts($scope.':'.$actorId, $limit)) {
            throw new HttpResponseException(response()->json(['message' => 'Too many map requests. Wait before retrying.'], 429)->header('Retry-After', '60'));
        }
        RateLimiter::hit($scope.':'.$actorId, 60);
        $key = config('fleet.maps.google_server_key');
        $http = Http::timeout(6)->connectTimeout(3)->acceptJson();
        try {
            $response = match ($capability) {
                'places' => $http->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'places.id,places.formattedAddress,places.location,places.attributions'])->post('https://places.googleapis.com/v1/places:searchText', ['textQuery' => $input['q'], 'pageSize' => 8]),
                'geocoding' => $http->get('https://maps.googleapis.com/maps/api/geocode/json', ['latlng' => $input['lat'].','.$input['lng'], 'key' => $key]),
                'routes' => $http->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline'])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => ['location' => ['latLng' => ['latitude' => $input['origin']['lat'], 'longitude' => $input['origin']['lng']]]],
                    'destination' => ['location' => ['latLng' => ['latitude' => $input['destination']['lat'], 'longitude' => $input['destination']['lng']]]],
                    'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE',
                ]),
                default => throw new \InvalidArgumentException('Unsupported map capability.'),
            };
        } catch (ConnectionException) {
            Cache::put($scope.':backoff', true, 60);
            abort(503, 'The map provider did not respond. Retry later; existing records remain usable.');
        }
        if ($response->status() === 429 || $response->serverError() || in_array($response->json('status'), ['OVER_QUERY_LIMIT', 'UNKNOWN_ERROR'], true)) {
            Cache::put($scope.':backoff', true, 60);
            throw new HttpResponseException(response()->json(['message' => 'Provider quota or temporary availability prevented this request.'], 503)->header('Retry-After', '60'));
        }
        abort_unless($response->successful() && ! in_array($response->json('status'), ['REQUEST_DENIED', 'INVALID_REQUEST', 'OVER_DAILY_LIMIT'], true), 503, 'The provider could not authorise this API request. Ask the map administrator to review its configuration.');
        // Return only the bounded fields needed for the requested view. No persistent provider-content cache.
        $result = match ($capability) {
            'places' => ['results' => collect($response->json('places', []))->take(8)->filter(fn ($place) => isset($place['location']['latitude'], $place['location']['longitude']))->map(fn ($place) => ['place_id' => $place['id'] ?? null, 'display_name' => $place['formattedAddress'] ?? '', 'lat' => $place['location']['latitude'], 'lng' => $place['location']['longitude'], 'attributions' => $place['attributions'] ?? []])->values()->all()],
            'geocoding' => ['address' => $response->json('results.0.formatted_address')],
            'routes' => ['routes' => collect($response->json('routes', []))->take(1)->map(fn ($route) => ['distance_m' => $route['distanceMeters'] ?? null, 'duration' => $route['duration'] ?? null, 'polyline' => $route['polyline']['encodedPolyline'] ?? null])->all()],
        };
        FleetMapUsageLog::create(['user_id' => $actorId, 'context' => 'google_'.$capability]);

        return ['provider' => 'google', 'attribution' => 'Google Maps', 'revision' => $this->settings->snapshot()['revision'], ...$result];
    }
}
