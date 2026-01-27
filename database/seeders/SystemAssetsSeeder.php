<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetInspection;
use App\Models\AssetMaintenanceLog;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class SystemAssetsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->orderBy('id')->first();
        $sites = Site::query()->get(['id', 'name']);
        $clients = Client::query()->get(['id', 'first_name', 'last_name', 'site_id']);

        if ($sites->isEmpty()) {
            return;
        }

        $categories = ['Hoist', 'Wheelchair', 'BP Monitor', 'Thermometer', 'Bed', 'Shower chair', 'Oxygen concentrator'];
        $manufacturers = ['Invacare', 'Drive', 'Arjo', 'Philips', 'Omron', 'Hillrom'];

        // Site-level assets
        foreach ($sites as $site) {
            for ($i = 0; $i < 4; $i++) {
                $name = $categories[array_rand($categories)] . ' - ' . $site->name;
                $asset = Asset::create([
                    'site_id' => $site->id,
                    'client_id' => null,
                    'created_by_user_id' => $admin?->id,
                    'updated_by_user_id' => $admin?->id,
                    'asset_tag' => 'S' . $site->id . '-' . ($i + 1),
                    'name' => $name,
                    'category' => $categories[array_rand($categories)],
                    'description' => 'Seeded demo asset for site use.',
                    'manufacturer' => $manufacturers[array_rand($manufacturers)],
                    'model' => 'Model-' . rand(100, 999),
                    'serial_number' => 'SN' . strtoupper(substr(md5($site->id . '-' . $i), 0, 10)),
                    'purchase_date' => now()->subDays(rand(100, 1400))->toDateString(),
                    'warranty_expires_at' => now()->addDays(rand(-60, 300))->toDateString(),
                    'status' => 'active',
                    'risk_level' => (function () { $levels = ['low','medium','high']; return $levels[array_rand($levels)]; })(),
                    'location' => 'Storage room',
                    'requires_inspection' => true,
                    'inspection_due_at' => now()->addDays(rand(-30, 120))->toDateString(),
                    'requires_maintenance' => true,
                    'maintenance_due_at' => now()->addDays(rand(-30, 180))->toDateString(),
                    'notes' => 'Seed notes.',
                ]);

                // Last inspection
                $inspectedAt = now()->subDays(rand(10, 120));
                $nextDue = now()->addDays(rand(30, 120))->toDateString();
                AssetInspection::create([
                    'asset_id' => $asset->id,
                    'inspected_by_user_id' => $admin?->id,
                    'inspected_at' => $inspectedAt,
                    'result' => (function () { $r = ['pass','pass','needs_followup','fail']; return $r[array_rand($r)]; })(),
                    'notes' => 'Seed inspection record.',
                    'next_due_at' => $nextDue,
                ]);

                // Last maintenance
                $performedAt = now()->subDays(rand(20, 200));
                $nextMaint = now()->addDays(rand(30, 180))->toDateString();
                AssetMaintenanceLog::create([
                    'asset_id' => $asset->id,
                    'performed_by_user_id' => $admin?->id,
                    'performed_at' => $performedAt,
                    'type' => (function () { $t = ['service','repair','cleaning']; return $t[array_rand($t)]; })(),
                    'vendor' => 'Demo Vendor',
                    'cost' => rand(0, 1) ? rand(50, 500) : null,
                    'notes' => 'Seed maintenance record.',
                    'next_due_at' => $nextMaint,
                ]);

                // Dummy doc file
                $disk = 'local';
                $path = "assets/{$asset->id}/demo.txt";
                Storage::disk($disk)->put($path, "Demo document for asset #{$asset->id}\n");

                AssetDocument::create([
                    'asset_id' => $asset->id,
                    'uploaded_by_user_id' => $admin?->id,
                    'title' => 'Demo document',
                    'category' => 'manual',
                    'version' => '1.0',
                    'effective_date' => now()->subDays(30)->toDateString(),
                    'expiry_date' => now()->addDays(365)->toDateString(),
                    'notes' => 'Seeded demo document.',
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'original_name' => 'demo.txt',
                    'mime_type' => 'text/plain',
                    'size_bytes' => strlen("Demo document for asset"),
                ]);
            }
        }

        // Client-level assets
        foreach ($clients->take(10) as $client) {
            for ($i = 0; $i < 2; $i++) {
                $name = $categories[array_rand($categories)] . ' - ' . $client->first_name;
                Asset::create([
                    'site_id' => $client->site_id,
                    'client_id' => $client->id,
                    'created_by_user_id' => $admin?->id,
                    'updated_by_user_id' => $admin?->id,
                    'asset_tag' => 'C' . $client->id . '-' . ($i + 1),
                    'name' => $name,
                    'category' => $categories[array_rand($categories)],
                    'description' => 'Seeded demo asset linked to a client.',
                    'manufacturer' => $manufacturers[array_rand($manufacturers)],
                    'model' => 'Model-' . rand(100, 999),
                    'serial_number' => 'SN' . strtoupper(substr(md5($client->id . '-' . $i), 0, 10)),
                    'purchase_date' => now()->subDays(rand(50, 1200))->toDateString(),
                    'warranty_expires_at' => now()->addDays(rand(-30, 200))->toDateString(),
                    'status' => 'active',
                    'risk_level' => (function () { $levels = ['low','medium','high']; return $levels[array_rand($levels)]; })(),
                    'location' => 'Client room',
                    'requires_inspection' => true,
                    'inspection_due_at' => now()->addDays(rand(-30, 90))->toDateString(),
                    'requires_maintenance' => rand(0,1) ? true : false,
                    'maintenance_due_at' => now()->addDays(rand(-30, 120))->toDateString(),
                    'notes' => 'Seed notes.',
                ]);
            }
        }
    }
}
