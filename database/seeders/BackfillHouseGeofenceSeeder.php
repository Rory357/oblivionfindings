<?php

namespace Database\Seeders;

use App\Models\AssetGeofence;
use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackfillHouseGeofenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'house_geofence_id')) {
            return;
        }

        if (! Schema::hasTable('asset_geofences')) {
            return;
        }

        $clients = Client::query()
            ->whereNull('house_geofence_id')
            ->whereNotNull('site_id')
            ->where('status', 'active')
            ->get();

        foreach ($clients as $client) {
            $geofence = AssetGeofence::query()
                ->where('is_active', true)
                ->where('site_id', $client->site_id)
                ->where(function ($q) {
                    $q->where('scope', 'house')
                        ->orWhere('scope', 'resident')
                        ->orWhereNull('scope');
                })
                ->orderByRaw("CASE scope WHEN 'house' THEN 0 WHEN 'resident' THEN 1 ELSE 2 END")
                ->first();

            if (! $geofence) {
                Log::info('BackfillHouseGeofenceSeeder: no house geofence for client', [
                    'client_id' => $client->id,
                    'site_id' => $client->site_id,
                ]);

                continue;
            }

            $client->update(['house_geofence_id' => $geofence->id]);
        }
    }
}
