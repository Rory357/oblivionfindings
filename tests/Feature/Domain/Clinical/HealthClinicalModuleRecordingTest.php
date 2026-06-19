<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the MODULE-level recording endpoints (no client in the URL — the wizard
 * supplies `client_id`). These routes were previously pointed at a dead controller
 * that wrote a phantom `health_clinical_*` schema; they now route through the
 * canonical Domain services via the shared RecordsClinicalRecords trait.
 *
 * See docs/health-clinical-redesign/PROGRESS.md §1A (de-dup) + §8 (one wizard,
 * two entry points) + B3 (witnesses validator).
 */
class HealthClinicalModuleRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->client = Client::factory()->create();
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

    public function test_module_observation_store_writes_through_canonical_domain(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->from('/health-clinical')
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 78.4],
                'notes' => 'Routine weekly weight.',
            ])
            ->assertRedirect('/health-clinical')
            ->assertSessionHas('success');

        // Canonical Domain table — proves the repoint no longer writes the phantom schema.
        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'recorded_by' => $user->id,
            'observation_type' => 'weight',
            'site_id' => $this->client->site_id,
        ]);

        // The Domain service emits a timeline event; the dead path did not.
        $this->assertDatabaseHas('timeline_events', [
            'type' => 'clinical_observation',
            'client_id' => $this->client->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_module_event_store_persists_witnesses(): void
    {
        // Regression: the old module validator dropped `witnesses` (handoff §7.2 / B3).
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->from('/health-clinical')
            ->post('/health-clinical/events', [
                'client_id' => $this->client->id,
                'event_type' => 'deterioration',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Reduced responsiveness noted on the evening round.',
                'witnesses' => ['Aroha (RN)', 'Hemi (support worker)'],
            ])
            ->assertRedirect('/health-clinical')
            ->assertSessionHas('success');

        $event = ClinicalEvent::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame(['Aroha (RN)', 'Hemi (support worker)'], $event->witnesses);
    }

    public function test_module_event_store_auto_links_high_severity_fall_to_hs(): void
    {
        // Cross-module integration: the repoint must reach ClinicalEventService's
        // HS auto-link (the whole reason for routing through the Domain stack).
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->from('/health-clinical')
            ->post('/health-clinical/events', [
                'client_id' => $this->client->id,
                'event_type' => 'fall',
                'severity' => 'high',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Unwitnessed fall in the bathroom; no obvious injury.',
            ])
            ->assertRedirect('/health-clinical');

        $event = ClinicalEvent::where('client_id', $this->client->id)->firstOrFail();
        $this->assertNotNull($event->linked_hs_event_id, 'High-severity fall should auto-link to an H&S event.');
        $this->assertDatabaseHas('hs_events', [
            'id' => $event->linked_hs_event_id,
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
        ]);
    }

    public function test_module_observation_store_requires_client_id(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->from('/health-clinical')
            ->post('/health-clinical/observations', [
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 80],
            ])
            ->assertSessionHasErrors('client_id');
    }

    public function test_module_recording_forbidden_without_permission(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 80],
            ])
            ->assertForbidden();
    }
}
