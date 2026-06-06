<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
use App\Models\FleetTrip;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

class FleetManagementSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::query()->updateOrCreate(
            ['name' => 'Main Site'],
            [
                'type' => 'house',
                'city' => 'Auckland',
                'region' => 'Auckland',
                'country' => 'New Zealand',
                'is_active' => true,
            ]
        );

        $users = User::query()->take(10)->get();
        $admin = $users->first();

        // Create Fleet Tracking consent type if not exists
        $consentType = ConsentType::query()
            ->where('name', 'Fleet Tracking')
            ->first();

        if (!$consentType) {
            $consentType = ConsentType::create([
                'name' => 'Fleet Tracking',
                'category' => 'essential',
                'description' => 'Consent for vehicle location tracking.',
                'purpose' => 'Enable fleet vehicle GPS tracking.',
                'legal_basis' => 'Privacy Act 2020 IPP basis',
                'version' => 1,
                'active' => true,
            ]);
        }

        // Create fleet vehicles
        $vehicleData = [
            ['name' => 'Transit Van 1', 'tag' => 'VAN-001', 'model' => 'Ford Transit', 'fuel' => 'diesel'],
            ['name' => 'Transit Van 2', 'tag' => 'VAN-002', 'model' => 'Ford Transit', 'fuel' => 'diesel'],
            ['name' => 'Sprinter Van', 'tag' => 'VAN-003', 'model' => 'Mercedes Sprinter', 'fuel' => 'diesel'],
            ['name' => 'Pool Car 1', 'tag' => 'CAR-001', 'model' => 'Toyota Corolla', 'fuel' => 'petrol'],
            ['name' => 'Pool Car 2', 'tag' => 'CAR-002', 'model' => 'Honda Civic', 'fuel' => 'petrol'],
            ['name' => 'Hybrid Car', 'tag' => 'CAR-003', 'model' => 'Toyota Prius', 'fuel' => 'hybrid'],
            ['name' => 'Electric Van', 'tag' => 'VAN-004', 'model' => 'VW e-Crafter', 'fuel' => 'electric'],
            ['name' => 'Minibus', 'tag' => 'BUS-001', 'model' => 'Ford Transit Minibus', 'fuel' => 'diesel'],
        ];

        $vehicles = [];
        $trackerImeis = [];

        foreach ($vehicleData as $vData) {
            $vehicle = Asset::query()
                ->where('asset_tag', $vData['tag'])
                ->first();

            if (!$vehicle) {
                $vehicle = Asset::create([
                    'site_id' => $site->id,
                    'created_by_user_id' => $admin?->id,
                    'asset_tag' => $vData['tag'],
                    'name' => $vData['name'],
                    'category' => 'vehicle',
                    'description' => 'Fleet vehicle for operations.',
                    'manufacturer' => explode(' ', $vData['model'])[0],
                    'model' => $vData['model'],
                    'serial_number' => 'VIN' . strtoupper(substr(md5($vData['tag']), 0, 12)),
                    'purchase_date' => now()->subDays(rand(180, 1000))->toDateString(),
                    'warranty_expires_at' => now()->addDays(rand(30, 365))->toDateString(),
                    'status' => 'active',
                    'risk_level' => 'medium',
                    'location' => 'Fleet yard',
                    'requires_inspection' => true,
                    'inspection_due_at' => now()->addDays(rand(-10, 60))->toDateString(),
                    'requires_maintenance' => true,
                    'maintenance_due_at' => now()->addDays(rand(-5, 90))->toDateString(),
                ]);
            }

            $vehicles[$vData['tag']] = [
                'asset' => $vehicle,
                'fuel' => $vData['fuel'],
            ];

            // Create tracker for vehicle
            $imei = 'QUE-' . str_pad($vehicle->id, 6, '0', STR_PAD_LEFT);
            $trackerImeis[$vehicle->id] = $imei;

            $tracker = AssetTracker::query()
                ->where('asset_id', $vehicle->id)
                ->first();

            if (!$tracker) {
                // Create a client for fleet consent if needed
                $fleetClient = Client::query()
                    ->where('first_name', 'Fleet')
                    ->where('last_name', 'Operations')
                    ->first();

                if (!$fleetClient) {
                    $fleetClient = Client::create([
                        'first_name' => 'Fleet',
                        'last_name' => 'Operations',
                        'status' => 'active',
                        'site_id' => $site->id,
                    ]);
                }

                $consent = ClientConsent::query()
                    ->where('client_id', $fleetClient->id)
                    ->where('consent_type_id', $consentType->id)
                    ->where('status', 'given')
                    ->first();

                if (!$consent) {
                    $consent = ClientConsent::create([
                        'client_id' => $fleetClient->id,
                        'consent_type_id' => $consentType->id,
                        'status' => 'given',
                        'given_at' => now()->subDays(30),
                        'expires_at' => now()->addDays(335),
                    ]);
                }

                AssetTracker::create([
                    'asset_id' => $vehicle->id,
                    'vendor' => 'queclink',
                    'device_uid' => $imei,
                    'status' => 'paired',
                    'paired_at' => now()->subDays(rand(30, 180)),
                    'consent_id' => $consent->id,
                ]);
            }
        }

        // Create driver sessions and trips for each vehicle
        foreach ($vehicles as $tag => $vehicleInfo) {
            $vehicle = $vehicleInfo['asset'];
            $fuelType = $vehicleInfo['fuel'];

            // Create 3-8 driver sessions per vehicle over past 30 days
            $numSessions = rand(3, 8);
            $sessionStartDate = now()->subDays(30);
            $currentOdometer = rand(15000, 80000);

            for ($s = 0; $s < $numSessions; $s++) {
                $driver = $users->count() > 1 ? $users->random() : $admin;
                $sessionStart = $sessionStartDate->copy()->addDays(rand(0, 4))->setHour(rand(6, 10))->setMinute(rand(0, 59));
                $sessionEnd = $sessionStart->copy()->addHours(rand(4, 10))->addMinutes(rand(0, 59));

                $driverSession = FleetDriverSession::create([
                    'asset_id' => $vehicle->id,
                    'user_id' => $driver->id,
                    'started_at' => $sessionStart,
                    'ended_at' => $sessionEnd,
                    'source' => 'manual',
                    'status' => 'ended',
                ]);

                // Create 1-4 trips per session
                $numTrips = rand(1, 4);
                $tripStart = $sessionStart->copy();

                for ($t = 0; $t < $numTrips; $t++) {
                    $tripDuration = rand(15, 90) * 60; // 15-90 minutes in seconds
                    $tripDistance = round(rand(5, 60) + (rand(0, 99) / 100), 3);
                    $tripEnd = $tripStart->copy()->addSeconds($tripDuration);

                    // Auckland area coordinates with some variation
                    $baseLat = -36.8485;
                    $baseLng = 174.7633;
                    $startLat = $baseLat + (rand(-500, 500) / 10000);
                    $startLng = $baseLng + (rand(-500, 500) / 10000);
                    $endLat = $baseLat + (rand(-500, 500) / 10000);
                    $endLng = $baseLng + (rand(-500, 500) / 10000);

                    FleetTrip::create([
                        'asset_id' => $vehicle->id,
                        'driver_session_id' => $driverSession->id,
                        'started_at' => $tripStart,
                        'ended_at' => $tripEnd,
                        'start_latitude' => $startLat,
                        'start_longitude' => $startLng,
                        'end_latitude' => $endLat,
                        'end_longitude' => $endLng,
                        'distance_km' => $tripDistance,
                        'duration_s' => $tripDuration,
                        'status' => 'closed',
                        'consent_blocked' => false,
                    ]);

                    $currentOdometer += $tripDistance;
                    $tripStart = $tripEnd->copy()->addMinutes(rand(10, 60));
                }

                $sessionStartDate = $sessionEnd->copy()->addDays(rand(1, 5));
            }

            // Create fuel logs for non-electric vehicles
            if ($fuelType !== 'electric') {
                $numFuelLogs = rand(3, 6);
                $fuelDate = now()->subDays(45);
                $odometer = rand(15000, 80000);

                $stations = ['BP Henderson', 'Z Newmarket', 'Mobil Albany', 'Caltex Mt Eden', 'Gull Panmure'];

                for ($f = 0; $f < $numFuelLogs; $f++) {
                    $fuelDate = $fuelDate->copy()->addDays(rand(5, 12));
                    $litres = round(rand(30, 70) + (rand(0, 99) / 100), 2);
                    $costPerLitre = round(2.5 + (rand(0, 80) / 100), 3); // $2.50 - $3.30 per litre
                    $totalCost = round($litres * $costPerLitre, 2);
                    $odometer += rand(300, 800);

                    FleetFuelLog::create([
                        'asset_id' => $vehicle->id,
                        'user_id' => $users->count() > 1 ? $users->random()->id : $admin?->id,
                        'logged_at' => $fuelDate,
                        'fuel_type' => $fuelType,
                        'quantity_litres' => $litres,
                        'cost_per_litre' => $costPerLitre,
                        'total_cost' => $totalCost,
                        'odometer_km' => $odometer,
                        'full_tank' => rand(0, 4) < 4, // 80% chance of full tank
                        'station_name' => $stations[array_rand($stations)],
                        'location' => 'Auckland',
                    ]);
                }
            }
        }

        // Create one open trip (vehicle currently in use)
        $activeVehicle = collect($vehicles)->random()['asset'];
        $activeDriver = $users->count() > 1 ? $users->random() : $admin;

        $activeSession = FleetDriverSession::create([
            'asset_id' => $activeVehicle->id,
            'user_id' => $activeDriver->id,
            'started_at' => now()->subHours(2),
            'ended_at' => null,
            'source' => 'manual',
            'status' => 'active',
        ]);

        FleetTrip::create([
            'asset_id' => $activeVehicle->id,
            'driver_session_id' => $activeSession->id,
            'started_at' => now()->subMinutes(45),
            'ended_at' => null,
            'start_latitude' => -36.8485,
            'start_longitude' => 174.7633,
            'distance_km' => 12.5,
            'duration_s' => 2700, // 45 minutes in seconds (ongoing trip)
            'status' => 'open',
            'consent_blocked' => false,
        ]);
    }
}
