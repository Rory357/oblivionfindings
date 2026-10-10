<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EA-066: the /emar Overview lists (recent doses, residents, medicines,
 * alerts) follow the P02 per-person rule, not just the house: an ordinary
 * support worker sees the residents they support, nobody else.
 */
class OverviewPersonRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unassigned_resident_never_appears_on_a_support_workers_overview(): void
    {
        $this->seed(RbacSeeder::class);
        $house = Site::factory()->create(['is_active' => true]);
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id, 'primary_site_id' => $house->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        ]);
        $mine = Client::factory()->create(['site_id' => $house->id, 'first_name' => 'Aroha', 'last_name' => 'Mine', 'status' => 'active']);
        $theirs = Client::factory()->create(['site_id' => $house->id, 'first_name' => 'Hemi', 'last_name' => 'Notmine', 'status' => 'active']);
        $mine->supportWorkers()->attach($worker->id);
        foreach ([[$mine, 'Metformin'], [$theirs, 'Quetiapine']] as [$client, $name]) {
            $medication = ClientMedication::factory()->create([
                'client_id' => $client->id, 'name' => $name, 'active' => true, 'state' => 'active',
                'approval_status' => 'verified', 'is_prn' => true, 'controlled_drug' => false,
                'start_date' => today()->subWeek()->toDateString(), 'end_date' => null,
            ]);
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id, 'client_medication_id' => $medication->id,
                'administered_by' => $worker->id, 'administered_at' => now()->subHour(), 'status' => 'given',
            ]);
        }

        $props = $this->actingAs($worker)->get('/emar')->assertOk()->inertiaProps();

        $encoded = json_encode([
            $props['recentActivity'] ?? [],
            $props['clientOptions'] ?? [],
            $props['medicationOptions'] ?? [],
            $props['activeAlertsList'] ?? [],
            $props['clientBoard'] ?? [],
        ]);
        $this->assertStringNotContainsString('Quetiapine', $encoded);
        $this->assertStringNotContainsString('Notmine', $encoded);
        $this->assertStringContainsString('Metformin', $encoded);
    }
}
