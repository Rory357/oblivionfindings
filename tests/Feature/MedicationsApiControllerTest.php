<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationsApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $client;
    protected Client $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'API Test Residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
        ]);

        $this->otherClient = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
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

        $this->actingAs($this->admin, 'sanctum')
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

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/prn-history")
            ->assertNotFound();
    }

    public function test_record_administration_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations", [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();
    }
}
