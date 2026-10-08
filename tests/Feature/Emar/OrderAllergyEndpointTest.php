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
 * same current canonical plus uncopied legacy list that dose-time safety
 * checks use. Compatibility projections retain the same clinical meaning.
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
            // The same allergen without the register's reaction/severity is
            // distinct evidence from the profile, alongside its free text.
            ['allergies' => ['penicillin', 'Kiwifruit']],
        );

        $response = $this->actingAs($reader)
            ->getJson(route('api.medications.allergies.index', $client))
            ->assertOk();

        // Both compatibility lists contain the same three current allergy entries.
        $response->assertJsonCount(3, 'allergies')
            ->assertJsonPath('allergies.0.allergen', 'Penicillin')
            ->assertJsonPath('allergies.0.recorded_by', $reader->name)
            ->assertJsonStructure(['allergies' => [['id', 'allergen', 'reaction', 'severity', 'is_severe']]])
            ->assertDontSee('Removed allergen', false);

        $this->assertSame([
            [
                'allergen' => 'Penicillin',
                'severity' => 'life_threatening',
                'reaction' => 'Anaphylaxis',
                'source' => 'medication_register',
            ],
            [
                'allergen' => 'Penicillin',
                'severity' => null,
                'reaction' => null,
                'source' => 'health_profile',
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
            ->assertJsonCount(0, 'allergies')
            ->assertJsonCount(0, 'recorded_allergies')
            ->assertJsonPath('allergy_record.status', 'none')
            ->assertJsonPath('allergy_record.reviewed', null);
    }
}
