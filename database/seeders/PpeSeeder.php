<?php

namespace Database\Seeders;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo PPE register (local/demo only — NOT run on deploy). Seeds the NZ AS/NZS
 * catalogue, inventory across sites with a mix of statuses (one condemned, one
 * inspection-overdue, one expiring) and active allocations incl. one
 * unacknowledged respiratory item with no fit-test — so every hero badge/tab lights up.
 */
class PpeSeeder extends Seeder
{
    public function run(): void
    {
        if (PpeType::count() > 0) {
            return; // idempotent
        }

        $sites = Site::query()->where('is_active', true)->take(3)->get();
        if ($sites->isEmpty()) {
            $sites = Site::factory()->count(3)->create(['is_active' => true]);
        }

        $catalogue = [
            ['Half-face respirator (P2)', 'respiratory', 'AS/NZS 1715 & 1716', 'monthly'],
            ['Disposable P2 mask', 'respiratory', 'AS/NZS 1716', 'monthly'],
            ['Hard hat', 'head', 'AS/NZS 1801', 'quarterly'],
            ['Safety glasses', 'eye', 'AS/NZS 1337.1', 'monthly'],
            ['Hi-vis vest', 'high_visibility', 'AS/NZS 4602.1', 'annually'],
            ['Safety boots', 'foot', 'AS/NZS 2210.3', 'quarterly'],
            ['Cut-resistant gloves', 'hand', 'AS/NZS 2161.3', 'monthly'],
            ['Full-body harness', 'fall_protection', 'AS/NZS 1891.1', 'quarterly'],
        ];

        $types = collect($catalogue)->map(fn ($c) => PpeType::create([
            'name' => $c[0], 'category' => $c[1], 'standards_reference' => $c[2],
            'inspection_frequency' => $c[3], 'typical_lifespan_months' => 36, 'is_active' => true,
            'hazards_addressed' => 'Demo hazard coverage', 'description' => 'Seeded demo PPE type.',
        ]));

        $respirator = $types->firstWhere('category', 'respiratory');
        $workers = User::query()->inRandomOrder()->take(6)->get();
        $i = 0;

        foreach ($types as $type) {
            foreach ($sites as $site) {
                $i++;
                $state = match (true) {
                    $i === 3 => 'condemned',
                    $i === 5 => 'inspectionDue',
                    $i === 7 => 'expiring',
                    default => null,
                };
                $factory = PpeInventory::factory()->for($type, 'ppeType')->state(['site_id' => $site->id]);
                if ($state === 'condemned') {
                    $factory = $factory->condemned();
                } elseif ($state === 'inspectionDue') {
                    $factory = $factory->inspectionDue();
                } elseif ($state === 'expiring') {
                    $factory = $factory->expiring();
                }
                $factory->create();
            }
        }

        // One unacknowledged respiratory allocation with NO fit-test (lights RPE + unack badges).
        if ($respirator && $workers->isNotEmpty()) {
            $item = PpeInventory::factory()->for($respirator, 'ppeType')->state([
                'site_id' => $sites->first()->id, 'status' => 'allocated',
            ])->create();
            PpeAllocation::factory()->create([
                'ppe_inventory_id' => $item->id, 'user_id' => $workers->first()->id,
                'fit_test_completed' => false, 'acknowledged' => false, 'returned_at' => null,
            ]);
        }
    }
}
