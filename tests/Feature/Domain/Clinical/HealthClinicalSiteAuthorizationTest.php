<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Events\ClinicalEventRecorded;
use App\Domain\Clinical\Events\ObservationRecorded;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Models\ClinicalRiskAssessment;
use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Domain\Clinical\Services\ClinicalSignalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\ClientFluidEntry;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression coverage for canonical Site access on Health & Clinical writes.
 *
 * The coordinator has the required clinical permissions and is linked to
 * both Clients, but is current staff at only one Site. Direct writes must still
 * be rejected at the other Site, proving assignment never replaces Site access.
 */
class HealthClinicalSiteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinicalUser;

    protected Client $visibleClient;

    protected Client $outsideClient;

    protected Site $visibleSite;

    protected Site $outsideSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $coordinatorRole = Role::where('name', 'coordinator')->firstOrFail();

        $this->visibleSite = Site::factory()->create(['is_active' => true]);
        $this->outsideSite = Site::factory()->create(['is_active' => true]);

        $this->clinicalUser = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $this->clinicalUser->roles()->attach($coordinatorRole);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->clinicalUser->id,
            'primary_site_id' => $this->visibleSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $this->visibleClient = Client::factory()->create(['site_id' => $this->visibleSite->id]);
        $this->outsideClient = Client::factory()->create(['site_id' => $this->outsideSite->id]);
        $this->visibleClient->supportWorkers()->attach($this->clinicalUser->id);
        $this->outsideClient->supportWorkers()->attach($this->clinicalUser->id);
    }

    public function test_clinical_user_cannot_record_observation_for_client_at_another_site(): void
    {
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/observations', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_observations', [
            'client_id' => $this->outsideClient->id,
        ]);
    }

    public function test_clinical_user_cannot_record_event_for_client_at_another_site(): void
    {
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/events', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('clinical_events', [
            'client_id' => $this->outsideClient->id,
        ]);
    }

    public function test_client_policy_allows_assigned_site_and_denies_outside_site_client_view(): void
    {
        $this->assertTrue(
            Gate::forUser($this->clinicalUser)->allows('view', $this->visibleClient),
            'Client view should be allowed at the employee current Site.'
        );

        $this->assertFalse(
            Gate::forUser($this->clinicalUser)->allows('view', $this->outsideClient),
            'Client view outside the employee current Sites must be denied.'
        );
    }

    public function test_site_restricted_registers_pickers_aggregates_and_lenses_exclude_site_b(): void
    {
        $visibleStaff = $this->currentStaffAt($this->visibleSite);
        $outsideStaff = $this->currentStaffAt($this->outsideSite);

        $visibleObservation = ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->visibleSite->id,
            'recorded_by' => $visibleStaff->id,
            'recorded_at' => now(),
        ]);
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'recorded_by' => $outsideStaff->id,
            'recorded_at' => now(),
        ]);

        $visibleEvent = ClinicalEvent::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->visibleSite->id,
            'reported_by' => $visibleStaff->id,
            'occurred_at' => now(),
            'reviewed_at' => null,
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'reported_by' => $outsideStaff->id,
            'occurred_at' => now(),
            'reviewed_at' => null,
        ]);

        $visibleProtocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->visibleClient->id,
            'is_active' => true,
        ]);
        $outsideProtocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->outsideClient->id,
            'is_active' => true,
        ]);
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $visibleProtocol->id,
            'status' => 'pending',
            'due_at' => now()->subHour(),
        ]);
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $outsideProtocol->id,
            'status' => 'pending',
            'due_at' => now()->subHour(),
        ]);

        $visibleBehaviour = BehaviourAbcEntry::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->visibleSite->id,
            'recorded_by' => $visibleStaff->id,
            'occurred_at' => now(),
        ]);
        BehaviourAbcEntry::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'recorded_by' => $outsideStaff->id,
            'occurred_at' => now(),
        ]);

        $visibleAssessment = $this->assessmentFor($this->visibleClient, $visibleStaff);
        $this->assessmentFor($this->outsideClient, $outsideStaff);

        ClientFluidEntry::query()->create([
            'client_id' => $this->visibleClient->id,
            'occurred_at' => now(),
            'direction' => 'intake',
            'fluid_type' => 'water',
            'volume_ml' => 250,
            'recorded_by' => $visibleStaff->id,
        ]);
        ClientFluidEntry::query()->create([
            'client_id' => $this->outsideClient->id,
            'occurred_at' => now(),
            'direction' => 'intake',
            'fluid_type' => 'water',
            'volume_ml' => 900,
            'recorded_by' => $outsideStaff->id,
        ]);

        $visibleRestraint = RestraintEvent::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->visibleSite->id,
        ]);
        RestraintEvent::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
        ]);

        $dashboard = app(ClinicalDashboardService::class);

        $this->assertSame([$visibleObservation->id], $dashboard->getObservationRegister($this->clinicalUser)->pluck('id')->all());
        $this->assertSame([$visibleEvent->id], $dashboard->getEventRegister($this->clinicalUser)->pluck('id')->all());
        $this->assertSame([$visibleProtocol->id], $dashboard->getProtocolRegister($this->clinicalUser)->pluck('id')->all());
        $this->assertSame([$visibleBehaviour->id], $dashboard->getBehaviourRegister($this->clinicalUser)->pluck('id')->all());
        $this->assertSame([$visibleAssessment->id], $dashboard->getAssessmentsRegister($this->clinicalUser)->pluck('id')->all());
        $this->assertSame([$visibleRestraint->id], collect($dashboard->getRestraintLens($this->clinicalUser)['events'])->pluck('id')->all());

        $monitoring = $dashboard->getMonitoringRollup($this->clinicalUser);
        $this->assertSame(1, $monitoring['stats']['fluid_30d']);
        $this->assertSame(250, $monitoring['stats']['fluid_intake_ml_7d']);
        $this->assertCount(1, $monitoring['recent_fluid']);

        $kpis = $dashboard->getKpis($this->clinicalUser);
        $this->assertSame(1, $kpis['protocols_active']);
        $this->assertSame(1, $kpis['observations_7d']);
        $this->assertSame(1, $kpis['schedules_overdue']);
        $this->assertSame(1, $kpis['events_30d']);

        $this->actingAs($this->clinicalUser)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
                ->where('observations.data.0.id', $visibleObservation->id)
                ->where('stats.total_7d', 1)
                ->has('filter_options.clients', 1)
                ->where('filter_options.clients.0.id', $this->visibleClient->id)
                ->has('filter_options.sites', 1)
                ->where('filter_options.sites.0.id', $this->visibleSite->id)
                ->where('filter_options.staff', fn ($staff) => collect($staff)->pluck('id')->contains($visibleStaff->id)
                    && ! collect($staff)->pluck('id')->contains($outsideStaff->id))
            );

        $this->actingAs($this->clinicalUser)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $visibleEvent->id)
                ->where('stats.total_30d', 1)
                ->has('filter_options.clients', 1)
                ->has('filter_options.sites', 1)
            );
    }

    public function test_site_b_only_data_produces_zero_rows_counts_and_attention_badges_for_site_a(): void
    {
        $outsideStaff = $this->currentStaffAt($this->outsideSite);
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'recorded_by' => $outsideStaff->id,
            'recorded_at' => now(),
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'reported_by' => $outsideStaff->id,
            'occurred_at' => now(),
            'reviewed_at' => null,
        ]);
        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->outsideClient->id,
            'is_active' => true,
        ]);
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'status' => 'pending',
            'due_at' => now()->subHour(),
        ]);
        $this->assessmentFor($this->outsideClient, $outsideStaff);

        $dashboard = app(ClinicalDashboardService::class);
        $kpis = $dashboard->getKpis($this->clinicalUser);
        $tabs = $dashboard->getTabCounts($this->clinicalUser, $kpis);

        $this->assertSame(0, $kpis['protocols_active']);
        $this->assertSame(0, $kpis['observations_today']);
        $this->assertSame(0, $kpis['observations_7d']);
        $this->assertSame(0, $kpis['schedules_due']);
        $this->assertSame(0, $kpis['schedules_overdue']);
        $this->assertSame(0, $kpis['events_30d']);
        $this->assertSame(0, $tabs['observations']);
        $this->assertSame(0, $tabs['clinical_events']);
        $this->assertSame(0, $tabs['assessments']);
        $this->assertSame(0, $dashboard->getObservationRegisterStats($this->clinicalUser)['total_30d']);
        $this->assertSame(0, $dashboard->getEventRegisterStats($this->clinicalUser)['total_30d']);
        $this->assertSame(0, $dashboard->getAssessmentsRegisterStats($this->clinicalUser)['total']);

        $this->actingAs($this->clinicalUser)
            ->get('/health-clinical')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.protocols_active', 0)
                ->where('kpis.observations_7d', 0)
                ->where('kpis.events_30d', 0)
                ->where('tab_counts.observations', 0)
                ->where('tab_counts.clinical_events', 0)
                ->has('deterioration_watch', 0)
                ->has('overdue_items', 0)
                ->has('recent_events', 0)
                ->has('recent_observations', 0)
            );
    }

    public function test_direct_site_b_reads_writes_shift_and_protocol_schedule_completion_are_denied_without_side_effects(): void
    {
        Notification::fake();
        Event::fake([ObservationRecorded::class, ClinicalEventRecorded::class]);

        $outsideProtocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->outsideClient->id,
        ]);
        $outsideSchedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $outsideProtocol->id,
            'status' => 'pending',
            'due_at' => now(),
        ]);
        $outsideShift = Shift::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'user_id' => $this->clinicalUser->id,
        ]);

        $auditCount = DB::table('audit_logs')->count();
        $timelineCount = DB::table('timeline_events')->count();

        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/clients/{$this->outsideClient->id}/clinical-card")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/clients/{$this->outsideClient->id}/trends")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/clients/{$this->outsideClient->id}/summary")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/observations?client_id={$this->outsideClient->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/events?site_id={$this->outsideSite->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/behaviour?client_id={$this->outsideClient->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/health-monitoring?client_id={$this->outsideClient->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/assessments?client_id={$this->outsideClient->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/protocols?client_id={$this->outsideClient->id}")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->get("/shifts/{$outsideShift->id}/clinical/observations/due")
            ->assertForbidden();

        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/observations', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/events', ['client_id' => $this->outsideClient->id])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/assessments', [
                'client_id' => $this->outsideClient->id,
                'assessment_type' => ClinicalAssessmentType::MalnutritionMust->value,
            ])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/protocols', [
                'client_id' => $this->outsideClient->id,
                'name' => 'Site B weight protocol',
                'observation_type' => ObservationType::Weight->value,
                'frequency' => ProtocolFrequency::Daily->value,
                'alert_if_missed_hours' => 2,
            ])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->postJson("/shifts/{$outsideShift->id}/clinical/observations", [
                'observation_type' => ObservationType::Weight->value,
                'data' => ['weight_kg' => 70],
            ])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->post('/health-clinical/observations', [
                'client_id' => $this->visibleClient->id,
                'observation_type' => ObservationType::Weight->value,
                'data' => ['weight_kg' => 70],
                'protocol_schedule_id' => $outsideSchedule->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('clinical_events', 0);
        $this->assertDatabaseCount('clinical_risk_assessments', 0);
        $this->assertDatabaseCount('clinical_protocols', 1);
        $this->assertSame('pending', $outsideSchedule->fresh()->status);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($timelineCount, DB::table('timeline_events')->count());
        Notification::assertNothingSent();
        Event::assertNotDispatched(ObservationRecorded::class);
        Event::assertNotDispatched(ClinicalEventRecorded::class);
    }

    public function test_conflicting_site_snapshots_are_hidden_from_rows_counts_cards_trends_and_summaries(): void
    {
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->outsideSite->id,
            'recorded_at' => now(),
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->outsideSite->id,
            'occurred_at' => now(),
        ]);
        BehaviourAbcEntry::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->outsideSite->id,
            'occurred_at' => now(),
        ]);

        $dashboard = app(ClinicalDashboardService::class);
        $this->assertSame(0, $dashboard->getObservationRegister($this->clinicalUser)->total());
        $this->assertSame(0, $dashboard->getEventRegister($this->clinicalUser)->total());
        $this->assertSame(0, $dashboard->getBehaviourRegister($this->clinicalUser)->total());
        $this->assertSame(0, $dashboard->getKpis($this->clinicalUser)['observations_today']);
        $this->assertSame(0, $dashboard->getKpis($this->clinicalUser)['events_30d']);

        $this->actingAs($this->clinicalUser)
            ->getJson("/health-clinical/clients/{$this->visibleClient->id}/clinical-card")
            ->assertOk()
            ->assertJsonPath('baseline_vitals', null);

        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/clients/{$this->visibleClient->id}/trends")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('chartable_observation_count', 0)
            );

        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/clients/{$this->visibleClient->id}/summary")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('summary.recent_observations', 0)
                ->has('summary.recent_events', 0)
            );
    }

    public function test_direct_site_b_event_review_followup_escalation_and_protocol_mutation_are_denied(): void
    {
        $signals = $this->mock(ClinicalSignalService::class);
        $signals->shouldNotReceive('emitForEscalation');

        $outsideEvent = ClinicalEvent::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'requires_followup' => true,
            'followup_completed_at' => null,
            'reviewed_at' => null,
        ]);
        $conflictingEvent = ClinicalEvent::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->outsideSite->id,
            'reviewed_at' => null,
        ]);
        $outsideProtocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->outsideClient->id,
            'name' => 'Protected Site B protocol',
            'is_active' => true,
        ]);

        $auditCount = DB::table('audit_logs')->count();
        $timelineCount = DB::table('timeline_events')->count();

        $this->actingAs($this->clinicalUser)
            ->patch("/health-clinical/events/{$outsideEvent->id}/review")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->patch("/health-clinical/events/{$outsideEvent->id}/follow-up/complete")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->post("/health-clinical/events/{$outsideEvent->id}/escalate")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->patch("/health-clinical/events/{$conflictingEvent->id}/review")
            ->assertForbidden();

        $this->actingAs($this->clinicalUser)
            ->get("/health-clinical/protocols/{$outsideProtocol->id}/edit")
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->put("/health-clinical/protocols/{$outsideProtocol->id}", [
                'name' => 'Attempted mutation',
                'observation_type' => $outsideProtocol->observation_type->value,
                'frequency' => $outsideProtocol->frequency->value,
                'alert_if_missed_hours' => 4,
            ])
            ->assertForbidden();
        $this->actingAs($this->clinicalUser)
            ->patch("/health-clinical/protocols/{$outsideProtocol->id}/toggle-active")
            ->assertForbidden();

        $outsideEvent->refresh();
        $this->assertNull($outsideEvent->reviewed_at);
        $this->assertNull($outsideEvent->followup_completed_at);
        $this->assertSame('Protected Site B protocol', $outsideProtocol->fresh()->name);
        $this->assertTrue($outsideProtocol->fresh()->is_active);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($timelineCount, DB::table('timeline_events')->count());
    }

    public function test_clinical_lead_has_explicit_global_exception_and_positive_cross_site_flows(): void
    {
        $clinicalLead = $this->userWithRole('clinical_lead');
        $this->assertTrue($clinicalLead->canDo('clinical.accessAllSites'));
        $this->assertFalse($this->clinicalUser->canDo('clinical.accessAllSites'));
        $this->assertTrue(Gate::forUser($clinicalLead)->allows('view', $this->outsideClient));

        ClinicalObservation::factory()->create([
            'client_id' => $this->visibleClient->id,
            'site_id' => $this->visibleSite->id,
            'recorded_at' => now(),
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'recorded_at' => now(),
        ]);
        $outsideEvent = ClinicalEvent::factory()->create([
            'client_id' => $this->outsideClient->id,
            'site_id' => $this->outsideSite->id,
            'reviewed_at' => null,
        ]);

        $this->actingAs($clinicalLead)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 2)
                ->has('filter_options.clients', 2)
                ->has('filter_options.sites', 2)
                ->where('kpis.observations_7d', 2)
            );

        $this->actingAs($clinicalLead)
            ->patch("/health-clinical/events/{$outsideEvent->id}/review")
            ->assertRedirect();
        $this->assertSame($clinicalLead->id, $outsideEvent->fresh()->reviewed_by);

        $this->actingAs($clinicalLead)
            ->post('/health-clinical/observations', [
                'client_id' => $this->outsideClient->id,
                'observation_type' => ObservationType::Weight->value,
                'data' => ['weight_kg' => 71.2],
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->outsideClient->id,
            'recorded_by' => $clinicalLead->id,
            'observation_type' => ObservationType::Weight->value,
        ]);
    }

    private function currentStaffAt(Site $site): User
    {
        $user = $this->userWithRole('support_worker');
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function assessmentFor(Client $client, User $assessor): ClinicalRiskAssessment
    {
        return ClinicalRiskAssessment::query()->create([
            'client_id' => $client->id,
            'assessed_by' => $assessor->id,
            'assessment_type' => ClinicalAssessmentType::PressureBraden->value,
            'assessed_at' => now(),
            'inputs' => [],
            'total_score' => 10,
            'risk_band' => 'high',
            'breakdown' => [],
            'summary' => 'Scoped assessment',
            'tool_version' => 'test',
            'review_due_at' => today(),
        ]);
    }
}
