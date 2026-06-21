<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected User $coordinator;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->client = Client::factory()->create();
        $this->client->supportWorkers()->attach($this->staff->id);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helper: create a mock for NotificationService
    // ──────────────────────────────────────────────────────────────

    protected function mockNotificationService(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(NotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(NotificationService::class, $mock);

        return $mock;
    }

    // ──────────────────────────────────────────────────────────────
    //  1. INDEX - Authentication
    // ──────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get('/incidents')->assertRedirect('/login');
    }

    // ──────────────────────────────────────────────────────────────
    //  2. INDEX - Role-based access
    // ──────────────────────────────────────────────────────────────

    public function test_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk();
    }

    public function test_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/incidents')
            ->assertOk();
    }

    public function test_index_accessible_by_staff_with_view_assigned(): void
    {
        $this->actingAs($this->staff)
            ->get('/incidents')
            ->assertOk();
    }

    public function test_index_blocked_for_user_without_incident_permissions(): void
    {
        $userNoPerms = User::factory()->create([
            'role' => 'hr',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $userNoPerms->roles()->attach(Role::where('name', 'hr')->first());

        $this->actingAs($userNoPerms)
            ->get('/incidents')
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  3. INDEX - Inertia response
    // ──────────────────────────────────────────────────────────────

    public function test_index_returns_inertia_page_with_rows(): void
    {
        ClientIncident::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('incidents/index')
                ->has('rows')
                ->has('filters')
                ->has('clients')
                ->has('tabCounts')
                ->has('hero')
                ->where('rowsKind', 'incidents')
            );
    }

    public function test_index_admin_receives_clients_and_sites_list(): void
    {
        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clients')
                ->has('sites')
            );
    }

    // ──────────────────────────────────────────────────────────────
    //  15. Staff only sees assigned client incidents
    // ──────────────────────────────────────────────────────────────

    public function test_staff_sees_only_assigned_client_incidents(): void
    {
        // Incident for assigned client
        $assignedIncident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        // Incident for unassigned client
        $unassignedClient = Client::factory()->create();
        $unassignedIncident = ClientIncident::factory()->create(['client_id' => $unassignedClient->id]);

        $response = $this->actingAs($this->staff)->get('/incidents');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.id', $assignedIncident->id)
        );
    }

    public function test_admin_sees_all_incidents_regardless_of_assignment(): void
    {
        ClientIncident::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 3)
            );
    }

    // ── Detail-over-list (IncidentDetailDialog) ──

    public function test_index_without_incident_param_has_null_detail(): void
    {
        ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('detail', null));
    }

    public function test_index_with_incident_param_returns_detail(): void
    {
        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'type' => 'fall',
            'description' => 'slipped on a wet floor',
        ]);
        IncidentFollowup::factory()->create(['client_incident_id' => $incident->id, 'completed_at' => null]);

        $this->actingAs($this->admin)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.id', $incident->id)
                ->where('detail.type', 'fall')
                ->where('detail.description', 'slipped on a wet floor')
                ->where('detail.client.id', $this->client->id)
                ->has('detail.followups', 1)
                ->has('detail.attachments')
                ->has('detail.can')
            );
    }

    public function test_detail_not_returned_for_unviewable_incident(): void
    {
        // staff may only view incidents for their assigned clients
        $otherClient = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $otherClient->id]);

        $this->actingAs($this->staff)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('detail', null));
    }

    // ──────────────────────────────────────────────────────────────
    //  16. All filters
    // ──────────────────────────────────────────────────────────────

    public function test_tab_review_shows_only_submitted(): void
    {
        ClientIncident::factory()->submitted()->create();
        ClientIncident::factory()->reviewed()->create();
        ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->admin)
            ->get('/incidents?tab=review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'review')
                ->has('rows.data', 1)
                ->where('rows.data.0.status', 'submitted')
            );
    }

    public function test_tab_closed_shows_only_closed(): void
    {
        ClientIncident::factory()->create(['status' => 'closed']);
        ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->admin)
            ->get('/incidents?tab=closed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.status', 'closed')
            );
    }

    public function test_tab_near_misses_filters_type(): void
    {
        ClientIncident::factory()->create(['type' => 'near_miss']);
        ClientIncident::factory()->create(['type' => 'fall']);

        $this->actingAs($this->admin)
            ->get('/incidents?tab=near_misses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.type', 'near_miss')
            );
    }

    public function test_legacy_near_miss_query_lands_on_tab(): void
    {
        ClientIncident::factory()->create(['type' => 'near_miss']);
        ClientIncident::factory()->create(['type' => 'fall']);

        $this->actingAs($this->admin)
            ->get('/incidents?type=near_miss')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'near_misses')
                ->has('rows.data', 1)
            );
    }

    public function test_followups_tab_returns_open_followup_worklist(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);
        IncidentFollowup::factory()->create(['client_incident_id' => $incident->id, 'completed_at' => null]);

        // a completed follow-up must be excluded from the worklist
        $other = ClientIncident::factory()->create(['client_id' => $this->client->id]);
        IncidentFollowup::factory()->completed()->create(['client_incident_id' => $other->id]);

        $this->actingAs($this->admin)
            ->get('/incidents?tab=followups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rowsKind', 'followups')
                ->has('rows.data', 1)
                ->where('rows.data.0.incident_id', $incident->id)
            );
    }

    public function test_tab_counts_reflect_data(): void
    {
        ClientIncident::factory()->create(['type' => 'near_miss', 'status' => 'draft']);
        ClientIncident::factory()->submitted()->create();
        ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->admin)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tabCounts.all', 3)
                ->where('tabCounts.near_misses', 1)
                ->where('tabCounts.review', 1)
                ->where('tabCounts.closed', 1)
            );
    }

    public function test_filter_by_severity(): void
    {
        ClientIncident::factory()->highSeverity()->create();
        ClientIncident::factory()->create(['severity' => 'low']);
        ClientIncident::factory()->create(['severity' => 'medium']);

        $this->actingAs($this->admin)
            ->get('/incidents?severity=high')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.severity', 'high')
            );
    }

    public function test_filter_by_client_id(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        ClientIncident::factory()->create(['client_id' => $clientA->id]);
        ClientIncident::factory()->create(['client_id' => $clientB->id]);

        $this->actingAs($this->admin)
            ->get("/incidents?client_id={$clientA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.client.id', $clientA->id)
            );
    }

    public function test_filter_by_date_range(): void
    {
        ClientIncident::factory()->create(['occurred_at' => now()->subDays(5)]);
        ClientIncident::factory()->create(['occurred_at' => now()->subMonths(3)]);

        $from = now()->subMonth()->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $this->actingAs($this->admin)
            ->get("/incidents?from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
            );
    }

    public function test_filter_by_source(): void
    {
        ClientIncident::factory()->create(['source' => 'sensor']);
        ClientIncident::factory()->create(['source' => 'manual']);

        $this->actingAs($this->admin)
            ->get('/incidents?source=sensor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.source', 'sensor')
            );
    }

    public function test_filter_by_site_id(): void
    {
        $siteA = \App\Models\Site::factory()->create();
        $siteB = \App\Models\Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        ClientIncident::factory()->create(['client_id' => $clientA->id]);
        ClientIncident::factory()->create(['client_id' => $clientB->id]);

        $this->actingAs($this->admin)
            ->get("/incidents?site_id={$siteA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.client.id', $clientA->id)
            );
    }

    public function test_filter_by_search_query(): void
    {
        ClientIncident::factory()->create([
            'description' => 'A unique xylophone incident description',
            'type' => 'fall',
            'title' => 'fall incident',
        ]);
        ClientIncident::factory()->create([
            'description' => 'Another incident',
            'type' => 'medication_error',
            'title' => 'medication_error incident',
        ]);

        $this->actingAs($this->admin)
            ->get('/incidents?q=xylophone')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
            );
    }

    public function test_filter_by_search_matches_type(): void
    {
        ClientIncident::factory()->create([
            'type' => 'fall',
            'title' => 'fall incident',
        ]);
        ClientIncident::factory()->create([
            'type' => 'medication_error',
            'title' => 'medication_error incident',
        ]);

        $this->actingAs($this->admin)
            ->get('/incidents?q=fall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
            );
    }

    public function test_filter_by_search_matches_title(): void
    {
        ClientIncident::factory()->create([
            'title' => 'UniqueSearchableTitle incident',
            'type' => 'other',
            'description' => 'nothing special',
        ]);
        ClientIncident::factory()->create([
            'title' => 'Different incident',
            'type' => 'fall',
            'description' => 'something else',
        ]);

        $this->actingAs($this->admin)
            ->get('/incidents?q=UniqueSearchableTitle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
            );
    }

    // ──────────────────────────────────────────────────────────────
    //  CREATE - Authentication & authorization
    // ──────────────────────────────────────────────────────────────

    public function test_create_requires_authentication(): void
    {
        $this->get('/incidents/create')->assertRedirect('/login');
    }

    public function test_create_redirects_to_modal_report_wizard(): void
    {
        // The report flow is now a modal over the register: /incidents/create
        // redirects to the index with ?report= so the wizard auto-opens.
        $this->actingAs($this->admin)
            ->get('/incidents/create')
            ->assertRedirect(route('incidents.index', ['report' => 'incident']));
    }

    public function test_create_near_miss_redirects_with_near_miss_report(): void
    {
        $this->actingAs($this->staff)
            ->get('/incidents/create?type=near_miss')
            ->assertRedirect(route('incidents.index', ['report' => 'near_miss']));
    }

    public function test_create_forwards_client_prefill(): void
    {
        $this->actingAs($this->admin)
            ->get('/incidents/create?client_id=' . $this->client->id)
            ->assertRedirect(route('incidents.index', ['report' => 'incident', 'report_client_id' => $this->client->id]));
    }

    public function test_create_resume_draft_redirects_to_detail(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->admin)
            ->get('/incidents/create?incident=' . $incident->id)
            ->assertRedirect(route('incidents.index', ['incident' => $incident->id]));
    }

    // ──────────────────────────────────────────────────────────────
    //  STORE - CRUD with valid data
    // ──────────────────────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $this->post('/incidents', [])->assertRedirect('/login');
    }

    public function test_store_creates_incident_in_draft_status(): void
    {
        $this->mockNotificationService();

        $response = $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'medium',
                'occurred_at' => now()->format('Y-m-d H:i:s'),
                'description' => 'Client fell in the living room.',
                'requires_followup' => true,
                'immediate_action_taken' => 'Applied ice pack',
                'witnesses' => 'Jane Doe',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('client_incidents', [
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
            'type' => 'fall',
            'severity' => 'medium',
            'status' => 'draft',
            'title' => 'fall incident',
            'description' => 'Client fell in the living room.',
            'requires_followup' => true,
            'immediate_action_taken' => 'Applied ice pack',
            'witnesses' => 'Jane Doe',
        ]);
    }

    public function test_store_generates_title_from_type(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'medication_error',
                'severity' => 'low',
            ]);

        $this->assertDatabaseHas('client_incidents', [
            'title' => 'medication_error incident',
        ]);
    }

    public function test_store_sets_source_manual_and_creates_followups(): void
    {
        $this->mockNotificationService();

        $response = $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'low',
                'description' => 'Slipped',
                'followups' => [
                    ['notes' => 'Update care plan', 'assigned_to_user_id' => $this->coordinator->id, 'due_at' => now()->addDays(3)->format('Y-m-d')],
                ],
            ]);

        $response->assertRedirect();
        $incident = ClientIncident::where('client_id', $this->client->id)->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('manual', $incident->source);
        $this->assertTrue((bool) $incident->requires_followup);
        $this->assertDatabaseHas('incident_followups', [
            'client_incident_id' => $incident->id,
            'notes' => 'Update care plan',
            'assigned_to_user_id' => $this->coordinator->id,
        ]);
    }

    public function test_store_near_miss_persists_potential_and_hazard(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'near_miss',
                'severity' => 'low',
                'description' => 'Almost fell',
                'potential_severity' => 'high',
                'potential_consequence' => 'Could have broken a hip',
                'hazard' => 'Wet floor, no sign',
            ]);

        $incident = ClientIncident::where('type', 'near_miss')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('high', $incident->potential_severity);
        $this->assertSame('manual', $incident->source);
        $this->assertSame('Wet floor, no sign', $incident->metadata['hazard'] ?? null);
    }

    public function test_store_with_template(): void
    {
        $this->mockNotificationService();

        $template = IncidentTemplate::create([
            'name' => 'Fall Template',
            'type' => 'fall',
            'severity' => 'high',
            'is_active' => true,
        ]);

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'template_id' => $template->id,
                'type' => 'fall',
                'severity' => 'high',
            ]);

        $this->assertDatabaseHas('client_incidents', [
            'template_id' => $template->id,
            'type' => 'fall',
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  STORE - Validation
    // ──────────────────────────────────────────────────────────────

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [])
            ->assertSessionHasErrors(['client_id', 'type', 'severity']);
    }

    public function test_store_validates_client_exists(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => 99999,
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertSessionHasErrors(['client_id']);
    }

    public function test_store_validates_severity_values(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'critical',
            ])
            ->assertSessionHasErrors(['severity']);
    }

    public function test_store_validates_type_max_length(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => str_repeat('a', 121),
                'severity' => 'low',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_store_validates_occurred_at_is_date(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'low',
                'occurred_at' => 'not-a-date',
            ])
            ->assertSessionHasErrors(['occurred_at']);
    }

    public function test_store_validates_template_exists(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'template_id' => 99999,
                'type' => 'fall',
                'severity' => 'low',
            ])
            ->assertSessionHasErrors(['template_id']);
    }

    // ──────────────────────────────────────────────────────────────
    //  17. High severity notification triggers on store
    // ──────────────────────────────────────────────────────────────

    public function test_store_high_severity_sends_notification(): void
    {
        $mock = $this->mockNotificationService();

        $mock->shouldReceive('notifyCrud')
            ->once()
            ->withArgs(function ($actor, $action, $label, $entity, $client, $options) {
                return $action === 'created'
                    && $label === 'incident'
                    && ($options['event_key'] ?? null) === 'incidents.high_severity_alert';
            })
            ->andReturnNull();

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'high',
                'description' => 'Serious fall',
            ]);
    }

    public function test_store_low_severity_does_not_send_high_severity_notification(): void
    {
        $mock = $this->mockNotificationService();

        $mock->shouldNotReceive('notifyCrud')
            ->withArgs(function ($actor, $action, $label, $entity, $client, $options) {
                return ($options['event_key'] ?? null) === 'incidents.high_severity_alert';
            });

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'low',
                'description' => 'Minor fall',
            ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  SHOW - Authentication & authorization
    // ──────────────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $this->get("/incidents/{$incident->id}")->assertRedirect('/login');
    }

    public function test_show_accessible_by_admin(): void
    {
        $incident = ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('incidents/show')
                ->where('detail.id', $incident->id)
            );
    }

    public function test_show_accessible_by_staff_for_assigned_client(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->staff)
            ->get("/incidents/{$incident->id}")
            ->assertOk();
    }

    public function test_show_blocked_for_staff_on_unassigned_client(): void
    {
        $otherClient = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $otherClient->id]);

        $this->actingAs($this->staff)
            ->get("/incidents/{$incident->id}")
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  18. Show page returns correct can permissions
    // ──────────────────────────────────────────────────────────────

    public function test_show_returns_detail_can_permissions(): void
    {
        $incident = ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('detail.can.update')
                ->has('detail.can.submit')
                ->has('detail.can.review')
                ->has('detail.can.close')
                ->has('detail.can.followupsManage')
                ->has('detail.can.followupsComplete')
                ->has('detail.can.portalManage')
                ->has('detail.can.raiseCorrectiveAction')
            );
    }

    public function test_show_detail_carries_records(): void
    {
        $incident = ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('detail.client')
                ->has('detail.reporter')
                ->has('detail.attachments')
                ->has('detail.followups')
                ->has('detail.assignable_staff')
            );
    }

    // ──────────────────────────────────────────────────────────────
    //  UPDATE - CRUD
    // ──────────────────────────────────────────────────────────────

    public function test_update_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $this->put("/incidents/{$incident->id}", [])->assertRedirect('/login');
    }

    public function test_update_modifies_draft_incident(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => 'behavioural',
                'severity' => 'low',
                'description' => 'Updated description',
                'occurred_at' => now()->format('Y-m-d H:i:s'),
                'requires_followup' => false,
                'immediate_action_taken' => 'Talked to client',
                'witnesses' => 'Staff A',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('behavioural', $incident->type);
        $this->assertEquals('low', $incident->severity);
        $this->assertEquals('Updated description', $incident->description);
        $this->assertEquals('behavioural incident', $incident->title);
    }

    public function test_update_blocked_on_closed_incident(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'closed',
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  8. Core fields locked after submission
    // ──────────────────────────────────────────────────────────────

    public function test_core_fields_locked_after_submission(): void
    {
        $incident = ClientIncident::factory()->submitted()->create([
            'type' => 'fall',
            'severity' => 'medium',
            'description' => 'Original description',
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => 'behavioural',
                'severity' => 'high',
                'description' => 'Changed description',
                'review_notes' => 'Manager notes',
            ])
            ->assertRedirect();

        $incident->refresh();
        // Core fields should not have changed
        $this->assertEquals('fall', $incident->type);
        $this->assertEquals('medium', $incident->severity);
        $this->assertEquals('Original description', $incident->description);
        // Manager-only fields should have been updated
        $this->assertEquals('Manager notes', $incident->review_notes);
    }

    public function test_core_fields_locked_after_review(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create([
            'type' => 'fall',
            'severity' => 'low',
            'description' => 'Original reviewed description',
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => 'injury',
                'severity' => 'high',
                'description' => 'Attempt to change',
                'review_notes' => 'New review notes',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('fall', $incident->type);
        $this->assertEquals('low', $incident->severity);
        $this->assertEquals('Original reviewed description', $incident->description);
        $this->assertEquals('New review notes', $incident->review_notes);
    }

    // ──────────────────────────────────────────────────────────────
    //  9. Portal visibility management requires portal.manage
    // ──────────────────────────────────────────────────────────────

    public function test_admin_can_set_portal_visible(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
            'portal_visible' => false,
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => $incident->type,
                'severity' => $incident->severity,
                'portal_visible' => true,
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertTrue($incident->portal_visible);
    }

    public function test_staff_cannot_set_portal_visible(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
            'portal_visible' => false,
        ]);

        $this->actingAs($this->staff)
            ->put("/incidents/{$incident->id}", [
                'type' => $incident->type,
                'severity' => $incident->severity,
                'portal_visible' => true,
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertFalse($incident->portal_visible);
    }

    // ──────────────────────────────────────────────────────────────
    //  SUBMIT
    // ──────────────────────────────────────────────────────────────

    public function test_submit_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $this->post("/incidents/{$incident->id}/submit")->assertRedirect('/login');
    }

    public function test_submit_changes_status_to_submitted(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('submitted', $incident->status);
        $this->assertNotNull($incident->submitted_at);
    }

    public function test_submit_sends_notification(): void
    {
        $mock = $this->mockNotificationService();

        $mock->shouldReceive('notifyCrud')
            ->atLeast()->once()
            ->andReturnNull();

        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();
    }

    public function test_submit_high_severity_sends_extra_alert(): void
    {
        $mock = $this->mockNotificationService();

        $callCount = 0;
        $mock->shouldReceive('notifyCrud')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return null;
            });

        $incident = ClientIncident::factory()->highSeverity()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();

        // High severity triggers the standard notification + the extra high severity alert
        $this->assertEquals(2, $callCount, 'Expected 2 notifications for high severity submit (standard + extra alert)');
    }

    public function test_submit_only_allowed_by_reporter(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        // Another staff member cannot submit someone else's incident
        $otherStaff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $otherStaff->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->client->supportWorkers()->attach($otherStaff->id);

        $this->actingAs($otherStaff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertForbidden();
    }

    public function test_submit_not_allowed_on_already_submitted_incident(): void
    {
        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  REVIEW
    // ──────────────────────────────────────────────────────────────

    public function test_review_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->submitted()->create();
        $this->post("/incidents/{$incident->id}/review")->assertRedirect('/login');
    }

    public function test_review_changes_status_to_reviewed(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review", [
                'review_notes' => 'Reviewed and noted.',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('reviewed', $incident->status);
        $this->assertEquals($this->coordinator->id, $incident->reviewed_by);
        $this->assertNotNull($incident->reviewed_at);
        $this->assertEquals('Reviewed and noted.', $incident->review_notes);
    }

    public function test_review_sends_notification(): void
    {
        $mock = $this->mockNotificationService();
        $mock->shouldReceive('notifyCrud')->once()->andReturnNull();

        $incident = ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review")
            ->assertRedirect();
    }

    // ──────────────────────────────────────────────────────────────
    //  19. Cannot review non-submitted incidents
    // ──────────────────────────────────────────────────────────────

    public function test_cannot_review_draft_incident(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review")
            ->assertForbidden();
    }

    public function test_cannot_review_reviewed_incident(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review")
            ->assertForbidden();
    }

    public function test_cannot_review_closed_incident(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review")
            ->assertForbidden();
    }

    public function test_staff_cannot_review_incidents(): void
    {
        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/review")
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  CLOSE
    // ──────────────────────────────────────────────────────────────

    public function test_close_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create();
        $this->post("/incidents/{$incident->id}/close")->assertRedirect('/login');
    }

    public function test_close_changes_status_to_closed(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved with no further action',
                'closed_notes' => 'No issues remain.',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('closed', $incident->status);
        $this->assertEquals($this->coordinator->id, $incident->closed_by);
        $this->assertNotNull($incident->closed_at);
        $this->assertEquals('Resolved with no further action', $incident->closed_outcome);
        $this->assertEquals('No issues remain.', $incident->closed_notes);
    }

    public function test_closing_incident_resolves_linked_control_room_alert(): void
    {
        $this->mockNotificationService();

        $alert = \App\Models\ControlRoomAlert::factory()->open()->create();
        $incident = ClientIncident::factory()->reviewed()->create(['control_room_alert_id' => $alert->id]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertRedirect();

        // State-sync (Gap D): the linked alert resolves with the incident.
        $alert->refresh();
        $this->assertSame('resolved', $alert->status);
        $this->assertSame('incident_closed', $alert->resolution_code);
    }

    // ── Corrective actions (Option B: raised from the incident, governed in H&S) ──

    public function test_raise_corrective_action_creates_hs_register_row(): void
    {
        $incident = ClientIncident::factory()->create();
        // The ClientIncidentObserver records the HsEvent when the incident is created.
        $hsEvent = \App\Models\HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->first();
        $this->assertNotNull($hsEvent, 'Expected the observer to record an HsEvent for the incident.');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/corrective-actions", [
                'title' => 'Install a grab rail in the bathroom',
                'priority' => 'high',
            ])
            ->assertRedirect();

        // No copy on the incident — it lives in the H&S register, linked to the event.
        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $hsEvent->id,
            'title' => 'Install a grab rail in the bathroom',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_raise_corrective_action_requires_permission(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/corrective-actions", ['title' => 'X'])
            ->assertForbidden();
    }

    public function test_close_requires_closed_outcome(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [])
            ->assertSessionHasErrors(['closed_outcome']);
    }

    // ──────────────────────────────────────────────────────────────
    //  20. Cannot close non-reviewed incidents
    // ──────────────────────────────────────────────────────────────

    public function test_cannot_close_draft_incident(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertForbidden();
    }

    public function test_cannot_close_submitted_incident(): void
    {
        $incident = ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertForbidden();
    }

    public function test_cannot_close_already_closed_incident(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved again',
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  7. Cannot close with open followups
    // ──────────────────────────────────────────────────────────────

    public function test_cannot_close_with_open_followups(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create();

        IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'completed_at' => null,
        ]);

        $response = $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $incident->refresh();
        $this->assertNotEquals('closed', $incident->status);
    }

    public function test_can_close_when_all_followups_are_completed(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create();

        IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('closed', $incident->status);
    }

    // ──────────────────────────────────────────────────────────────
    //  REOPEN
    // ──────────────────────────────────────────────────────────────

    public function test_reopen_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'closed']);
        $this->post("/incidents/{$incident->id}/reopen")->assertRedirect('/login');
    }

    public function test_reopen_changes_closed_to_reviewed(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->create([
            'status' => 'closed',
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Previously resolved',
            'closed_notes' => 'Previous notes',
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New information received from family',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('reviewed', $incident->status);
        $this->assertEquals($this->coordinator->id, $incident->reopened_by);
        $this->assertNotNull($incident->reopened_at);
        $this->assertEquals('New information received from family', $incident->reopened_reason);
        // Closure fields should be cleared
        $this->assertNull($incident->closed_by);
        $this->assertNull($incident->closed_at);
        $this->assertNull($incident->closed_outcome);
        $this->assertNull($incident->closed_notes);
    }

    public function test_reopen_requires_reopened_reason(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [])
            ->assertSessionHasErrors(['reopened_reason']);
    }

    public function test_reopen_validates_reason_max_length(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => str_repeat('a', 2001),
            ])
            ->assertSessionHasErrors(['reopened_reason']);
    }

    // ──────────────────────────────────────────────────────────────
    //  21. Cannot reopen non-closed incidents
    // ──────────────────────────────────────────────────────────────

    public function test_cannot_reopen_draft_incident(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'Some reason',
            ])
            ->assertForbidden();
    }

    public function test_cannot_reopen_submitted_incident(): void
    {
        $incident = ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'Some reason',
            ])
            ->assertForbidden();
    }

    public function test_cannot_reopen_reviewed_incident(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'Some reason',
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  5. Full lifecycle: draft -> submit -> review -> close
    // ──────────────────────────────────────────────────────────────

    public function test_full_lifecycle_draft_submit_review_close(): void
    {
        $this->mockNotificationService();

        // 1. Create (draft)
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $this->client->id,
                'type' => 'fall',
                'severity' => 'medium',
                'description' => 'Lifecycle test incident',
            ]);

        $incident = ClientIncident::latest()->first();
        $this->assertEquals('draft', $incident->status);

        // 2. Submit
        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();
        $incident->refresh();
        $this->assertEquals('submitted', $incident->status);

        // 3. Review
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review", [
                'review_notes' => 'Looks good.',
            ])
            ->assertRedirect();
        $incident->refresh();
        $this->assertEquals('reviewed', $incident->status);

        // 4. Close
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'No further action',
            ])
            ->assertRedirect();
        $incident->refresh();
        $this->assertEquals('closed', $incident->status);
    }

    // ──────────────────────────────────────────────────────────────
    //  6. Reopen: closed -> reopen (requires reason)
    // ──────────────────────────────────────────────────────────────

    public function test_lifecycle_close_then_reopen(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->create([
            'status' => 'closed',
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Resolved',
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New evidence emerged',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('reviewed', $incident->status);
        $this->assertNull($incident->closed_at);
    }

    // ──────────────────────────────────────────────────────────────
    //  10. Attachment upload only in draft status
    // ──────────────────────────────────────────────────────────────

    public function test_attachment_upload_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $this->post("/incidents/{$incident->id}/attachments")->assertRedirect('/login');
    }

    public function test_attachment_upload_in_draft_status(): void
    {
        // Uploads now land on the PRIVATE disk (controller stores 'disk' => 'private').
        Storage::fake('private');

        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 500, 'application/pdf');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [
                'file' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_incident_attachments', [
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'original_name' => 'report.pdf',
        ]);
    }

    public function test_attachment_upload_blocked_on_submitted_incident(): void
    {
        Storage::fake('public');

        $incident = ClientIncident::factory()->submitted()->create();

        $file = UploadedFile::fake()->create('report.pdf', 500, 'application/pdf');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_attachment_upload_blocked_on_reviewed_incident(): void
    {
        Storage::fake('public');

        $incident = ClientIncident::factory()->reviewed()->create();

        $file = UploadedFile::fake()->create('report.pdf', 500, 'application/pdf');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_attachment_upload_blocked_on_closed_incident(): void
    {
        Storage::fake('public');

        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $file = UploadedFile::fake()->create('report.pdf', 500, 'application/pdf');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_attachment_upload_validates_file_required(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [])
            ->assertSessionHasErrors(['file']);
    }

    public function test_attachment_upload_validates_max_size(): void
    {
        Storage::fake('public');

        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        // 10240 KB is the max; create file exceeding it
        $file = UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf');

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/attachments", [
                'file' => $file,
            ])
            ->assertSessionHasErrors(['file']);
    }

    // ──────────────────────────────────────────────────────────────
    //  11. Attachment removal only in draft
    // ──────────────────────────────────────────────────────────────

    public function test_attachment_removal_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->delete("/incidents/{$incident->id}/attachments/{$attachment->id}")
            ->assertRedirect('/login');
    }

    public function test_attachment_removal_in_draft_status(): void
    {
        Storage::fake('public');

        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->admin)
            ->delete("/incidents/{$incident->id}/attachments/{$attachment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('client_incident_attachments', [
            'id' => $attachment->id,
        ]);
    }

    public function test_attachment_removal_blocked_on_submitted_incident(): void
    {
        $incident = ClientIncident::factory()->submitted()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->admin)
            ->delete("/incidents/{$incident->id}/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_attachment_removal_fails_when_attachment_belongs_to_different_incident(): void
    {
        Storage::fake('public');

        $incidentA = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);
        $incidentB = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incidentB->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->admin)
            ->delete("/incidents/{$incidentA->id}/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    //  12. Attachment download
    // ──────────────────────────────────────────────────────────────

    public function test_attachment_download_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->get("/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertRedirect('/login');
    }

    public function test_attachment_download_accessible_by_admin(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('incident_attachments/test.txt', 'file content');

        $incident = ClientIncident::factory()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.txt',
            'path' => 'incident_attachments/test.txt',
            'mime' => 'text/plain',
            'mime_type' => 'text/plain',
            'size' => 12,
        ]);

        $this->actingAs($this->admin)
            ->get("/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    public function test_attachment_download_blocked_for_staff_on_unassigned_client(): void
    {
        $otherClient = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $otherClient->id]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->staff)
            ->get("/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_attachment_download_returns_404_for_mismatched_incident(): void
    {
        Storage::fake('public');

        $incidentA = ClientIncident::factory()->create();
        $incidentB = ClientIncident::factory()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incidentB->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->admin)
            ->get("/incidents/{$incidentA->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    //  13. Attachment portal_visible toggle (updateAttachment)
    // ──────────────────────────────────────────────────────────────

    public function test_attachment_portal_visible_toggle_requires_portal_manage(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'portal_visible' => false,
        ]);

        // Staff does not have incidents.portal.manage
        $this->actingAs($this->staff)
            ->patch("/incidents/{$incident->id}/attachments/{$attachment->id}", [
                'portal_visible' => true,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_toggle_attachment_portal_visible(): void
    {
        $incident = ClientIncident::factory()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'portal_visible' => false,
        ]);

        $this->actingAs($this->admin)
            ->patch("/incidents/{$incident->id}/attachments/{$attachment->id}", [
                'portal_visible' => true,
            ])
            ->assertRedirect();

        $attachment->refresh();
        $this->assertTrue($attachment->portal_visible);
    }

    public function test_attachment_portal_visible_toggle_validates_required_field(): void
    {
        $incident = ClientIncident::factory()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'portal_visible' => false,
        ]);

        $this->actingAs($this->admin)
            ->patch("/incidents/{$incident->id}/attachments/{$attachment->id}", [])
            ->assertSessionHasErrors(['portal_visible']);
    }

    public function test_attachment_portal_visible_toggle_returns_404_for_mismatched_incident(): void
    {
        $incidentA = ClientIncident::factory()->create();
        $incidentB = ClientIncident::factory()->create();

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incidentB->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'public',
            'original_name' => 'test.pdf',
            'path' => 'incident_attachments/test.pdf',
            'mime' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'portal_visible' => false,
        ]);

        $this->actingAs($this->admin)
            ->patch("/incidents/{$incidentA->id}/attachments/{$attachment->id}", [
                'portal_visible' => true,
            ])
            ->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    //  14. Followup create and complete
    // ──────────────────────────────────────────────────────────────

    public function test_followup_create_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $this->post("/incidents/{$incident->id}/followups")->assertRedirect('/login');
    }

    public function test_followup_create_by_coordinator(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->submitted()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/followups", [
                'notes' => 'Follow up with family',
                'assigned_to_user_id' => $this->staff->id,
                'due_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('incident_followups', [
            'client_incident_id' => $incident->id,
            'assigned_to_user_id' => $this->staff->id,
            'notes' => 'Follow up with family',
            'created_by' => $this->coordinator->id,
        ]);
    }

    public function test_followup_create_blocked_for_staff_without_manage_permission(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/followups", [
                'notes' => 'Attempt follow-up',
            ])
            ->assertForbidden();
    }

    public function test_followup_complete_by_assigned_staff(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'assigned_to_user_id' => $this->staff->id,
            'completed_at' => null,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/followups/{$followup->id}/complete", [
                'notes' => 'Completed the follow-up task.',
            ])
            ->assertRedirect();

        $followup->refresh();
        $this->assertNotNull($followup->completed_at);
    }

    public function test_followup_complete_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->create();
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'completed_at' => null,
        ]);

        $this->post("/incidents/{$incident->id}/followups/{$followup->id}/complete")
            ->assertRedirect('/login');
    }

    public function test_followup_complete_by_coordinator_with_manage_permission(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->create();
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'completed_at' => null,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/followups/{$followup->id}/complete")
            ->assertRedirect();

        $followup->refresh();
        $this->assertNotNull($followup->completed_at);
    }

    public function test_followup_complete_blocked_for_unassigned_staff(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id]);

        $otherUser = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $otherUser->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->client->supportWorkers()->attach($otherUser->id);

        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'assigned_to_user_id' => $this->staff->id,
            'completed_at' => null,
        ]);

        $this->actingAs($otherUser)
            ->post("/incidents/{$incident->id}/followups/{$followup->id}/complete")
            ->assertForbidden();
    }

    public function test_followup_complete_returns_404_for_mismatched_incident(): void
    {
        $incidentA = ClientIncident::factory()->create();
        $incidentB = ClientIncident::factory()->create();
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incidentB->id,
            'completed_at' => null,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incidentA->id}/followups/{$followup->id}/complete")
            ->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    //  Update validation
    // ──────────────────────────────────────────────────────────────

    public function test_update_validates_required_fields(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [])
            ->assertSessionHasErrors(['type', 'severity']);
    }

    public function test_update_validates_severity(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'reported_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/incidents/{$incident->id}", [
                'type' => 'fall',
                'severity' => 'extreme',
            ])
            ->assertSessionHasErrors(['severity']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Staff-level update permissions
    // ──────────────────────────────────────────────────────────────

    public function test_staff_can_update_own_draft_incident(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->put("/incidents/{$incident->id}", [
                'type' => 'behavioural',
                'severity' => 'low',
                'description' => 'Updated by staff',
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('behavioural', $incident->type);
    }

    public function test_staff_cannot_update_someone_elses_incident(): void
    {
        $otherClient = Client::factory()->create();
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $otherClient->id,
            'reported_by' => $this->coordinator->id,
        ]);

        $this->actingAs($this->staff)
            ->put("/incidents/{$incident->id}", [
                'type' => 'fall',
                'severity' => 'low',
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────
    //  Additional edge cases
    // ──────────────────────────────────────────────────────────────

    public function test_store_staff_cannot_create_for_unassigned_client(): void
    {
        $unassignedClient = Client::factory()->create();

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'client_id' => $unassignedClient->id,
                'type' => 'fall',
                'severity' => 'low',
            ])
            ->assertForbidden();
    }

    public function test_close_sends_notification(): void
    {
        $mock = $this->mockNotificationService();
        $mock->shouldReceive('notifyCrud')->once()->andReturnNull();

        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertRedirect();
    }

    public function test_reopen_sends_notification(): void
    {
        $mock = $this->mockNotificationService();
        $mock->shouldReceive('notifyCrud')->once()->andReturnNull();

        $incident = ClientIncident::factory()->create(['status' => 'closed']);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New info',
            ])
            ->assertRedirect();
    }

    public function test_staff_cannot_close_incidents(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertForbidden();
    }

    public function test_staff_cannot_reopen_incidents(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'closed',
            'client_id' => $this->client->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'Try reopen',
            ])
            ->assertForbidden();
    }

    public function test_review_preserves_existing_review_notes_when_not_provided(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->submitted()->create([
            'review_notes' => 'Existing notes',
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/review")
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('reviewed', $incident->status);
        $this->assertEquals('Existing notes', $incident->review_notes);
    }

    public function test_close_validates_closed_outcome_max_length(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => str_repeat('a', 121),
            ])
            ->assertSessionHasErrors(['closed_outcome']);
    }

    public function test_index_filters_are_echoed_back(): void
    {
        // The redesign replaced the status/reviewed filters with tabs; the echoed
        // filter set is now q / tab / severity / source / from / to (+ site/client).
        $this->actingAs($this->admin)
            ->get('/incidents?q=test&tab=open&severity=high&source=sensor&from=2025-01-01&to=2025-12-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.q', 'test')
                ->where('filters.tab', 'open')
                ->where('filters.severity', 'high')
                ->where('filters.source', 'sensor')
                ->where('filters.from', '2025-01-01')
                ->where('filters.to', '2025-12-31')
            );
    }

    public function test_finance_user_can_view_incidents_index(): void
    {
        $finance = User::factory()->create([
            'role' => 'finance',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $finance->roles()->attach(Role::where('name', 'finance')->first());

        $this->actingAs($finance)
            ->get('/incidents')
            ->assertOk();
    }

    public function test_auditor_can_view_incidents_index(): void
    {
        $auditor = User::factory()->create([
            'role' => 'auditor',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $auditor->roles()->attach(Role::where('name', 'auditor')->first());

        $this->actingAs($auditor)
            ->get('/incidents')
            ->assertOk();
    }
}
