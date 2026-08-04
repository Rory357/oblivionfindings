<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationsApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected Client $client;

    protected Client $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $site = Site::factory()->create(['name' => 'Medication API Site']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'API Test Residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
        $this->client->supportWorkers()->attach($this->actor->id);

        $this->otherClient = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
    }

    protected function createMedicationForClient(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Cross Client Test Medication',
            'dosage' => '5mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    public function test_safety_check_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/safety-check")
            ->assertNotFound();
    }

    public function test_prn_history_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient, [
            'is_prn' => true,
            'frequency' => null,
            'dose_times' => null,
            'prn_reason' => 'As needed for pain',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/prn-history")
            ->assertNotFound();
    }

    public function test_record_administration_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations", [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();
    }
}
