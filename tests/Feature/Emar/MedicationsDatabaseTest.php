<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationInteraction;
use App\Models\MedicationScheduledStockCount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Medications Database page serves a flat, client-side-filterable
 * register (no server pagination) with the active site's brand colour, and
 * discontinuing an order now requires a documented reason.
 */
class MedicationsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_medications_page_serves_flat_register_with_brand_colour(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.controlled.witness']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#1E88E5']);
        $client = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $site->id, 'status' => 'active']);

        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Twice daily',
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $this->actingAs($user)
            ->get('/emar/medications?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Medications')
                ->where('site_brand_colour', '#1E88E5')
                ->has('medications', 1)
                ->where('medications.0.name', 'Paracetamol')
                ->where('medications.0.client_name', 'Ngata, Aroha')
                ->has('witnesses')
                ->has('clients')
                ->where('can.verify_orders', true)
            );
    }

    public function test_discontinue_requires_a_reason(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'clients.update']);

        $client = Client::factory()->create(['status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Amlodipine',
            'dosage' => '5mg',
            'frequency' => 'Once daily',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        // No reason → rejected.
        $this->actingAs($user)
            ->from('/emar/medications')
            ->post('/emar/medications/'.$med->id.'/discontinue', [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $med->fresh()->state);

        // With reason → ceased.
        $this->actingAs($user)
            ->post('/emar/medications/'.$med->id.'/discontinue', ['reason' => 'Prescriber ceased — no longer indicated'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ceased', $med->fresh()->state);
    }

    public function test_medication_detail_returns_real_stock_activity_and_interactions(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view']);

        $client = Client::factory()->create(['status' => 'active']);
        $warfarin = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Warfarin',
            'dosage' => '3mg',
            'frequency' => 'Once daily',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        // A second CURRENT med for the same client, to anchor the interaction.
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Aspirin',
            'dosage' => '100mg',
            'frequency' => 'Once daily',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        // Real stock-movement sources: one administered dose + one completed count.
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $warfarin->id,
            'administered_by' => $user->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
            'dose_given' => '3mg',
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $warfarin->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
            'expected_quantity' => 30,
            'actual_quantity' => 28,
            'discrepancy' => -2,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);

        // A real interaction record between the two current meds.
        MedicationInteraction::query()->create([
            'medication_a' => 'Warfarin',
            'medication_b' => 'Aspirin',
            'severity' => 'major',
            'description' => 'Increased bleeding risk.',
            'clinical_effects' => 'Bruising, haemorrhage.',
            'management' => 'Monitor INR closely.',
            'active' => true,
        ]);

        $res = $this->actingAs($user)->getJson('/emar/medications/'.$warfarin->id.'/detail');

        $res->assertOk()
            ->assertJsonCount(2, 'movements')
            ->assertJsonCount(1, 'interactions')
            ->assertJsonPath('interactions.0.other', 'Aspirin')
            ->assertJsonPath('interactions.0.severity', 'major')
            ->assertJsonPath('interactions.0.severity_label', 'Major')
            ->assertJsonPath('interactions.0.management', 'Monitor INR closely.');

        $types = collect($res->json('movements'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['administration', 'count'], $types);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
