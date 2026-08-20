<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\HsInvestigationService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\NotificationService;
use App\Services\Tasks\Providers\ControlRoomAlertProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class IncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected User $coordinator;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

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

        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->client->supportWorkers()->attach($this->staff->id);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->staff->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helper: create a mock for NotificationService
    // ──────────────────────────────────────────────────────────────

    protected function mockNotificationService(): MockInterface
    {
        $mock = \Mockery::mock(NotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(NotificationService::class, $mock);

        return $mock;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reportPayload(array $overrides = []): array
    {
        return array_replace([
            'intent' => 'draft',
            'report_request_uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'type' => 'fall',
            'severity' => 'low',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'Client slipped beside the dining table.',
            'immediate_action_taken' => 'Resident checked and the immediate area made safe.',
        ], $overrides);
    }

    private function assignCoordinatorToPrimarySite(): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
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

    public function test_incident_reporting_role_permissions_are_narrow_and_explicit(): void
    {
        $healthSafety = Role::query()->where('name', 'health_safety_officer')->firstOrFail();
        $healthSafetyIncidentKeys = $healthSafety->permissions()
            ->where('key', 'like', 'incidents.%')
            ->pluck('key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['incidents.create'], $healthSafetyIncidentKeys);

        $coordinator = Role::query()->where('name', 'coordinator')->firstOrFail();
        $this->assertTrue($coordinator->permissions()->where('key', 'controlRoom.alerts.create')->exists());

        $supportWorker = Role::query()->where('name', 'support_worker')->firstOrFail();
        $this->assertFalse($supportWorker->permissions()->where('key', 'controlRoom.viewAny')->exists());
        $this->assertTrue($supportWorker->permissions()->where('key', 'controlRoom.alerts.view')->exists());
        $this->assertTrue($supportWorker->permissions()->where('key', 'incidents.viewAssigned')->exists());
        $this->assertTrue($supportWorker->permissions()->where('key', 'incidents.create')->exists());
        $this->assertTrue($supportWorker->permissions()->where('key', 'incidents.submit')->exists());
        $this->assertTrue(app(ControlRoomAlertProvider::class)->canView($this->staff));
    }

    public function test_health_safety_officer_can_use_the_visible_canonical_incident_action(): void
    {
        $this->mockNotificationService();
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());

        $this->actingAs($officer)
            ->post('/incidents', $this->reportPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('client_incidents', [
            'client_id' => $this->client->id,
            'reported_by' => $officer->id,
            'status' => 'draft',
        ]);
    }

    public function test_health_safety_incident_action_scopes_clients_to_accessible_sites_and_rejects_a_remote_site_client(): void
    {
        $remoteSite = Site::factory()->create();
        $remoteClient = Client::factory()->create([
            'site_id' => $remoteSite->id,
        ]);
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officerRole = Role::where('name', 'health_safety_officer')->firstOrFail();
        $officer->roles()->attach($officerRole);
        $officerRole->permissions()->detach(
            $officerRole->permissions()->where('key', 'healthSafety.viewAllSites')->firstOrFail(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $officer->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($officer)
            ->get(route('health-safety.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $this->client->id));

        $this->actingAs($officer)
            ->post('/incidents', $this->reportPayload(['client_id' => $remoteClient->id]))
            ->assertForbidden();

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_task5_hardening_health_safety_create_permission_does_not_widen_incident_list_or_detail_visibility(): void
    {
        $localIncident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
        ]);
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());

        $this->actingAs($officer)->get('/incidents')->assertForbidden();
        $this->actingAs($officer)->get("/incidents/{$localIncident->id}")->assertForbidden();
        $this->actingAs($officer)->get("/incidents/{$foreignIncident->id}")->assertForbidden();
    }

    public function test_report_picker_and_client_filter_scope_a_view_any_coordinator_by_site(): void
    {
        $this->client->update([
            'first_name' => 'Aroha',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        Client::factory()->create([
            'first_name' => 'Bella',
            'site_id' => $otherSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reportClients', 1)
                ->where('reportClients.0.id', $this->client->id)
                ->has('clients', 1)
                ->where('clients.0.id', $this->client->id)
                ->has('sites', 1)
                ->where('sites.0.id', $this->site->id));
    }

    public function test_task5_hardening_store_rejects_site_scoped_coordinator_foreign_site_crafted_post(): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignSiteClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post('/incidents', $this->reportPayload([
                'client_id' => $foreignSiteClient->id,
                'site_id' => $foreignSite->id,
            ]))
            ->assertForbidden();

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_report_picker_allows_an_application_wide_manager_to_see_clients_at_all_sites(): void
    {
        $this->client->update([
            'first_name' => 'Aroha',
        ]);
        $otherSite = Site::factory()->create();
        $otherSiteClient = Client::factory()->create([
            'first_name' => 'Bella',
            'site_id' => $otherSite->id,
        ]);
        $thirdClient = Client::factory()->create([
            'first_name' => 'Charlie',
            'site_id' => $otherSite->id,
        ]);
        $manager = User::factory()->create([
            'role' => 'provider_manager',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $manager->roles()->attach(Role::where('name', 'provider_manager')->firstOrFail());

        $this->actingAs($manager)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reportClients', 3)
                ->where('reportClients.0.id', $this->client->id)
                ->where('reportClients.1.id', $otherSiteClient->id)
                ->where('reportClients.2.id', $thirdClient->id));
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
                ->where('detail.close_gate.allowed', false)
                ->where('detail.close_gate.requirements.0.key', 'incident_review')
                ->where('detail.close_gate.requirements.1.key', 'incident_followups')
                ->where('detail.close_gate.requirements.1.complete', false)
                ->where(
                    'detail.close_gate.requirements.1.href',
                    "/incidents/{$incident->id}",
                )
                ->has('detail.journey_state')
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

    public function test_detail_does_not_link_health_safety_gates_without_health_safety_access(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->staff)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.id', $incident->id)
                ->where('detail.close_gate.requirements.2.href', null)
                ->where('detail.close_gate.requirements.3.href', null)
            );
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
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
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
            ->get('/incidents/create?client_id='.$this->client->id)
            ->assertRedirect(route('incidents.index', ['report' => 'incident', 'report_client_id' => $this->client->id]));
    }

    public function test_create_client_prefill_withholds_a_foreign_site_direct_object(): void
    {
        $this->assignCoordinatorToPrimarySite();
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);

        $this->actingAs($this->coordinator)
            ->get('/incidents/create?client_id='.$foreignClient->id)
            ->assertRedirect(route('incidents.index', ['report' => 'incident']));
    }

    public function test_create_shift_prefill_scopes_view_any_users_to_their_accessible_sites(): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $accessibleShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
        ]);
        $foreignSite = Site::factory()->create();
        $foreignSiteClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);
        $foreignSiteShift = Shift::factory()->create([
            'client_id' => $foreignSiteClient->id,
            'site_id' => $foreignSite->id,
            'user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get('/incidents/create?shift_id='.$accessibleShift->id)
            ->assertRedirect(route('incidents.index', [
                'report' => 'incident',
                'report_shift_id' => $accessibleShift->id,
                'report_client_id' => $this->client->id,
            ]));

        $this->actingAs($this->coordinator)
            ->get('/incidents/create?shift_id='.$foreignSiteShift->id)
            ->assertRedirect(route('incidents.index', ['report' => 'incident']));
    }

    public function test_create_shift_prefill_does_not_expose_remote_site_context_to_a_site_scoped_health_safety_officer(): void
    {
        $remoteSite = Site::factory()->create();
        $remoteClient = Client::factory()->create([
            'site_id' => $remoteSite->id,
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $remoteClient->id,
            'site_id' => $remoteSite->id,
            'user_id' => $this->staff->id,
        ]);
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officerRole = Role::where('name', 'health_safety_officer')->firstOrFail();
        $officer->roles()->attach($officerRole);
        $officerRole->permissions()->detach(
            $officerRole->permissions()->where('key', 'healthSafety.viewAllSites')->firstOrFail(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $officer->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($officer)
            ->get('/incidents/create?shift_id='.$shift->id)
            ->assertRedirect(route('incidents.index', ['report' => 'incident']));
    }

    public function test_create_shift_prefill_is_nondisclosing_when_missing_and_allows_an_application_wide_remote_site(): void
    {
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());
        $allSites = Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail();
        $officer->permissionOverrides()->syncWithoutDetaching([
            $allSites->id => ['allowed' => true],
        ]);
        $officer->refresh();
        HrEmployeeProfile::factory()->create([
            'user_id' => $officer->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $foreignShift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'user_id' => $officer->id,
        ]);
        $nondisclosingRedirect = route('incidents.index', ['report' => 'incident']);

        $this->actingAs($officer)
            ->get('/incidents/create?shift_id=999999999')
            ->assertRedirect($nondisclosingRedirect);

        $this->actingAs($officer)
            ->get('/incidents/create?shift_id='.$foreignShift->id)
            ->assertRedirect(route('incidents.index', [
                'report' => 'incident',
                'report_shift_id' => $foreignShift->id,
                'report_client_id' => $foreignClient->id,
            ]));
    }

    public function test_create_resume_draft_redirects_to_detail(): void
    {
        $incident = ClientIncident::factory()->create(['status' => 'draft']);

        $this->actingAs($this->admin)
            ->get('/incidents/create?incident='.$incident->id)
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
                'intent' => 'draft',
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'type' => 'fall',
                'severity' => 'medium',
                'occurred_at' => now()->format('Y-m-d H:i:s'),
                'description' => 'Client fell in the living room.',
                'requires_followup' => true,
                'immediate_action_taken' => 'Applied ice pack',
                'witnesses' => 'Jane Doe',
            ]);

        $response->assertRedirect();
        $incident = ClientIncident::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('client_incidents', [
            'client_id' => $this->client->id,
            'reported_by' => $this->staff->id,
            'site_id' => $this->site->id,
            'type' => 'fall',
            'severity' => 'medium',
            'status' => 'draft',
            'title' => 'fall incident',
            'description' => 'Client fell in the living room.',
            'requires_followup' => true,
            'immediate_action_taken' => 'Applied ice pack',
            'witnesses' => 'Jane Doe',
        ]);
        $this->assertNull($incident->submitted_at);
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
        $response->assertSessionHas('incident_report_result', function (array $result) use ($incident): bool {
            $this->assertSame('draft', $result['result'] ?? null);
            $this->assertSame($incident->reference_number, $result['incident_reference'] ?? null);
            $this->assertArrayNotHasKey('hs_reference', $result);
            $this->assertArrayNotHasKey('handover_state', $result);
            $this->assertArrayNotHasKey('incident_id', $result);
            $this->assertArrayNotHasKey('created_incident_id', $result);

            return true;
        });
    }

    public function test_store_rejects_invalid_intent_without_creating_a_record(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload(['intent' => 'save']))
            ->assertSessionHasErrors(['intent']);

        $this->assertSame(0, ClientIncident::query()->count());
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_store_submit_creates_hs_synchronously_and_returns_official_references(): void
    {
        $this->mockNotificationService();

        $response = $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload(['intent' => 'submit']));

        $response->assertRedirect();
        $incident = ClientIncident::query()->sole();
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->sole();

        $this->assertSame('submitted', $incident->status);
        $this->assertNotNull($incident->submitted_at);
        $this->assertSame($this->site->id, $incident->site_id);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $hsEvent->handover_status);
        $this->assertSame(0, ControlRoomAlert::query()->count(), 'Low incidents must not create an automatic alert.');
        $response->assertSessionHas('incident_report_result', function (array $result) use ($incident, $hsEvent): bool {
            $this->assertSame('submitted', $result['result'] ?? null);
            $this->assertSame($incident->reference_number, $result['incident_reference'] ?? null);
            $this->assertSame($hsEvent->reference_number, $result['hs_reference'] ?? null);
            $this->assertSame('awaiting_hs_acceptance', $result['handover_state'] ?? null);
            $this->assertArrayNotHasKey('incident_id', $result);
            $this->assertArrayNotHasKey('hs_event_id', $result);

            return true;
        });
    }

    public function test_store_high_submit_creates_one_canonical_alert(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'severity' => 'high',
            ]))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $hsEvent = HsEvent::query()->sole();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
    }

    public function test_store_high_submit_requires_truthful_immediate_action(): void
    {
        $payload = $this->reportPayload([
            'intent' => 'submit',
            'severity' => 'high',
            'immediate_action_taken' => null,
        ]);

        $this->actingAs($this->staff)
            ->post('/incidents', $payload)
            ->assertSessionHasErrors(['immediate_action_taken']);

        $this->assertSame(0, ClientIncident::query()->count());
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_task5_hardening_direct_submit_response_loss_retry_reuses_one_uuid_backed_journey(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();
        $payload = $this->reportPayload([
            'intent' => 'submit',
            'report_request_uuid' => $requestUuid,
            'severity' => 'high',
        ]);

        $first = $this->actingAs($this->staff)->post('/incidents', $payload);
        $retry = $this->actingAs($this->staff)->post('/incidents', $payload);

        $first->assertRedirect();
        $retry->assertRedirect();
        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame(1, HsEvent::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->count());

        $incident = ClientIncident::query()->sole();
        $this->assertSame($requestUuid, $incident->report_request_uuid);
        $first->assertSessionHas('incident_report_result', fn (array $result): bool => ($result['incident_reference'] ?? null) === $incident->reference_number);
        $retry->assertSessionHas('incident_report_result', fn (array $result): bool => ($result['incident_reference'] ?? null) === $incident->reference_number);
    }

    public function test_task5_review_fix_uuid_draft_submitted_by_legacy_id_preserves_uuid_for_retry_without_duplicate_journey(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();
        $draftPayload = $this->reportPayload([
            'intent' => 'draft',
            'report_request_uuid' => $requestUuid,
            'severity' => 'high',
        ]);

        $this->actingAs($this->staff)->post('/incidents', $draftPayload)->assertRedirect();
        $draft = ClientIncident::query()->sole();
        $legacySubmitPayload = array_replace($draftPayload, [
            'intent' => 'submit',
            'incident_id' => $draft->id,
        ]);
        unset($legacySubmitPayload['report_request_uuid']);

        $legacySubmit = $this->actingAs($this->staff)->post('/incidents', $legacySubmitPayload);
        $legacySubmit->assertRedirect();
        $legacyResult = session('incident_report_result');

        $uuidRetry = $this->actingAs($this->staff)->post('/incidents', array_replace($draftPayload, [
            'intent' => 'submit',
        ]));
        $uuidRetry->assertRedirect();
        $retryResult = session('incident_report_result');

        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame(1, HsEvent::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->count());

        $incident = ClientIncident::query()->sole();
        $hsEvent = HsEvent::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $this->assertSame($requestUuid, $incident->report_request_uuid);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($incident->reference_number, $legacyResult['incident_reference'] ?? null);
        $this->assertSame($incident->reference_number, $retryResult['incident_reference'] ?? null);
        $this->assertSame($hsEvent->reference_number, $legacyResult['hs_reference'] ?? null);
        $this->assertSame($hsEvent->reference_number, $retryResult['hs_reference'] ?? null);
    }

    public function test_task5_hardening_shift_linked_draft_to_submit_reuses_the_same_report_uuid(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
        ]);
        $draftPayload = $this->reportPayload([
            'intent' => 'draft',
            'report_request_uuid' => $requestUuid,
            'shift_id' => $shift->id,
            'severity' => 'high',
        ]);

        $this->actingAs($this->staff)->post('/incidents', $draftPayload)->assertRedirect();
        $this->actingAs($this->staff)->post('/incidents', array_replace($draftPayload, [
            'intent' => 'submit',
        ]))->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $this->assertSame($requestUuid, $incident->report_request_uuid);
        $this->assertSame($shift->id, $incident->shift_id);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame(1, HsEvent::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_task5_hardening_report_uuid_collision_rejects_actor_and_incident_context_changes(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'report_request_uuid' => $requestUuid,
            ]))
            ->assertRedirect();

        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherClient->supportWorkers()->attach($this->staff->id);

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => $requestUuid,
                'client_id' => $otherClient->id,
                'site_id' => $otherSite->id,
            ]))
            ->assertForbidden();

        $otherWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $otherWorker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        $this->client->supportWorkers()->attach($otherWorker->id);

        $this->actingAs($otherWorker)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => $requestUuid,
            ]))
            ->assertForbidden();

        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame('draft', ClientIncident::query()->sole()->status);
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_task5_hardening_rejects_ambiguous_uuid_and_legacy_incident_identity_without_duplication(): void
    {
        $this->mockNotificationService();
        $draftRequestUuid = (string) Str::uuid();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'report_request_uuid' => $draftRequestUuid,
            ]))
            ->assertRedirect();

        $draft = ClientIncident::query()->sole();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => (string) Str::uuid(),
                'incident_id' => $draft->id,
            ]))
            ->assertSessionHasErrors(['incident_id']);

        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_task5_hardening_critical_hs_report_preserves_incident_time_harm_worksafe_and_critical_journey_provenance(): void
    {
        $this->mockNotificationService();
        $occurredAt = now()->subHours(5)->startOfMinute();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'severity' => 'high',
                'reported_severity' => 'critical',
                'harm_or_injury' => 'hospitalisation',
                'consequence' => 'Serious head injury requiring hospital care.',
                'is_notifiable' => true,
                'site_preserved' => true,
                'worksafe_notification_status' => 'notified',
                'worksafe_reference' => 'WS-2026-7788',
            ]))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $hsEvent = HsEvent::query()->sole();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertTrue($incident->occurred_at->equalTo($occurredAt));
        $this->assertSame('high', $incident->severity);
        $this->assertSame('critical', data_get($incident->metadata, 'journey.original_alert_severity'));
        $this->assertSame('hospital', $incident->medical_treatment_type);
        $this->assertSame('notifiable', $incident->injury_classification);
        $this->assertSame('Serious head injury requiring hospital care.', $incident->potential_consequence);
        $this->assertTrue((bool) $incident->is_notifiable);
        $this->assertTrue((bool) $incident->site_preserved);
        $this->assertSame('notified', $incident->worksafe_notification_status);
        $this->assertSame('WS-2026-7788', $incident->worksafe_reference);
        $this->assertNotNull($incident->worksafe_notified_at);
        $this->assertSame(HsEvent::SEVERITY_CRITICAL, $hsEvent->severity);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $hsEvent->worksafe_status);
        $this->assertSame(HsEvent::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_task5_hardening_critical_provenance_forces_the_supported_incident_severity_enum(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'severity' => 'low',
                'reported_severity' => 'critical',
            ]))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $this->assertSame('high', $incident->severity);
        $this->assertSame('critical', data_get($incident->metadata, 'journey.original_alert_severity'));
        $this->assertSame(HsEvent::SEVERITY_CRITICAL, HsEvent::query()->sole()->severity);
        $this->assertSame(HsEvent::SEVERITY_CRITICAL, ControlRoomAlert::query()->sole()->severity);
    }

    public function test_task5_hardening_create_only_reporter_cannot_assign_followups_or_create_partial_records(): void
    {
        $officer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());

        $this->actingAs($officer)
            ->post('/incidents', $this->reportPayload([
                'followups' => [[
                    'notes' => 'Assign a manager follow-up.',
                    'assigned_to_user_id' => $this->coordinator->id,
                ]],
            ]))
            ->assertSessionHasErrors(['followups.0.assigned_to_user_id']);

        $this->assertSame(0, ClientIncident::query()->count());
        $this->assertSame(0, IncidentFollowup::query()->count());
    }

    public function test_task5_hardening_reporter_with_followup_manage_permission_can_assign_followups(): void
    {
        $this->mockNotificationService();
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post('/incidents', $this->reportPayload([
                'followups' => [[
                    'notes' => 'Complete the care-plan review.',
                    'assigned_to_user_id' => $this->staff->id,
                ]],
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('incident_followups', [
            'notes' => 'Complete the care-plan review.',
            'assigned_to_user_id' => $this->staff->id,
        ]);
    }

    public function test_store_draft_to_submit_and_retry_reuses_one_complete_journey(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'draft',
                'report_request_uuid' => $requestUuid,
                'severity' => 'high',
            ]))
            ->assertRedirect();

        $draft = ClientIncident::query()->sole();
        $submitPayload = $this->reportPayload([
            'intent' => 'submit',
            'report_request_uuid' => $requestUuid,
            'severity' => 'high',
        ]);

        $first = $this->actingAs($this->staff)->post('/incidents', $submitPayload);
        $retry = $this->actingAs($this->staff)->post('/incidents', $submitPayload);

        $first->assertRedirect();
        $retry->assertRedirect();
        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame(1, HsEvent::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->count());

        $incident = $draft->fresh();
        $this->assertSame('submitted', $incident->status);
        $this->assertNotNull($incident->submitted_at);
        $this->assertNotNull($incident->hs_event_id);
        $this->assertNotNull($incident->control_room_alert_id);
    }

    public function test_store_rolls_back_incident_when_synchronous_journey_fails(): void
    {
        $journeys = \Mockery::mock(IncidentJourneyService::class);
        $journeys->shouldReceive('ensureForSubmittedIncident')
            ->once()
            ->andThrow(new \RuntimeException('Injected journey failure'));
        $this->app->instance(IncidentJourneyService::class, $journeys);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->staff)
                ->post('/incidents', $this->reportPayload(['intent' => 'submit']));
            $this->fail('The injected journey failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected journey failure', $exception->getMessage());
        }

        $this->assertSame(0, ClientIncident::query()->count());
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_store_persists_validated_shift_site_snapshot(): void
    {
        $this->mockNotificationService();
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'draft',
                'shift_id' => $shift->id,
            ]))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $this->assertSame($shift->id, $incident->shift_id);
        $this->assertSame($this->site->id, $incident->site_id);
    }

    public function test_task5_hardening_store_returns_forbidden_for_inaccessible_shift_and_site_context(): void
    {
        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherClient->supportWorkers()->attach($this->staff->id);
        $otherShift = Shift::factory()->create([
            'client_id' => $otherClient->id,
            'site_id' => $otherSite->id,
            'user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload(['shift_id' => $otherShift->id]))
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload(['site_id' => $otherSite->id]))
            ->assertForbidden();

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_store_rejects_unassigned_shift_without_disclosing_or_silently_dropping_it(): void
    {
        $otherWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $otherWorker->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson('/incidents', $this->reportPayload(['shift_id' => $shift->id]))
            ->assertForbidden();

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_task5_integration_store_rejects_draft_identity_changes_and_foreign_submitted_reuse(): void
    {
        $this->mockNotificationService();
        $requestUuid = (string) Str::uuid();
        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'report_request_uuid' => $requestUuid,
            ]))
            ->assertRedirect();
        $draft = ClientIncident::query()->sole();

        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherClient->supportWorkers()->attach($this->staff->id);

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => $requestUuid,
                'client_id' => $otherClient->id,
                'site_id' => $otherSite->id,
            ]))
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => $requestUuid,
            ]))
            ->assertRedirect();

        $otherWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $otherWorker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        $this->client->supportWorkers()->attach($otherWorker->id);

        $this->actingAs($otherWorker)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'submit',
                'report_request_uuid' => $requestUuid,
            ]))
            ->assertForbidden();

        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame(1, HsEvent::query()->count());
    }

    public function test_store_generates_title_from_type(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->staff)
            ->post('/incidents', [
                'intent' => 'draft',
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
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->coordinator)
            ->post('/incidents', [
                'intent' => 'draft',
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
                'intent' => 'draft',
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
                'intent' => 'draft',
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
            ->assertSessionHasErrors(['intent', 'client_id', 'type', 'severity']);
    }

    public function test_store_validates_client_exists(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', [
                'intent' => 'draft',
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
                'intent' => 'draft',
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
                'intent' => 'draft',
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
                'intent' => 'draft',
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
                'intent' => 'draft',
                'client_id' => $this->client->id,
                'template_id' => 99999,
                'type' => 'fall',
                'severity' => 'low',
            ])
            ->assertSessionHasErrors(['template_id']);
    }

    // ──────────────────────────────────────────────────────────────
    //  17. Drafts stay silent until submission
    // ──────────────────────────────────────────────────────────────

    public function test_task5_review_fix_store_high_severity_draft_emits_no_alert_notification_or_communication(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', $this->reportPayload([
                'intent' => 'draft',
                'severity' => 'high',
                'description' => 'Serious fall',
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('control_room_communications', 0);
    }

    public function test_task5_review_fix_abuse_draft_defers_safeguarding_journey_until_first_submit(): void
    {
        $requestUuid = (string) Str::uuid();
        $draftPayload = $this->reportPayload([
            'intent' => 'draft',
            'report_request_uuid' => $requestUuid,
            'type' => 'suspected abuse',
            'severity' => 'high',
        ]);

        $this->actingAs($this->staff)->post('/incidents', $draftPayload)->assertRedirect();

        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('safeguarding_concerns', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('control_room_communications', 0);

        $firstSubmit = $this->actingAs($this->staff)->post('/incidents', array_replace($draftPayload, [
            'intent' => 'submit',
        ]));
        $firstSubmit->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $concern = SafeguardingConcern::query()
            ->where('related_incident_id', $incident->id)
            ->where('concern_type', 'incident_escalation')
            ->sole();
        $incidentHsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->sole();
        $canonicalAlert = ControlRoomAlert::query()->sole();
        $this->assertSame($incident->id, $concern->related_incident_id);
        $this->assertSame($incidentHsEvent->id, $incident->hs_event_id);
        $this->assertSame($canonicalAlert->id, $incident->control_room_alert_id);
        $this->assertSame(0, HsEvent::query()
            ->where('source_type', SafeguardingConcern::class)
            ->where('source_id', $concern->id)
            ->count());
        $this->assertSame(0, ControlRoomAlert::query()
            ->where('source', 'safeguarding')
            ->where('context->concern_id', $concern->id)
            ->count());

        $this->actingAs($this->staff)->post('/incidents', array_replace($draftPayload, [
            'intent' => 'submit',
        ]))->assertRedirect();

        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('safeguarding_concerns', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame($requestUuid, $incident->fresh()->report_request_uuid);
        $this->assertSame($incidentHsEvent->id, $incident->fresh()->hs_event_id);
        $this->assertSame($canonicalAlert->id, $incident->fresh()->control_room_alert_id);

        $concern->update(['severity' => 'critical']);

        $this->assertDatabaseHas('notifiable_incidents', [
            'incident_type' => 'safeguarding',
            'related_incident_id' => $concern->id,
            'severity' => 'critical',
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('notifiable_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);

        $concern->update(['description' => 'Critical safeguarding follow-up recorded.']);

        $this->assertDatabaseCount('notifiable_incidents', 1);
        $this->assertSame($incidentHsEvent->id, $incident->fresh()->hs_event_id);
        $this->assertSame($canonicalAlert->id, $incident->fresh()->control_room_alert_id);
    }

    public function test_task5_review_fix_standalone_safeguarding_concern_keeps_its_own_observer_journey(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'subject_type' => Client::class,
            'subject_id' => $this->client->id,
            'subject_name' => $this->client->first_name.' '.$this->client->last_name,
            'concern_type' => 'abuse',
            'severity' => 'high',
            'site_id' => $this->site->id,
            'reported_by_user_id' => $this->staff->id,
            'reported_by_name' => $this->staff->name,
            'related_incident_id' => null,
        ]);

        $hsEvent = HsEvent::query()
            ->where('source_type', SafeguardingConcern::class)
            ->where('source_id', $concern->id)
            ->sole();
        $alert = ControlRoomAlert::query()
            ->where('source', 'safeguarding')
            ->where('context->concern_id', $concern->id)
            ->sole();

        $this->assertSame($hsEvent->id, $concern->linkedHsEvent()?->id);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
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
                'intent' => 'draft',
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

        $response = $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();

        $incident->refresh();
        $this->assertEquals('submitted', $incident->status);
        $this->assertNotNull($incident->submitted_at);
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->sole();
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $response->assertSessionHas('incident_report_result', fn (array $result): bool => ($result['incident_reference'] ?? null) === $incident->reference_number
            && ($result['hs_reference'] ?? null) === $hsEvent->reference_number
            && ($result['handover_state'] ?? null) === 'awaiting_hs_acceptance'
        );
    }

    public function test_submit_rolls_back_draft_state_when_synchronous_journey_fails(): void
    {
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->staff->id,
        ]);
        $journeys = \Mockery::mock(IncidentJourneyService::class);
        $journeys->shouldReceive('ensureForSubmittedIncident')
            ->once()
            ->andThrow(new \RuntimeException('Injected submit journey failure'));
        $this->app->instance(IncidentJourneyService::class, $journeys);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->staff)->post("/incidents/{$incident->id}/submit");
            $this->fail('The injected submit journey failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected submit journey failure', $exception->getMessage());
        }

        $incident->refresh();
        $this->assertSame('draft', $incident->status);
        $this->assertNull($incident->submitted_at);
        $this->assertNull($incident->hs_event_id);
        $this->assertNull($incident->control_room_alert_id);
        $this->assertSame(0, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
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
            'immediate_action_taken' => 'Resident checked and immediate hazards controlled.',
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

    public function test_submit_retry_is_idempotent_for_the_original_reporter(): void
    {
        $this->mockNotificationService();
        $incident = ClientIncident::factory()->create([
            'status' => 'draft',
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();
        $this->actingAs($this->staff)
            ->post("/incidents/{$incident->id}/submit")
            ->assertRedirect();

        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertSame(1, HsEvent::query()->count());
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_task5_hardening_submit_and_retry_reject_inaccessible_site_before_journey_mutation(): void
    {
        $this->mockNotificationService();
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignClient->supportWorkers()->attach($this->staff->id);
        $draft = ClientIncident::factory()->create([
            'status' => 'draft',
            'submitted_at' => null,
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'reported_by' => $this->staff->id,
        ]);
        $submitted = ClientIncident::factory()->submitted()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'reported_by' => $this->staff->id,
            'hs_event_id' => null,
            'control_room_alert_id' => null,
        ]);
        $hsEventCount = HsEvent::query()->count();
        $alertCount = ControlRoomAlert::query()->count();

        $this->actingAs($this->staff)
            ->post("/incidents/{$draft->id}/submit")
            ->assertForbidden();
        $this->actingAs($this->staff)
            ->post("/incidents/{$submitted->id}/submit")
            ->assertForbidden();

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame($hsEventCount, HsEvent::query()->count());
        $this->assertSame($alertCount, ControlRoomAlert::query()->count());
    }

    // ──────────────────────────────────────────────────────────────
    //  REVIEW
    // ──────────────────────────────────────────────────────────────

    public function test_review_requires_authentication(): void
    {
        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->post("/incidents/{$incident->id}/review")->assertRedirect('/login');
    }

    public function test_review_changes_status_to_reviewed(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

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

    public function test_review_rolls_back_incident_and_journey_mutations_when_synchronous_verification_fails(): void
    {
        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->staff->id,
            'review_notes' => 'Original review note.',
        ]);
        $hsEvent = $incident->fresh()->hsEvent()->firstOrFail();
        $originalHsStatus = $hsEvent->status;
        $originalHsSummary = $hsEvent->closure_summary;

        $journeys = \Mockery::mock(IncidentJourneyService::class);
        $journeys->shouldReceive('ensureForSubmittedIncident')
            ->once()
            ->andReturnUsing(function (ClientIncident $lockedIncident) use ($hsEvent): never {
                $lockedIncident->forceFill(['hs_event_id' => null])->saveQuietly();
                $hsEvent->newQuery()->whereKey($hsEvent->id)->update([
                    'status' => HsEvent::STATUS_CLOSED,
                    'closure_summary' => 'Injected partial review mutation.',
                ]);

                throw new \RuntimeException('Injected review journey failure');
            });
        $this->app->instance(IncidentJourneyService::class, $journeys);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->coordinator)
                ->post("/incidents/{$incident->id}/review", [
                    'review_notes' => 'This must roll back.',
                ]);
            $this->fail('The injected review journey failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected review journey failure', $exception->getMessage());
        }

        $incident->refresh();
        $this->assertSame('submitted', $incident->status);
        $this->assertNull($incident->reviewed_by);
        $this->assertNull($incident->reviewed_at);
        $this->assertSame('Original review note.', $incident->review_notes);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame($originalHsStatus, $hsEvent->fresh()->status);
        $this->assertSame($originalHsSummary, $hsEvent->fresh()->closure_summary);
    }

    public function test_review_sends_notification(): void
    {
        $mock = $this->mockNotificationService();
        $mock->shouldReceive('notifyCrud')->once()->andReturnNull();

        $incident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

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
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

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

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

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

    public function test_close_rolls_back_incident_and_journey_mutations_when_synchronous_verification_fails(): void
    {
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);
        $incident->refresh();
        $hsEvent = $incident->hsEvent()->firstOrFail();
        $originalHsSummary = $hsEvent->closure_summary;
        $closeGate = app(IncidentJourneyService::class)->closeGate($incident);
        $this->assertTrue($closeGate->allowed);

        $journeys = \Mockery::mock(IncidentJourneyService::class);
        $journeys->shouldReceive('closeGate')
            ->once()
            ->andReturn($closeGate);
        $journeys->shouldReceive('ensureForSubmittedIncident')
            ->once()
            ->andReturnUsing(function (ClientIncident $lockedIncident) use ($hsEvent): never {
                $lockedIncident->forceFill(['hs_event_id' => null])->saveQuietly();
                $hsEvent->newQuery()->whereKey($hsEvent->id)->update([
                    'closure_summary' => 'Injected partial close mutation.',
                ]);

                throw new \RuntimeException('Injected close journey failure');
            });
        $this->app->instance(IncidentJourneyService::class, $journeys);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->coordinator)
                ->post("/incidents/{$incident->id}/close", [
                    'closed_outcome' => 'This must roll back.',
                    'closed_notes' => 'No partial closure may remain.',
                ]);
            $this->fail('The injected close journey failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected close journey failure', $exception->getMessage());
        }

        $incident->refresh();
        $this->assertSame('reviewed', $incident->status);
        $this->assertNull($incident->closed_by);
        $this->assertNull($incident->closed_at);
        $this->assertNull($incident->closed_outcome);
        $this->assertNull($incident->closed_notes);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);
        $this->assertSame($originalHsSummary, $hsEvent->fresh()->closure_summary);
    }

    public function test_closing_incident_does_not_silently_resolve_linked_control_room_alert(): void
    {
        $this->mockNotificationService();

        $alert = ControlRoomAlert::factory()->open()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [
                'closed_outcome' => 'Resolved',
            ])
            ->assertRedirect();

        $this->assertSame('closed', $incident->fresh()->status);
        // Incident review and operational response are independently truthful.
        // An operator must use the gated Control Room lifecycle to resolve it.
        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertNull($alert->resolved_at);
        $this->assertNull($alert->resolved_by_user_id);
        $this->assertNull($alert->resolution_code);
    }

    // ── Corrective actions (Option B: raised from the incident, governed in H&S) ──

    public function test_task5_failed_gate_raise_corrective_action_creates_hs_register_row(): void
    {
        $this->mockNotificationService();
        $this->actingAs($this->admin)
            ->post('/incidents', $this->reportPayload(['intent' => 'submit']))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->sole();
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/corrective-actions", [
                'title' => 'Install a grab rail in the bathroom',
                'priority' => 'high',
                'assigned_to_user_id' => $this->admin->id,
                'due_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertRedirect();

        // No copy on the incident — it lives in the H&S register, linked to the event.
        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $hsEvent->id,
            'title' => 'Install a grab rail in the bathroom',
            'priority' => 'high',
            'status' => 'open',
            'assigned_to_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('detail.corrective_action_owners', 1)
                ->where('detail.corrective_action_owners.0.id', $this->admin->id)
                ->where('detail.corrective_action_owners.0.name', $this->admin->name)
            );
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
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", [])
            ->assertSessionHasErrors(['closed_outcome']);
    }

    // ── High-severity close guardrail ↔ H&S investigation seam ──

    public function test_high_severity_close_blocked_without_completed_investigation(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'severity' => 'high',
            'immediate_action_taken' => 'Resident assessed and immediate hazards controlled.',
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", ['closed_outcome' => 'Attempted close'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('reviewed', $incident->fresh()->status);
    }

    public function test_completing_the_hs_investigation_and_closing_governance_unlocks_high_severity_close(): void
    {
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'severity' => 'high',
            'immediate_action_taken' => 'Resident assessed and immediate hazards controlled.',
            'submitted_at' => now()->subDay(),
        ]);
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->first();
        $this->assertNotNull($hsEvent, 'Expected the observer to record an HsEvent for the incident.');

        // Run the investigation to completion through the real service.
        $this->actingAs($this->admin);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $service = app(HsInvestigationService::class);
        $investigation = $service->create($hsEvent, [
            'methodology' => '5_whys',
            'lead_investigator_id' => $this->admin->id,
        ]);
        $service->start($investigation, $this->admin->id);
        $service->recordFindings($investigation, [
            'findings_summary' => 'Root cause established.',
            'recommendations' => [['description' => 'Refresh the support plan']],
        ]);
        $service->submitForReview($investigation, $this->admin);
        $reviewer = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $reviewer->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $reviewer->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $approver = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $approver->roles()->attach(Role::where('name', 'health_safety_officer')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $approver->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $this->actingAs($reviewer);
        $service->review($investigation, $reviewer);
        $this->actingAs($approver);
        $service->complete($investigation, $approver);

        // The lifecycle sync mirrors completion onto the incident column.
        $this->assertSame('completed', $incident->fresh()->investigation_status);
        $eventService = app(HsEventService::class);
        $eventService->acceptHandover(
            $hsEvent->fresh(),
            $approver,
            $approver,
            'Investigation completed and governance accepted for closure.',
        );
        $eventService->recordWorksafeDecision(
            $hsEvent->fresh(),
            false,
            'No WorkSafe notification is required for this incident fixture.',
            $approver,
        );
        $service->dispositionRecommendation(
            $investigation->fresh(),
            0,
            HsRecommendationDisposition::DISPOSITION_NO_ACTION,
            $approver,
            'The completed investigation does not require a separate corrective action.',
        );
        $alert = ControlRoomAlert::query()->findOrFail($hsEvent->control_room_alert_id);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->actingAs($this->admin);
        $alertLifecycle = app(ControlRoomAlertLifecycleService::class);
        $alert = $alertLifecycle->acknowledge(
            $alert,
            $this->admin,
            'Operational response acknowledged for the completed investigation.',
        );
        $alert = $alertLifecycle->startTriage(
            $alert,
            $this->admin,
            'Operational review confirmed the investigation outcome.',
        );
        $alertLifecycle->resolve(
            $alert,
            $this->admin,
            'Operational response resolved after the H&S investigation was accepted.',
            'controlled',
        );
        $this->actingAs($approver);
        app(HsEventClosureService::class)->closeEvent(
            $hsEvent->fresh(),
            'Governance closed through the canonical H&S lifecycle.',
            $approver,
        );

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", ['closed_outcome' => 'Investigated and resolved'])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame('closed', $incident->fresh()->status);
    }

    public function test_pre_sync_completed_hs_investigation_and_closed_governance_unlock_close(): void
    {
        // Simulates rows written before the status sync existed: the H&S
        // investigation is completed but the incident column was never mirrored.
        $this->mockNotificationService();

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'severity' => 'critical',
            'immediate_action_taken' => 'Resident assessed and immediate hazards controlled.',
        ]);
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->first();
        $this->assertNotNull($hsEvent);

        HsInvestigation::create([
            'hs_event_id' => $hsEvent->id,
            'reference_number' => HsInvestigation::generateReferenceNumber(),
            'investigation_type' => 'standard',
            'status' => HsInvestigation::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->assertNull($incident->fresh()->investigation_status);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", ['closed_outcome' => 'Historic record closed'])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame('closed', $incident->fresh()->status);
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

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

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

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

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

    public function test_task7_final_gap_sequential_followup_and_close_orderings_never_leave_closed_with_open_work(): void
    {
        $this->mockNotificationService();

        $followupFirst = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($followupFirst);
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$followupFirst->id}/followups", [
                'notes' => 'Created before the close attempt.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$followupFirst->id}/close", [
                'closed_outcome' => 'Must remain reviewed',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('reviewed', $followupFirst->fresh()->status);
        $this->assertSame(1, $followupFirst->followups()->whereNull('completed_at')->count());

        $closeFirst = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($closeFirst);
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$closeFirst->id}/close", [
                'closed_outcome' => 'Closed before any follow-up',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$closeFirst->id}/followups", [
                'notes' => 'Must not be created after closure.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'incident' => 'Closed incidents cannot receive new follow-ups. Reopen the incident before creating more work.',
            ]);

        $this->assertSame('closed', $closeFirst->fresh()->status);
        $this->assertSame(0, $closeFirst->followups()->count());
    }

    public function test_task7_final_gap_followup_store_and_close_lock_the_incident_before_dependent_work(): void
    {
        $this->mockNotificationService();
        $storeIncident = ClientIncident::factory()->submitted()->create([
            'client_id' => $this->client->id,
        ]);

        $storeQueries = $this->captureIncidentBoundaryQueries(fn () => $this->actingAs($this->coordinator)
            ->post("/incidents/{$storeIncident->id}/followups", [
                'notes' => 'Lock-order follow-up.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $storeSql = collect($storeQueries)->pluck('query')->map($this->normaliseIncidentBoundarySql(...))->values();
        $storeLock = $storeSql->search(fn (string $sql): bool => str_contains($sql, 'from client_incidents')
            && str_contains($sql, 'for update'));
        $followupInsert = $storeSql->search(fn (string $sql): bool => str_starts_with($sql, 'insert into incident_followups'));

        $this->assertNotFalse($storeLock, 'Follow-up creation must lock the parent incident.');
        $this->assertNotFalse($followupInsert);
        $this->assertLessThan($followupInsert, $storeLock);

        $closeIncident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($closeIncident);
        $closeQueries = $this->captureIncidentBoundaryQueries(fn () => $this->actingAs($this->coordinator)
            ->post("/incidents/{$closeIncident->id}/close", [
                'closed_outcome' => 'Lock-order close.',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error'));
        $closeSql = collect($closeQueries)->pluck('query')->map($this->normaliseIncidentBoundarySql(...))->values();
        $closeLock = $closeSql->search(fn (string $sql): bool => str_contains($sql, 'from client_incidents')
            && str_contains($sql, 'for update'));
        $followupCheck = $closeSql->search(fn (string $sql): bool => str_contains($sql, 'from incident_followups'));
        $incidentUpdate = $closeSql->search(fn (string $sql): bool => str_starts_with($sql, 'update client_incidents'));

        $this->assertNotFalse($closeLock, 'Closure must lock the parent incident.');
        $this->assertNotFalse($followupCheck);
        $this->assertNotFalse($incidentUpdate);
        $this->assertLessThan($followupCheck, $closeLock);
        $this->assertLessThan($incidentUpdate, $closeLock);
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
        $this->assignCoordinatorToPrimarySite();

        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
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

    public function test_reopen_preserves_terminal_health_safety_and_control_room_lifecycles(): void
    {
        $this->mockNotificationService();
        $this->assignCoordinatorToPrimarySite();
        [$incident, $hsEvent, $alert] = $this->closedCanonicalIncidentJourney();
        $itTicket = ItTicket::factory()->create([
            'requester_user_id' => $this->coordinator->id,
            'site_id' => $this->site->id,
            'status' => 'closed',
            'resolved_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
        ]);
        $itTicket->links()->create([
            'relationship' => 'source_alert',
            'linkable_type' => $alert->getMorphClass(),
            'linkable_id' => $alert->id,
            'created_by_user_id' => $this->coordinator->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New factual evidence requires incident review.',
            ])
            ->assertRedirect();

        $this->assertSame('reviewed', $incident->fresh()->status);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->fresh()->status);
        $this->assertSame('closed', $itTicket->fresh()->status);
        $this->assertNotNull($itTicket->fresh()->closed_at);
        $this->assertTrue($itTicket->links()->where('linkable_id', $alert->id)->exists());
        $this->assertSame('incident_reopened', data_get($alert->fresh()->context, 'journey_attention.type'));
        $this->assertTrue((bool) data_get($alert->fresh()->context, 'journey_attention.requires_operational_reopen'));
    }

    public function test_reopen_rolls_back_incident_and_journey_mutations_when_synchronous_verification_fails(): void
    {
        $this->assignCoordinatorToPrimarySite();
        [$incident, $hsEvent, $alert] = $this->closedCanonicalIncidentJourney();
        $originalAlertContext = $alert->context;
        $originalClosedAt = $incident->closed_at?->getTimestamp();

        $journeys = \Mockery::mock(IncidentJourneyService::class);
        $journeys->shouldReceive('attachAlertToIncident')
            ->once()
            ->andReturnUsing(function (
                ClientIncident $lockedIncident,
                ControlRoomAlert $lockedAlert,
            ) use ($hsEvent): never {
                $lockedIncident->forceFill(['hs_event_id' => null])->saveQuietly();
                $hsEvent->newQuery()->whereKey($hsEvent->id)->update([
                    'status' => HsEvent::STATUS_OPEN,
                    'closure_summary' => 'Injected partial reopen mutation.',
                ]);
                $lockedAlert->forceFill([
                    'status' => ControlRoomAlert::STATUS_OPEN,
                    'context' => array_replace((array) $lockedAlert->context, [
                        'injected_partial_reopen' => true,
                    ]),
                ])->saveQuietly();

                throw new \RuntimeException('Injected reopen journey failure');
            });
        $journeys->shouldNotReceive('ensureForSubmittedIncident');
        $this->app->instance(IncidentJourneyService::class, $journeys);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->coordinator)
                ->post("/incidents/{$incident->id}/reopen", [
                    'reopened_reason' => 'This must roll back.',
                ]);
            $this->fail('The injected reopen journey failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected reopen journey failure', $exception->getMessage());
        }

        $incident->refresh();
        $this->assertSame('closed', $incident->status);
        $this->assertSame($this->coordinator->id, $incident->closed_by);
        $this->assertSame($originalClosedAt, $incident->closed_at?->getTimestamp());
        $this->assertSame('Previously resolved', $incident->closed_outcome);
        $this->assertNull($incident->reopened_by);
        $this->assertNull($incident->reopened_at);
        $this->assertNull($incident->reopened_reason);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);
        $this->assertSame('Closed H&S journey fixture.', $hsEvent->fresh()->closure_summary);
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->fresh()->status);
        $this->assertSame($originalAlertContext, $alert->fresh()->context);
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
                'intent' => 'draft',
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
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

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
        $this->assignCoordinatorToPrimarySite();

        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
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
                'intent' => 'draft',
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

        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->markLinkedHealthSafetyGovernanceClosed($incident);

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
        $this->assignCoordinatorToPrimarySite();

        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'status' => 'closed',
        ]);

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
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
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

    private function markLinkedHealthSafetyGovernanceClosed(ClientIncident $incident): void
    {
        $incident->refresh();
        $event = $incident->hsEvent()->first()
            ?? HsEvent::query()
                ->where('source_type', ClientIncident::class)
                ->where('source_id', $incident->id)
                ->first();

        if ($event === null) {
            return;
        }

        $event->forceFill([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $this->coordinator->id,
            'closure_summary' => 'Governance closed for the incident closure fixture.',
        ])->saveQuietly();
    }

    /** @return array{0: ClientIncident, 1: HsEvent, 2: ControlRoomAlert} */
    private function closedCanonicalIncidentJourney(): array
    {
        $incident = ClientIncident::factory()->highSeverity()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->coordinator->id,
            'status' => 'closed',
            'submitted_at' => now()->subDays(3),
            'immediate_action_taken' => 'Resident assessed and immediate hazards controlled.',
            'reviewed_by' => $this->coordinator->id,
            'reviewed_at' => now()->subDays(2),
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Previously resolved',
        ])->fresh();
        $hsEvent = $incident->hsEvent()->firstOrFail();
        $alert = $incident->controlRoomAlert()->firstOrFail();

        $hsEvent->forceFill([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now()->subDay(),
            'closed_by' => $this->coordinator->id,
            'closure_summary' => 'Closed H&S journey fixture.',
        ])->saveQuietly();
        $alert->forceFill([
            'status' => ControlRoomAlert::STATUS_CLOSED,
            'resolved_at' => now()->subDays(2),
            'resolved_by_user_id' => $this->coordinator->id,
            'resolution_code' => 'resolved',
            'closed_at' => now()->subDay(),
            'closed_by_user_id' => $this->coordinator->id,
            'context' => array_replace((array) $alert->context, [
                'fixture_marker' => 'preserved',
            ]),
        ])->saveQuietly();

        return [$incident->fresh(), $hsEvent->fresh(), $alert->fresh()];
    }

    /** @return list<array{query: string, bindings: array<mixed>, time: float}> */
    private function captureIncidentBoundaryQueries(callable $action): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $action();

            return $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
    }

    private function normaliseIncidentBoundarySql(string $query): string
    {
        return strtolower(str_replace([chr(96), '"'], '', $query));
    }
}
