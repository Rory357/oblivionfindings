<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\MedicationAllergy;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The new-order wizard's allergy step reads this endpoint. It must carry the
 * same combined register + health-profile list (EM-07) that dose-time safety
 * checks use, without changing the register list other callers rely on.
 */
class OrderAllergyEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_allergies_endpoint_returns_register_and_combined_recorded_allergies(): void
    {
        $this->seed(RbacSeeder::class);
        $reader = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $reader->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'status' => 'active',
        ]);
        MedicationAllergy::query()->create([
            'client_id' => $client->id,
            'allergen' => 'Penicillin',
            'reaction' => 'Anaphylaxis',
            'severity' => 'life_threatening',
            'recorded_by' => $reader->id,
        ]);
        MedicationAllergy::query()->create([
            'client_id' => $client->id,
            'allergen' => 'Removed allergen',
            'recorded_by' => $reader->id,
        ])->delete();
        ClientMedicalProfile::query()->updateOrCreate(
            ['client_id' => $client->id],
            // An option key (duplicate of the register entry) and free text.
            ['allergies' => ['penicillin', 'Kiwifruit']],
        );

        $response = $this->actingAs($reader)
            ->getJson(route('api.medications.allergies.index', $client))
            ->assertOk();

        // The register list keeps its existing shape for other callers.
        $response->assertJsonCount(1, 'allergies')
            ->assertJsonPath('allergies.0.allergen', 'Penicillin')
            ->assertJsonStructure(['allergies' => [['id', 'allergen', 'reaction', 'severity', 'is_severe']]]);

        $this->assertSame([
            [
                'allergen' => 'Penicillin',
                'severity' => 'life_threatening',
                'reaction' => 'Anaphylaxis',
                'source' => 'medication_register',
            ],
            [
                'allergen' => 'Kiwifruit',
                'severity' => null,
                'reaction' => null,
                'source' => 'health_profile',
            ],
        ], $response->json('recorded_allergies'));
    }

    public function test_a_resident_with_nothing_recorded_gets_empty_lists_not_an_error(): void
    {
        $this->seed(RbacSeeder::class);
        $reader = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $reader->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'status' => 'active',
        ]);

        $this->actingAs($reader)
            ->getJson(route('api.medications.allergies.index', $client))
            ->assertOk()
            ->assertExactJson(['allergies' => [], 'recorded_allergies' => []]);
    }
}
