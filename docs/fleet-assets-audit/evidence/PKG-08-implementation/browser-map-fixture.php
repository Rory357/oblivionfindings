<?php
// Add synthetic populated vehicle maps only to the retained PKG-08 test database.
$root = dirname(__DIR__, 4);
$environment = json_decode(file_get_contents(__DIR__.'/browser-environment.json'), true, 512, JSON_THROW_ON_ERROR);
if (($environment['APP_ENV'] ?? '') !== 'testing' || !str_starts_with($environment['DB_DATABASE'] ?? '', 'of_pkg08_browser_20260928_')) throw new RuntimeException('Unexpected fixture database');
foreach ($environment as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
$actor = \App\Models\User::where('email', 'pkg08.coordinator@example.test')->sole();
$site = \App\Models\Site::where('name', 'Settings test site')->sole();
$vehicle = \App\Models\Asset::where('asset_tag', 'PKG08-MAP-FIXTURE')->first();
if ($vehicle) { echo json_encode(['vehicle_id' => $vehicle->id, 'already_seeded' => true]); exit; }
$result = \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $site) {
    $vehicle = \App\Models\Asset::factory()->create([
        'name' => 'Synthetic map review van', 'asset_tag' => 'PKG08-MAP-FIXTURE', 'category' => 'vehicle',
        'site_id' => $site->id, 'home_site_id' => $site->id, 'client_id' => null,
        'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
        'registration_number' => 'TEST08', 'manufacturer' => 'Synthetic', 'model' => 'Review van',
    ]);
    $start = \Carbon\CarbonImmutable::now('UTC')->subMinutes(12)->startOfMinute();
    $trip = \App\Models\FleetTrip::create([
        'asset_id' => $vehicle->id, 'started_at' => $start, 'ended_at' => $start->addMinutes(10),
        'start_latitude' => -41.2838, 'start_longitude' => 174.7743,
        'end_latitude' => -41.2858, 'end_longitude' => 174.7763,
        'distance_km' => 1.5, 'duration_s' => 600, 'status' => 'closed', 'consent_blocked' => false, 'is_personal' => false,
    ]);
    for ($index = 0; $index <= 20; $index++) {
        $event = \App\Models\FleetTelemetryEvent::create([
            'asset_id' => $vehicle->id, 'vendor' => 'synthetic-review', 'vendor_message_id' => 'pkg08-map-'.$index,
            'occurred_at' => $start->addSeconds($index * 30), 'received_at' => $start->addSeconds($index * 30),
            'latitude' => -41.2838 - $index * 0.0001, 'longitude' => 174.7743 + $index * 0.0001,
            'speed_kph' => $index === 0 || $index === 20 ? 0 : 20, 'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', 'pkg08-map-'.$index), 'raw_payload' => [], 'consent_blocked' => false,
        ]);
    }
    \App\Models\FleetVehicleStateSnapshot::create([
        'asset_id' => $vehicle->id, 'last_event_id' => $event->id, 'last_trip_id' => $trip->id,
        'latitude' => $event->latitude, 'longitude' => $event->longitude, 'last_seen_at' => $event->occurred_at,
        'speed_kph' => 0, 'status' => 'online', 'consent_blocked' => false,
    ]);
    return ['vehicle_id' => $vehicle->id, 'trip_id' => $trip->id, 'synthetic_positions' => 21, 'operating_data' => false];
});
file_put_contents(__DIR__.'/map-fixture-result.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result);
