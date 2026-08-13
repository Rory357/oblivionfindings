<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\LocationHardware;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\Integration\IntegrationContextProvider;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class DismissedAlertScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create();
        $this->attachCurrentHrProfile($this->admin, $this->site, 'admin');
    }

    public function test_health_and_safety_dashboard_does_not_count_dismissed_lone_worker_alerts_as_active(): void
    {
        $this->makeAlert([
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->actingAs($this->admin)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/dashboard')
                ->where('kpis.active_alerts', 1));
    }

    public function test_lone_worker_register_treats_dismissed_alerts_as_terminal(): void
    {
        $open = $this->makeAlert([
            'source' => 'lone_worker',
            'alert_type' => LoneWorkerSignalService::canonicalAlertType(
                LoneWorkerSignalService::TYPE_EMERGENCY,
            ),
            'status' => ControlRoomAlert::STATUS_OPEN,
            'triggered_at' => now(),
        ]);
        $this->makeAlert([
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'triggered_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/health-safety/lone-workers?tab=alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/lone-workers/index')
                ->where('tabCounts.alerts', 1)
                ->where('hero.clusters.alerts.unresolved', 1)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', 'cr_'.$open->id));
    }

    public function test_integration_context_omits_dismissed_alerts_from_live_summary_and_worklist(): void
    {
        $site = Site::factory()->create();
        $this->makeAlert([
            'site_id' => $site->id,
            'source' => 'integration_nurse_call',
            'alert_type' => 'Open integration alert',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $site->id,
            'source' => 'integration_nurse_call',
            'alert_type' => 'Dismissed integration alert',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $context = app(IntegrationContextProvider::class)->getContext($site->id);

        $this->assertSame(1, $context['site_summary']['open_alerts']);
        $this->assertSame(1, $context['site_summary']['critical_alerts']);
        $this->assertCount(1, $context['open_alerts']);
        $this->assertSame('Open integration alert', $context['open_alerts'][0]['title']);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $context['open_alerts'][0]['status']);
    }

    public function test_fleet_dashboard_omits_dismissed_alerts_from_live_stats_and_worklist(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $open = $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->actingAs($this->admin)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->where('stats.active_alerts', 1)
                ->where('stats.critical_alerts', 1)
                ->where('stats.open_wandering_alerts', 1)
                ->has('recent_alerts', 1)
                ->where('recent_alerts.0.id', $open->id));
    }

    public function test_fleet_live_map_does_not_count_dismissed_alerts_as_open(): void
    {
        $site = Site::factory()->create();
        $this->makeAlert([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->actingAs($this->admin)
            ->get('/fleet-assets/map')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/map')
                ->where('open_alerts', 1));
    }

    public function test_resident_tracking_omits_dismissed_alerts_from_live_stats_and_wandering_worklist(): void
    {
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => 'active',
        ]);
        $this->createTrackingAssignment($client);
        $open = $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking?tab=wandering')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->where('stats.active_alerts', 1)
                ->where('wandering.stats.active_alerts', 1)
                ->where('wandering.alerts.meta.total', 1)
                ->has('wandering.alerts.data', 1)
                ->where('wandering.alerts.data.0.id', $open->id));
    }

    public function test_lone_worker_panic_acknowledgement_only_transitions_open_alerts(): void
    {
        $session = $this->makeLoneWorkerSession();
        $context = [
            'normalized_data' => [
                'lone_worker_session_id' => $session->id,
            ],
        ];

        $open = $this->makeAlert([
            'source' => 'lone_worker',
            'alert_type' => LoneWorkerSignalService::canonicalAlertType(
                LoneWorkerSignalService::TYPE_EMERGENCY,
            ),
            'status' => ControlRoomAlert::STATUS_OPEN,
            'context' => $context,
        ]);
        $openSla = $this->attachSla($open);
        $triageActor = User::factory()->create();
        $triageAcknowledgedAt = now()->subHour()->startOfSecond();
        $triaging = $this->makeAlert([
            'source' => 'lone_worker',
            'alert_type' => LoneWorkerSignalService::canonicalAlertType(
                LoneWorkerSignalService::TYPE_EMERGENCY,
            ),
            'status' => ControlRoomAlert::STATUS_TRIAGING,
            'context' => $context,
            'acknowledged_at' => $triageAcknowledgedAt,
            'acknowledged_by_user_id' => $triageActor->id,
        ]);
        $dismissed = $this->makeAlert([
            'source' => 'lone_worker',
            'alert_type' => LoneWorkerSignalService::canonicalAlertType(
                LoneWorkerSignalService::TYPE_EMERGENCY,
            ),
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'context' => $context,
        ]);

        $this->actingAs($this->admin)
            ->from('/health-safety/lone-workers')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/acknowledge-panic")
            ->assertRedirect('/health-safety/lone-workers');

        $this->assertCanonicalAcknowledgement($open, $openSla);
        $this->assertAlertWasNotReAcknowledged(
            $triaging,
            ControlRoomAlert::STATUS_TRIAGING,
            $triageActor,
            $triageAcknowledgedAt,
        );
        $this->assertAlertWasNotReAcknowledged($dismissed, ControlRoomAlert::STATUS_DISMISSED);
        $this->assertSame(1, $this->acknowledgementAuditCountFor([$open, $triaging, $dismissed]));
    }

    public function test_resident_tracking_panic_acknowledgement_only_transitions_open_alerts(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $this->createTrackingAssignment($client);
        $open = $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $openSla = $this->attachSla($open);
        $triageActor = User::factory()->create();
        $triageAcknowledgedAt = now()->subHour()->startOfSecond();
        $triaging = $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'status' => ControlRoomAlert::STATUS_TRIAGING,
            'acknowledged_at' => $triageAcknowledgedAt,
            'acknowledged_by_user_id' => $triageActor->id,
        ]);
        $dismissed = $this->makeAlert([
            'client_id' => $client->id,
            'source' => 'tracker',
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->actingAs($this->admin)
            ->from('/fleet-assets/resident-tracking')
            ->post("/fleet-assets/resident-tracking/{$client->id}/acknowledge-panic")
            ->assertRedirect('/fleet-assets/resident-tracking');

        $this->assertCanonicalAcknowledgement($open, $openSla);
        $this->assertAlertWasNotReAcknowledged(
            $triaging,
            ControlRoomAlert::STATUS_TRIAGING,
            $triageActor,
            $triageAcknowledgedAt,
        );
        $this->assertAlertWasNotReAcknowledged($dismissed, ControlRoomAlert::STATUS_DISMISSED);
        $this->assertSame(1, $this->acknowledgementAuditCountFor([$open, $triaging, $dismissed]));
    }

    public function test_health_and_safety_alert_kpi_is_limited_to_the_users_accessible_sites(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view']);
        $this->makeAlert([
            'site_id' => $visibleSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);

        $this->actingAs($officer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kpis.active_alerts', 1));

        $this->actingAs($officer)
            ->get('/health-safety?site='.$hiddenSite->id)
            ->assertForbidden();
    }

    public function test_lone_worker_live_register_and_alert_counts_are_limited_to_accessible_sites(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view']);
        $visibleAlert = $this->makeAlert([
            'site_id' => $visibleSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeLoneWorkerSession(['site_id' => $visibleSite->id]);
        $this->makeLoneWorkerSession(['site_id' => $hiddenSite->id]);

        $this->actingAs($officer)
            ->get('/health-safety/lone-workers?tab=alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.sessions', 1)
                ->where('tabCounts.alerts', 1)
                ->where('hero.clusters.live.active', 1)
                ->where('hero.clusters.alerts.awaiting_ack', 1)
                ->where('hero.clusters.alerts.unresolved', 1)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', 'cr_'.$visibleAlert->id));
    }

    public function test_lone_worker_panic_acknowledgement_rejects_a_session_from_another_site(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view', 'hazards.manage']);
        $hiddenSession = $this->makeLoneWorkerSession(['site_id' => $hiddenSite->id]);
        $hiddenAlert = $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'context' => [
                'normalized_data' => [
                    'lone_worker_session_id' => $hiddenSession->id,
                ],
            ],
        ]);

        $this->actingAs($officer)
            ->post("/health-safety/lone-workers/sessions/{$hiddenSession->id}/acknowledge-panic")
            ->assertForbidden();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $hiddenAlert->fresh()->status);
        $this->assertNull($hiddenAlert->fresh()->acknowledged_at);
    }

    public function test_fleet_dashboard_and_map_alert_summaries_are_limited_to_accessible_sites(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $viewer = $this->siteScopedUser($visibleSite, ['fleet.viewAny']);
        $visibleAlert = $this->makeAlert([
            'site_id' => $visibleSite->id,
            'source' => 'fleet',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'source' => 'fleet',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);

        $this->actingAs($viewer)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.active_alerts', 1)
                ->where('stats.critical_alerts', 1)
                ->has('recent_alerts', 1)
                ->where('recent_alerts.0.id', $visibleAlert->id));

        $this->actingAs($viewer)
            ->get('/fleet-assets/map')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('open_alerts', 1));
    }

    public function test_resident_tracking_alert_summaries_are_limited_to_clients_at_accessible_sites(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $viewer = $this->siteScopedUser($visibleSite, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'clients.viewAny',
        ]);
        $visibleClient = Client::factory()->create([
            'site_id' => $visibleSite->id,
            'status' => 'active',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $hiddenSite->id,
            'status' => 'active',
        ]);
        $this->createTrackingAssignment($visibleClient);
        $this->createTrackingAssignment($hiddenClient);
        $visibleAlert = $this->makeAlert([
            'site_id' => $visibleSite->id,
            'client_id' => $visibleClient->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'client_id' => $hiddenClient->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);

        $this->actingAs($viewer)
            ->get('/fleet-assets/resident-tracking?tab=wandering')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.active_alerts', 1)
                ->where('wandering.stats.active_alerts', 1)
                ->where('wandering.alerts.meta.total', 1)
                ->has('wandering.alerts.data', 1)
                ->where('wandering.alerts.data.0.id', $visibleAlert->id));
    }

    public function test_lone_worker_options_only_include_accessible_sites_staff_clients_and_shifts(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view']);
        $visibleWorker = $this->siteScopedUser($visibleSite, []);
        $hiddenWorker = $this->siteScopedUser($hiddenSite, []);
        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id, 'status' => 'active']);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'status' => 'active']);
        $visibleShift = $this->monitorableShift($visibleSite, $visibleClient, $visibleWorker);
        $hiddenShift = $this->monitorableShift($hiddenSite, $hiddenClient, $hiddenWorker);

        $this->actingAs($officer)
            ->get('/health-safety/lone-workers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$visibleSite->id])
                ->where('options.staff', fn ($staff) => collect($staff)->pluck('id')->contains($visibleWorker->id)
                    && ! collect($staff)->pluck('id')->contains($hiddenWorker->id))
                ->where('options.clients', fn ($clients) => collect($clients)->pluck('id')->all() === [$visibleClient->id])
                ->where('options.shifts', fn ($shifts) => collect($shifts)->pluck('id')->contains($visibleShift->id)
                    && ! collect($shifts)->pluck('id')->contains($hiddenShift->id)));
    }

    public function test_lone_worker_detail_does_not_expose_cross_site_sessions_or_alerts(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view']);
        $hiddenSession = $this->makeLoneWorkerSession(['site_id' => $hiddenSite->id]);
        $hiddenCanonicalAlert = $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'source' => 'lone_worker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $hiddenLegacyAlert = $hiddenSession->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);

        foreach ([
            'session='.$hiddenSession->id,
            'alert=cr_'.$hiddenCanonicalAlert->id,
            'alert=legacy_'.$hiddenLegacyAlert->id,
        ] as $query) {
            $this->actingAs($officer)
                ->get('/health-safety/lone-workers?'.$query)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('detail', null));
        }
    }

    public function test_lone_worker_coordinator_session_mutations_reject_another_site(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view', 'hazards.manage']);
        $this->actingAs($officer);

        $cases = [
            'update' => function (LoneWorkerSession $session) {
                return $this->patch("/health-safety/lone-workers/sessions/{$session->id}", [
                    'expected_end_at' => now()->addHours(3)->toDateTimeString(),
                ]);
            },
            'end' => fn (LoneWorkerSession $session) => $this->post("/health-safety/lone-workers/sessions/{$session->id}/end"),
            'emergency' => fn (LoneWorkerSession $session) => $this->post("/health-safety/lone-workers/sessions/{$session->id}/emergency", [
                'emergency_notes' => 'Attempted cross-site emergency mutation.',
            ]),
            'locate' => fn (LoneWorkerSession $session) => $this->post("/health-safety/lone-workers/sessions/{$session->id}/locate"),
        ];

        foreach ($cases as $name => $request) {
            $session = $this->makeLoneWorkerSession([
                'site_id' => $hiddenSite->id,
                'activity_description' => 'Unchanged '.$name,
            ]);

            $request($session)->assertForbidden();

            $session->refresh();
            $this->assertSame('active', $session->status);
            $this->assertSame('Unchanged '.$name, $session->activity_description);
            $this->assertNull($session->ended_at);
            $this->assertNull($session->emergency_triggered_at);
        }

        $completed = $this->makeLoneWorkerSession([
            'site_id' => $hiddenSite->id,
            'status' => 'completed',
            'ended_at' => now()->subMinute(),
        ]);
        $this->delete("/health-safety/lone-workers/sessions/{$completed->id}")->assertForbidden();
        $this->assertNotSoftDeleted($completed);
    }

    public function test_lone_worker_coordinator_alert_mutations_reject_another_site(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.view', 'hazards.manage']);
        $hiddenSession = $this->makeLoneWorkerSession(['site_id' => $hiddenSite->id]);
        $ackAlert = $hiddenSession->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);
        $resolveAlert = $hiddenSession->alerts()->create([
            'alert_type' => 'overdue',
            'triggered_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($officer)
            ->post("/health-safety/lone-workers/alerts/{$ackAlert->id}/acknowledge")
            ->assertNotFound();
        $this->actingAs($officer)
            ->post("/health-safety/lone-workers/alerts/{$resolveAlert->id}/resolve", [
                'resolution_notes' => 'Attempted cross-site resolution.',
            ])
            ->assertNotFound();

        $this->assertSame('active', $ackAlert->fresh()->status);
        $this->assertNull($ackAlert->fresh()->acknowledged_at);
        $this->assertSame('active', $resolveAlert->fresh()->status);
        $this->assertNull($resolveAlert->fresh()->resolved_at);
    }

    public function test_lone_worker_coordinator_check_in_rejects_another_site(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.manage']);
        $hiddenSession = $this->makeLoneWorkerSession(['site_id' => $hiddenSite->id]);

        $this->actingAs($officer)
            ->post("/health-safety/lone-workers/sessions/{$hiddenSession->id}/check-in", ['status' => 'ok'])
            ->assertForbidden();

        $this->assertSame(0, $hiddenSession->checkIns()->count());
    }

    public function test_lone_worker_own_worker_check_in_remains_ownership_authorized(): void
    {
        $worker = $this->siteScopedUser($this->site, []);
        $session = $this->makeLoneWorkerSession([
            'user_id' => $worker->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($worker)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", ['status' => 'ok'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $session->checkIns()->where('status', 'ok')->count());
    }

    public function test_start_lone_worker_session_rejects_inaccessible_site_client_and_shift(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $officer = $this->siteScopedUser($visibleSite, ['hazards.manage']);
        $worker = $this->siteScopedUser($visibleSite, []);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'status' => 'active']);
        $hiddenShift = $this->monitorableShift($hiddenSite, $hiddenClient, $worker);
        $base = [
            'user_id' => $worker->id,
            'expected_end_at' => now()->addHours(2)->toDateTimeString(),
        ];

        foreach ([
            array_merge($base, ['site_id' => $hiddenSite->id]),
            array_merge($base, ['site_id' => $visibleSite->id, 'client_id' => $hiddenClient->id]),
            array_merge($base, ['site_id' => $visibleSite->id, 'shift_id' => $hiddenShift->id]),
        ] as $payload) {
            $this->actingAs($officer)
                ->post('/health-safety/lone-workers/sessions', $payload)
                ->assertForbidden();
        }

        $this->assertSame(0, LoneWorkerSession::query()->count());
    }

    public function test_start_lone_worker_session_requires_selected_shift_worker_client_and_site_to_agree(): void
    {
        $site = Site::factory()->create();
        $officer = $this->siteScopedUser($site, ['hazards.manage']);
        $shiftWorker = $this->siteScopedUser($site, []);
        $otherWorker = $this->siteScopedUser($site, []);
        $shiftClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $otherClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = $this->monitorableShift($site, $shiftClient, $shiftWorker);
        $base = [
            'site_id' => $site->id,
            'shift_id' => $shift->id,
            'expected_end_at' => now()->addHours(2)->toDateTimeString(),
        ];

        $this->actingAs($officer)
            ->post('/health-safety/lone-workers/sessions', array_merge($base, [
                'user_id' => $shiftWorker->id,
                'client_id' => $otherClient->id,
            ]))
            ->assertSessionHasErrors('client_id');
        $this->actingAs($officer)
            ->post('/health-safety/lone-workers/sessions', array_merge($base, [
                'user_id' => $otherWorker->id,
                'client_id' => $shiftClient->id,
            ]))
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, LoneWorkerSession::query()->count());
    }

    public function test_resident_panic_outside_the_users_sites_requires_explicit_application_wide_permissions(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        $hiddenClient = Client::factory()->create([
            'site_id' => $hiddenSite->id,
            'status' => 'active',
        ]);
        $this->createTrackingAssignment($hiddenClient);
        $hiddenAlert = $this->makeAlert([
            'site_id' => $hiddenSite->id,
            'client_id' => $hiddenClient->id,
            'source' => 'tracker',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $siteViewer = $this->siteScopedUser($visibleSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
        ]);

        $this->actingAs($siteViewer)
            ->post("/fleet-assets/resident-tracking/{$hiddenClient->id}/acknowledge-panic")
            ->assertForbidden();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $hiddenAlert->fresh()->status);

        $globalManager = $this->siteScopedUser($visibleSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
            'securityDevices.devices.viewAllSites',
        ]);
        $this->actingAs($globalManager)
            ->post("/fleet-assets/resident-tracking/{$hiddenClient->id}/acknowledge-panic")
            ->assertRedirect();

        $hiddenAlert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_ACK, $hiddenAlert->status);
        $this->assertSame($globalManager->id, $hiddenAlert->acknowledged_by_user_id);
    }

    public function test_cross_site_alert_summaries_require_explicit_global_bypass_permissions(): void
    {
        [$visibleSite, $hiddenSite] = [Site::factory()->create(), Site::factory()->create()];
        foreach ([$visibleSite, $hiddenSite] as $site) {
            $this->makeAlert([
                'site_id' => $site->id,
                'source' => 'lone_worker',
                'status' => ControlRoomAlert::STATUS_OPEN,
            ]);
        }

        $globalHsOfficer = $this->siteScopedUser($visibleSite, [
            'hazards.view',
            'healthSafety.viewAllSites',
        ]);
        $this->actingAs($globalHsOfficer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kpis.active_alerts', 2));

        $globalFleetManager = $this->siteScopedUser($visibleSite, ['fleet.viewAny', 'fleet.manage']);
        $this->actingAs($globalFleetManager)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.active_alerts', 2));
        $this->actingAs($globalFleetManager)
            ->get('/fleet-assets/map')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('open_alerts', 2));
    }

    private function makeAlert(array $overrides = []): ControlRoomAlert
    {
        $siteId = $this->site->id;
        if (! array_key_exists('site_id', $overrides) && ! empty($overrides['client_id'])) {
            $siteId = (int) (Client::query()->whereKey($overrides['client_id'])->value('site_id') ?: $siteId);
        }

        return ControlRoomAlert::factory()->create(array_merge([
            'site_id' => $siteId,
            'source' => 'fleet',
            'alert_type' => 'Test alert',
            'severity' => 'high',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'triggered_at' => now(),
        ], $overrides));
    }

    private function makeLoneWorkerSession(array $overrides = []): LoneWorkerSession
    {
        $siteId = (int) ($overrides['site_id'] ?? $this->site->id);
        if (! array_key_exists('user_id', $overrides)) {
            $overrides['user_id'] = $this->siteScopedUser(
                Site::query()->findOrFail($siteId),
                [],
            )->id;
        }

        return LoneWorkerSession::create(array_merge([
            'site_id' => $siteId,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHours(2),
            'last_check_in_at' => now()->subMinutes(10),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'activity_description' => 'Home visit',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    private function monitorableShift(Site $site, Client $client, User $worker): Shift
    {
        return Shift::factory()->inProgress()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'is_lone_worker' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteScopedUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        $this->attachCurrentHrProfile($user, $site, 'support_worker');

        return $user;
    }

    private function attachCurrentHrProfile(User $user, Site $site, string $positionRole): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_role' => $positionRole,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    private function createTrackingConsent(Client $client): ClientConsent
    {
        $type = ConsentType::query()->firstOrCreate(
            ['name' => 'Fleet Tracking'],
            [
                'category' => 'operational',
                'description' => 'Fleet location tracking',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'allows_withdrawal' => true,
                'active' => true,
            ],
        );
        $version = ConsentTypeVersion::query()->firstOrCreate(
            ['consent_type_id' => $type->id, 'version' => 1],
            [
                'description' => 'Fleet tracking v1',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ],
        );

        return AuthoritativeConsentFixture::manualSelf($client, $type, $this->admin, [
            'status' => 'given',
            'given_at' => now(),
        ]);
    }

    private function createTrackingAssignment(Client $client): DeviceAssignment
    {
        $consent = $this->createTrackingConsent($client);
        $hardware = LocationHardware::query()->create([
            'site_id' => $client->site_id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Resident tracker hardware '.$client->id,
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        $device = Device::factory()->tracking()->create([
            'device_uid' => 'DISMISSED-ALERT-TRACKER-'.$client->id,
            'legacy_location_hardware_id' => $hardware->id,
        ]);

        return DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
            'consent_id' => $consent->id,
        ]);
    }

    private function attachSla(ControlRoomAlert $alert): AlertSla
    {
        $definition = SlaDefinition::create([
            'name' => 'Panic acknowledgement test SLA '.$alert->id,
            'code' => 'panic-ack-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);

        return AlertSla::createFromDefinition($alert, $definition);
    }

    private function assertCanonicalAcknowledgement(ControlRoomAlert $alert, AlertSla $sla): void
    {
        $alert->refresh();
        $sla->refresh();

        $this->assertSame(ControlRoomAlert::STATUS_ACK, $alert->status);
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($this->admin->id, $alert->acknowledged_by_user_id);
        $this->assertNotNull($sla->acknowledged_at);

        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.acknowledge')
            ->where('auditable_type', $alert->getMorphClass())
            ->where('auditable_id', $alert->id)
            ->firstOrFail();

        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($this->admin->id, $audit->meta['actor_id'] ?? null);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $audit->meta['from_status'] ?? null);
        $this->assertSame(ControlRoomAlert::STATUS_ACK, $audit->meta['to_status'] ?? null);
    }

    private function assertAlertWasNotReAcknowledged(
        ControlRoomAlert $alert,
        string $expectedStatus,
        ?User $originalActor = null,
        ?\DateTimeInterface $originalAcknowledgedAt = null,
    ): void {
        $alert->refresh();

        $this->assertSame($expectedStatus, $alert->status);
        $this->assertSame($originalActor?->id, $alert->acknowledged_by_user_id);

        if ($originalAcknowledgedAt === null) {
            $this->assertNull($alert->acknowledged_at);
        } else {
            $this->assertNotNull($alert->acknowledged_at);
            $this->assertTrue($alert->acknowledged_at->equalTo($originalAcknowledgedAt));
        }
    }

    /**
     * @param  array<int, ControlRoomAlert>  $alerts
     */
    private function acknowledgementAuditCountFor(array $alerts): int
    {
        return AuditLog::query()
            ->where('action', 'controlRoom.alert.acknowledge')
            ->where('auditable_type', ControlRoomAlert::query()->getModel()->getMorphClass())
            ->whereIn('auditable_id', collect($alerts)->pluck('id'))
            ->count();
    }
}
