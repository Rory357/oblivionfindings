<?php

namespace Tests\Feature;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinicalLead;

    protected User $supportWorker;

    protected User $unauthorizedUser;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->client = Client::factory()->create();
        $this->clinicalLead = $this->makeRoleUser('clinical_lead');
        $this->supportWorker = $this->makeRoleUser('support_worker');
        $this->unauthorizedUser = User::factory()->create(['approved_at' => now()]);
    }

    public function test_dashboard_renders_for_authorized_user(): void
    {
        ClinicalObservation::factory()->create([
            'client_id' => $this->client->id,
            'observation_type' => ObservationType::Vitals,
            'recorded_by' => $this->clinicalLead->id,
        ]);

        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->client->id,
            'created_by' => $this->clinicalLead->id,
            'observation_type' => ObservationType::Weight,
            'frequency' => ProtocolFrequency::Weekly,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addDay(),
            'status' => 'pending',
        ]);

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

    public function test_observation_register_renders(): void
    {
        ClinicalObservation::factory()->create([
            'client_id' => $this->client->id,
            'observation_type' => ObservationType::Weight,
            'recorded_by' => $this->clinicalLead->id,
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

    public function test_event_register_renders(): void
    {
        ClinicalEvent::factory()->create([
            'client_id' => $this->client->id,
            'reported_by' => $this->clinicalLead->id,
            'event_type' => ClinicalEventType::Fall,
        ]);

        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Events')
                ->has('events.data', 1)
            );
    }

    public function test_protocols_page_renders(): void
    {
        ClinicalProtocol::factory()->create([
            'client_id' => $this->client->id,
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

    public function test_client_summary_renders(): void
    {
        $this->actingAs($this->clinicalLead)
            ->get('/health-clinical/clients/' . $this->client->id . '/summary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientSummary')
                ->has('client')
                ->has('summary')
            );
    }

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
}
