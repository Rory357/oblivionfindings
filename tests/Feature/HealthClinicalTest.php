<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClinicalEvent;
use App\Models\ClinicalObservation;
use App\Models\ClinicalProtocol;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinicalLead;

    protected User $supportWorker;

    protected User $unauthorizedUser;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->site = Site::factory()->create([
            'name' => 'Kowhai House',
            'type' => 'house',
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        $this->clinicalLead = $this->makeRoleUser('admin');
        $this->createEmployeeProfile($this->clinicalLead);

        $this->supportWorker = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($this->supportWorker);

        $this->unauthorizedUser = User::factory()->create(['approved_at' => now()]);
    }

    // ── Dashboard ──────────────────────────────────────────────────────

    public function test_dashboard_renders_for_authorized_user(): void
    {
        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/index')
                ->has('kpis')
                ->has('overdue_items')
                ->has('recent_events')
                ->has('recent_observations')
            );
    }

    public function test_dashboard_forbidden_without_permission(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->get('/health-clinical')
            ->assertForbidden();
    }

    // ── Observation Register ───────────────────────────────────────────

    public function test_observation_register_renders(): void
    {
        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'data' => ['blood_pressure_systolic' => 120, 'blood_pressure_diastolic' => 80],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/observations')
                ->has('observations.data', 1)
                ->has('filter_options.clients')
                ->has('filter_options.staff')
                ->has('filter_options.observation_types')
            );
    }

    public function test_observation_register_filters_by_client(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 70],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        ClinicalObservation::create([
            'client_id' => $otherClient->id,
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 85],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/observations?client_id=' . $this->client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_observation_register_filters_by_type(): void
    {
        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'data' => ['pulse' => 72],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'mood',
            'data' => ['mood_rating' => 4],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/observations?observation_type=vitals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_observation_register_filters_by_date_range(): void
    {
        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 70],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now()->subDays(10),
        ]);

        ClinicalObservation::create([
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 71],
            'recorded_by' => $this->supportWorker->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/observations?date_from=' . now()->subDays(2)->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    // ── Store Observation ──────────────────────────────────────────────

    public function test_can_store_observation(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'observation_type' => 'vitals',
                'data' => ['blood_pressure_systolic' => 130, 'blood_pressure_diastolic' => 85, 'pulse' => 78],
                'notes' => 'Client resting comfortably.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'recorded_by' => $this->supportWorker->id,
        ]);
    }

    public function test_store_observation_forbidden_without_permission(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'observation_type' => 'vitals',
                'data' => ['pulse' => 72],
            ])
            ->assertForbidden();
    }

    public function test_store_observation_validates_type(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'observation_type' => 'invalid_type',
                'data' => ['foo' => 'bar'],
            ])
            ->assertSessionHasErrors('observation_type');
    }

    public function test_store_observation_updates_linked_protocol(): void
    {
        $protocol = ClinicalProtocol::create([
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'frequency' => 'weekly',
            'next_due_at' => now()->subDay(),
            'status' => 'active',
            'created_by' => $this->clinicalLead->id,
        ]);

        $this->actingAs($this->supportWorker)
            ->post('/health-clinical/observations', [
                'client_id' => $this->client->id,
                'clinical_protocol_id' => $protocol->id,
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 72.5],
            ])
            ->assertRedirect();

        $protocol->refresh();
        $this->assertNotNull($protocol->last_recorded_at);
        $this->assertTrue($protocol->next_due_at->isFuture());
    }

    // ── Clinical Events ────────────────────────────────────────────────

    public function test_event_register_renders(): void
    {
        ClinicalEvent::create([
            'client_id' => $this->client->id,
            'event_type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => now(),
            'description' => 'Client slipped in bathroom.',
            'reported_by' => $this->supportWorker->id,
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Events')
                ->has('events.data', 1)
                ->has('event_types')
            );
    }

    public function test_can_store_event(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/health-clinical/events', [
                'client_id' => $this->client->id,
                'event_type' => 'seizure',
                'severity' => 'high',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Tonic-clonic seizure lasting 90 seconds.',
                'follow_up_required' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_events', [
            'client_id' => $this->client->id,
            'event_type' => 'seizure',
            'severity' => 'high',
            'follow_up_required' => true,
            'reported_by' => $this->supportWorker->id,
        ]);
    }

    // ── Protocols ──────────────────────────────────────────────────────

    public function test_protocols_page_renders(): void
    {
        ClinicalProtocol::create([
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'frequency' => 'daily',
            'status' => 'active',
            'next_due_at' => now(),
            'created_by' => $this->clinicalLead->id,
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/protocols')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Protocols')
                ->has('protocols.data', 1)
            );
    }

    public function test_can_store_protocol(): void
    {
        $this->actingAs($this->clinicalLead)
            ->post('/health-clinical/protocols', [
                'client_id' => $this->client->id,
                'observation_type' => 'weight',
                'frequency' => 'weekly',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_protocols', [
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'frequency' => 'weekly',
            'status' => 'active',
            'created_by' => $this->clinicalLead->id,
        ]);
    }

    public function test_can_update_protocol_status(): void
    {
        $protocol = ClinicalProtocol::create([
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'frequency' => 'daily',
            'status' => 'active',
            'next_due_at' => now(),
            'created_by' => $this->clinicalLead->id,
        ]);

        $this->actingAs($this->clinicalLead)
            ->put('/health-clinical/protocols/' . $protocol->id, [
                'status' => 'paused',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_protocols', [
            'id' => $protocol->id,
            'status' => 'paused',
        ]);
    }

    public function test_store_protocol_forbidden_without_manage_permission(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->post('/health-clinical/protocols', [
                'client_id' => $this->client->id,
                'observation_type' => 'weight',
                'frequency' => 'weekly',
            ])
            ->assertForbidden();
    }

    // ── Client Summary ─────────────────────────────────────────────────

    public function test_client_summary_renders(): void
    {
        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/clients/' . $this->client->id . '/summary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientSummary')
                ->has('client')
                ->has('summary')
                ->has('observation_types')
                ->has('event_types')
            );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function createEmployeeProfile(User $user): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-HC-' . $user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ],
        );
    }
}
