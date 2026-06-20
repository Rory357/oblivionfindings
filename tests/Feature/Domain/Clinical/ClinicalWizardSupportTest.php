<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backend support for the record wizards: the debounced client picker search, the
 * live clinical card shown in the wizard rail, and ABC create-time evidence.
 */
class ClinicalWizardSupportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->user = $this->createUserWithRole('coordinator');
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_client_search_matches_name_and_nhi(): void
    {
        $aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'nhi_number' => 'ZAC5961']);
        Client::factory()->create(['first_name' => 'Hemi', 'last_name' => 'Walker', 'nhi_number' => 'ABC1234']);

        $this->actingAs($this->user)
            ->getJson('/health-clinical/clients/search?q=ngata')
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.id', $aroha->id);

        // NHI is encrypted → searchable by the full number (exact hash match).
        $this->actingAs($this->user)
            ->getJson('/health-clinical/clients/search?q=ZAC5961')
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.nhi', 'ZAC5961');
    }

    public function test_clinical_card_returns_allergies_baseline_and_protocols(): void
    {
        $client = Client::factory()->create();
        ClientMedicalProfile::create([
            'client_id' => $client->id,
            'allergies' => ['Penicillin', 'Peanuts'],
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'observation_type' => ObservationType::Vitals,
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'data' => ['systolic' => 122, 'diastolic' => 78, 'pulse' => 70, 'temperature' => 36.6, 'o2_saturation' => 98],
        ]);
        ClinicalProtocol::factory()->create([
            'client_id' => $client->id,
            'created_by' => $this->user->id,
            'is_active' => true,
            'observation_type' => ObservationType::Weight,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/health-clinical/clients/{$client->id}/clinical-card")
            ->assertOk();

        $this->assertSame(['Penicillin', 'Peanuts'], $response->json('allergies'));
        $this->assertCount(1, $response->json('active_protocols'));
        $this->assertStringContainsString('BP 122/78', $response->json('baseline_vitals.summary'));
    }

    public function test_abc_store_saves_attachments(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $this->actingAs($this->user)
            ->from('/health-clinical')
            ->post("/clients/{$client->id}/behaviour/abc", [
                'occurred_at' => now()->toDateTimeString(),
                'antecedent' => 'Asked to stop a preferred activity.',
                'behaviour' => 'Threw an object and struck own hand.',
                'consequence' => 'Activity paused; supported to calm.',
                'intensity' => 'medium',
                'harm_occurred' => true,
                'attachments' => [UploadedFile::fake()->image('injury.jpg')],
            ])
            ->assertRedirect('/health-clinical');

        $entry = BehaviourAbcEntry::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(1, $entry->attachments()->count());
        $this->assertDatabaseHas('clinical_attachments', [
            'attachable_type' => BehaviourAbcEntry::class,
            'attachable_id' => $entry->id,
            'uploaded_by' => $this->user->id,
        ]);
    }
}
