<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Site;
use App\Services\Fleet\FleetTelemetryIngestService;
use Illuminate\Database\Seeder;

class FleetDemoSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::query()->first();
        if (!$site) {
            $site = Site::create(['name' => 'Demo Site']);
        }

        $client = Client::query()->first();
        if (!$client) {
            $client = Client::create([
                'first_name' => 'Demo',
                'last_name' => 'Client',
                'status' => 'active',
            ]);
        }

        $consentType = ConsentType::query()
            ->where('name', 'Fleet Tracking')
            ->first();

        if (!$consentType) {
            $consentType = ConsentType::create([
                'name' => 'Fleet Tracking',
                'category' => 'essential',
                'description' => 'Tracking consent for fleet vehicles.',
                'purpose' => 'Enable vehicle tracking.',
                'legal_basis' => 'GDPR Art 6',
                'version' => 1,
                'active' => true,
            ]);
        }

        $consent = ClientConsent::query()
            ->where('client_id', $client->id)
            ->where('consent_type_id', $consentType->id)
            ->where('status', 'given')
            ->first();

        if (!$consent) {
            $consent = ClientConsent::create([
                'client_id' => $client->id,
                'consent_type_id' => $consentType->id,
                'status' => 'given',
                'given_at' => now()->subDay(),
                'expires_at' => now()->addDays(365),
            ]);
        }

        $vehicle = Asset::query()
            ->where('category', 'vehicle')
            ->first();

        if (!$vehicle) {
            $vehicle = Asset::create([
                'site_id' => $site->id,
                'client_id' => $client->id,
                'name' => 'Demo Van',
                'status' => 'active',
                'risk_level' => 'medium',
                'category' => 'vehicle',
            ]);
        }

        $tracker = AssetTracker::query()
            ->where('asset_id', $vehicle->id)
            ->where('vendor', 'queclink')
            ->where('device_uid', 'QUE-DEMO-001')
            ->first();

        if (!$tracker) {
            $tracker = AssetTracker::create([
                'asset_id' => $vehicle->id,
                'vendor' => 'queclink',
                'device_uid' => 'QUE-DEMO-001',
                'status' => 'paired',
                'paired_at' => now()->subDay(),
                'consent_id' => $consent->id,
            ]);
        }

        app(FleetTelemetryIngestService::class)->ingest('queclink', [
            'imei' => $tracker->device_uid,
            'gps_time' => now()->toISOString(),
            'lat' => -36.8485,
            'lng' => 174.7633,
            'speed' => 12,
        ]);
    }
}
