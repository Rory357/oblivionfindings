<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftClinicalEventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected Site $site;

    protected Shift $shift;

    protected User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);

        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staffUser = $this->createUserWithRole('coordinator');

        $this->shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
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

    public function test_can_record_event_from_shift_context(): void
    {
        $response = $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/events", [
                'event_type' => 'deterioration',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client was more short of breath during care.',
                'immediate_action_taken' => 'Observed, sat client upright, and escalated to RN.',
                'outcome' => 'Breathing settled after intervention.',
                'requires_followup' => true,
                'followup_notes' => 'Check respiratory status again this afternoon.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('shift_id', $this->shift->id);

        $this->assertDatabaseHas('clinical_events', [
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'reported_by' => $this->staffUser->id,
            'event_type' => 'deterioration',
            'severity' => 'medium',
            'requires_followup' => true,
            'followup_notes' => 'Check respiratory status again this afternoon.',
        ]);
    }

    public function test_shift_event_creates_timeline_event(): void
    {
        $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'low',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Minor clinical concern logged for visibility.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('timeline_events', [
            'type' => 'clinical_event',
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'actor_user_id' => $this->staffUser->id,
        ]);
    }

    public function test_shift_hs_linked_event_requires_immediate_action(): void
    {
        $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/events", [
                'event_type' => 'fall',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client fell while transferring.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('immediate_action_taken');

        $this->assertDatabaseCount('clinical_events', 0);
        $this->assertDatabaseCount('hs_events', 0);
    }

    public function test_assigned_support_worker_can_record_shift_event(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $worker->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'status' => 'in_progress',
        ]);

        $this->actingAs($worker)
            ->postJson("/shifts/{$shift->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client reported brief dizziness.',
            ])
            ->assertCreated();
    }

    public function test_unassigned_support_worker_cannot_record_shift_event(): void
    {
        $worker = $this->createUserWithRole('support_worker');

        $this->actingAs($worker)
            ->postJson("/shifts/{$this->shift->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client reported brief dizziness.',
            ])
            ->assertForbidden();
    }

    public function test_shift_event_rejects_invalid_severity(): void
    {
        $this->actingAs($this->staffUser)
            ->postJson("/shifts/{$this->shift->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'urgent',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Test event',
            ])
            ->assertUnprocessable();
    }
}
