<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftClinicalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;
    protected Shift $shift;
    protected User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->staffUser = $this->createUserWithRole('coordinator');

        $this->shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staffUser->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'status' => 'in_progress',
        ]);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    // ── GET due observations ─────────────────────────────────────────────

    public function test_can_get_due_observations_for_shift(): void
    {
        // Create an every-shift protocol
        ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->staffUser)
            ->getJson("/shifts/{$this->shift->id}/clinical/observations/due");

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.observation_type', 'vitals');
    }

    public function test_due_observations_empty_when_no_protocols(): void
    {
        $response = $this->actingAs($this->staffUser)
            ->getJson("/shifts/{$this->shift->id}/clinical/observations/due");

        $response->assertOk();
        $response->assertJsonCount(0, 'items');
    }

    public function test_every_shift_protocol_not_due_if_already_recorded(): void
    {
        ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        // Record vitals for this shift
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
        ]);

        $response = $this->actingAs($this->staffUser)
            ->getJson("/shifts/{$this->shift->id}/clinical/observations/due");

        $response->assertOk();
        $response->assertJsonCount(0, 'items');
    }

    public function test_time_based_protocol_shows_due_within_shift_window(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        // Schedule item within shift window
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        // Schedule item outside shift window
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addHours(12),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->staffUser)
            ->getJson("/shifts/{$this->shift->id}/clinical/observations/due");

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
    }

    // ── POST store observation from shift ─────────────────────────────────

    public function test_can_record_observation_from_shift(): void
    {
        $response = $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/observations", [
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 72.5],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('shift_id', $this->shift->id);

        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'observation_type' => 'weight',
            'recorded_by' => $this->staffUser->id,
        ]);
    }

    public function test_shift_observation_creates_timeline_event(): void
    {
        $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/observations", [
                'observation_type' => 'bowel',
                'data' => ['bristol_type' => 4],
            ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => 'clinical_observation',
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
        ]);
    }

    public function test_shift_observation_completes_protocol_schedule(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/observations", [
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 71.0],
                'protocol_schedule_id' => $schedule->id,
            ]);

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
        $this->assertNotNull($schedule->clinical_observation_id);
    }

    public function test_unauthorized_user_cannot_access_shift_observations(): void
    {
        $otherUser = $this->createUserWithRole('support_worker');
        // Not assigned to this shift

        $response = $this->actingAs($otherUser)
            ->getJson("/shifts/{$this->shift->id}/clinical/observations/due");

        $response->assertForbidden();
    }

    public function test_support_worker_assigned_to_shift_can_record(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $worker->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $response = $this->actingAs($worker)
            ->postJson("/shifts/{$shift->id}/clinical/observations", [
                'observation_type' => 'bowel',
                'data' => ['bristol_type' => 3],
            ]);

        $response->assertCreated();
    }

    public function test_support_worker_cannot_record_clinical_type_from_shift(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $worker->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $response = $this->actingAs($worker)
            ->postJson("/shifts/{$shift->id}/clinical/observations", [
                'observation_type' => 'vitals',
                'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
            ]);

        $response->assertForbidden();
    }
}
