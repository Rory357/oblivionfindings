<?php

namespace Tests\Feature;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\StrategicGoal;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientNote;
use App\Models\FleetWorkOrder;
use App\Models\IncidentFollowup;
use App\Models\ItChange;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Overview\OverviewAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, OperationsPermissionsSeeder::class]);
    }

    private function actor(string $role = 'support_worker', ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        if ($role === 'admin') {
            // These module permissions have independent seeders. A role label
            // itself never grants access to the corresponding overview.
            foreach (['hr.analytics.view', 'it.view', 'governance.view'] as $key) {
                $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
                $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
            }
        }
        if ($site) {
            HrEmployeeProfile::query()->create(['user_id' => $user->id, 'employee_number' => 'OV-'.$user->id,
                'work_email' => $user->email, 'position_title' => 'Overview test', 'position_role' => 'staff',
                'employment_type' => 'full_time', 'start_date' => today()->subMonth(), 'is_active' => true,
                'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        }

        return $user->fresh();
    }

    private function layout(string $id = 'attention'): array
    {
        return ['blocks' => [['id' => $id, 'span' => 8, 'height' => 0, 'site' => 'page']], 'favorites' => [], 'copies' => [], 'density' => 'balanced', 'range' => 'today'];
    }

    private function grant(User $user, array $permissions): User
    {
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user->fresh();
    }

    public function test_worker_draft_block_is_own_permitted_client_work_and_hides_note_contents(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['progress_notes.viewAny', 'progress_notes.create']);
        $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Merewhiua', 'preferred_name' => 'Mere']);
        $client->supportWorkers()->attach($user->id);
        $hidden = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Hiddenperson', 'preferred_name' => null]);
        $foreign = Client::factory()->create();
        $base = ['client_id' => $client->id, 'user_id' => $user->id, 'type' => 'daily_note', 'body' => 'Private care narrative', 'subject' => 'Private care subject', 'is_draft' => true];
        $draft = ClientNote::query()->create($base);
        ClientNote::query()->create([...$base, 'client_id' => $hidden->id]);
        ClientNote::query()->create([...$base, 'client_id' => $foreign->id]);
        ClientNote::query()->create([...$base, 'user_id' => User::factory()->create()->id]);
        ClientNote::query()->create([...$base, 'is_draft' => false]);
        ClientNote::query()->create($base)->delete();
        $incoherent = Shift::factory()->create(['site_id' => $site->id, 'client_id' => $hidden->id, 'user_id' => $user->id]);
        ClientNote::query()->create([...$base, 'shift_id' => $incoherent->id]);
        $this->actingAs($user)->getJson('/dashboard/data?role=worker')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.worker-notes.state', 'ready')->assertJsonPath('blocks.worker-notes.total', 1)
            // The preferred name says whose note it is; the contents stay private.
            ->assertJsonPath('blocks.worker-notes.rows.0.label', 'Daily note for Mere')
            ->assertJsonPath('blocks.worker-notes.rows.0.href', '/operations/clients/'.$client->id.'?tab=progress_notes')
            ->assertDontSee('Private care narrative')->assertDontSee('Private care subject')->assertDontSee('Hiddenperson');
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'progress_notes.viewAny')->sole()->id => ['allowed' => false]]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=worker')->assertOk()->assertJsonPath('blocks.worker-notes.state', 'restricted');
    }

    public function test_supervision_blocks_use_latest_current_staff_dates_without_private_discussions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('team_lead', $site), ['hr.performance.view', 'hr.analytics.view']);
        $worker = $this->actor('support_worker', $site);
        $hidden = $this->actor('support_worker', Site::factory()->create());
        $base = ['employee_user_id' => $worker->id, 'supervisor_user_id' => $user->id, 'session_date' => '2026-10-01', 'next_session_date' => '2026-10-08', 'topics_discussed' => 'Private supervision discussion', 'employee_comments' => 'Private staff comments'];
        HrSupervisionNote::query()->create($base);
        HrSupervisionNote::query()->create([...$base, 'session_date' => '2026-10-10', 'next_session_date' => '2026-10-17']);
        HrSupervisionNote::query()->create([...$base, 'employee_user_id' => $hidden->id]);
        foreach (['lead' => 'lead-supervision', 'hr' => 'hr-supervision'] as $role => $id) {
            $this->actingAs($user)->getJson('/dashboard/data?role='.$role)->assertOk()->assertJsonPath('partial', false)
                ->assertJsonPath('blocks.'.$id.'.total', 1)->assertJsonPath('blocks.'.$id.'.rows.0.date', '2026-10-17')
                ->assertJsonPath('blocks.'.$id.'.rows.0.label', $worker->name)
                ->assertDontSee('Private supervision discussion')->assertDontSee('Private staff comments');
        }
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hr.performance.view')->sole()->id => ['allowed' => false]]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=lead')->assertOk()->assertJsonPath('blocks.lead-supervision.state', 'restricted');
    }

    public function test_hr_leaver_block_counts_practical_tasks_with_historical_staff_scope(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['hr.analytics.view', 'hr.onboarding.view']);
        $worker = $this->actor('support_worker', $site);
        $hidden = $this->actor('support_worker', Site::factory()->create());
        $base = ['employee_profile_id' => $worker->hrEmployeeProfile->id, 'template_key' => 'standard', 'status' => 'in_progress', 'due_date' => '2026-10-13'];
        $checklist = HrOffboardingChecklist::query()->create($base);
        foreach (['pending', 'completed'] as $status) {
            $checklist->tasks()->create(['title' => 'Private departure context', 'status' => $status, 'category' => 'other']);
        }
        HrOffboardingChecklist::query()->create([...$base, 'employee_profile_id' => $hidden->hrEmployeeProfile->id]);
        HrOffboardingChecklist::query()->create([...$base, 'status' => 'completed']);
        $this->actingAs($user)->getJson('/dashboard/data?role=hr')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.hr-exits.total', 1)->assertJsonPath('blocks.hr-exits.rows.0.value', 1)
            ->assertJsonPath('blocks.hr-exits.rows.0.target', 2)->assertJsonPath('blocks.hr-exits.rows.0.href', '/hr/offboarding/'.$checklist->id)
            ->assertDontSee('Private departure context');
    }

    public function test_manager_maintenance_uses_asset_sites_and_never_sends_financial_fields(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['operations.dashboard.view', 'assets.viewAny']);
        $asset = Asset::factory()->create(['site_id' => $site->id]);
        $order = FleetWorkOrder::factory()->create(['asset_id' => $asset->id, 'priority' => 'urgent', 'status' => 'open', 'actual_cost' => 87654.32, 'description' => 'Private repair notes']);
        FleetWorkOrder::factory()->create(['asset_id' => Asset::factory()->create(['site_id' => Site::factory()->create()->id])->id, 'status' => 'open']);
        FleetWorkOrder::factory()->create(['asset_id' => $asset->id, 'status' => 'completed']);
        $this->actingAs($user)->getJson('/dashboard/data?role=manager')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.manager-maintenance.total', 1)->assertJsonPath('blocks.manager-maintenance.rows.0.label', 'Open')
            ->assertJsonPath('blocks.manager-maintenance.rows.0.value', 1)
            ->assertJsonPath('blocks.manager-maintenance.href', '/fleet-assets/maintenance/work-orders?site_id='.$site->id)
            ->assertDontSee('87654.32')->assertDontSee('actual_cost')->assertDontSee('Private repair notes');
    }

    public function test_it_knowledge_reviews_require_authority_over_the_entire_article_audience(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['it.view', 'it.knowledge.review']);
        $base = ['status' => 'published', 'review_due_at' => '2026-10-11', 'audience' => 'specific_sites', 'site_scope' => [$site->id], 'body' => 'Private article contents'];
        $article = ItKbArticle::factory()->create($base);
        ItKbArticle::factory()->create([...$base, 'site_scope' => [$site->id, $other->id], 'title' => 'Shared article outside review authority']);
        ItKbArticle::factory()->create([...$base, 'site_scope' => [$other->id], 'title' => 'Foreign article']);
        ItKbArticle::factory()->create([...$base, 'status' => 'draft', 'title' => 'Draft article']);
        ItKbArticle::factory()->create([...$base, 'review_due_at' => '2026-12-01', 'title' => 'Later review']);
        $this->actingAs($user)->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.it-knowledge.total', 1)->assertJsonPath('blocks.it-knowledge.rows.0.href', '/it/knowledge/'.$article->id)
            ->assertJsonPath('blocks.it-knowledge.rows.0.status', 'Review overdue')
            ->assertDontSee('Shared article outside review authority')->assertDontSee('Foreign article')->assertDontSee('Private article contents');
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'it.knowledge.review')->sole()->id => ['allowed' => false]]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('blocks.it-knowledge.state', 'restricted');
    }

    public function test_board_declaration_block_is_personal_without_interest_details(): void
    {
        $user = $this->grant($this->actor(), ['governance.view', 'governance.interests.view']);
        $member = BoardMember::query()->create(['user_id' => $user->id, 'board_role' => 'member', 'term_start' => '2026-01-01', 'is_active' => true]);
        $other = BoardMember::query()->create(['user_id' => User::factory()->create()->id, 'board_role' => 'member', 'term_start' => '2026-01-01', 'is_active' => true]);
        $base = ['board_member_id' => $member->id, 'recorded_by' => $user->id, 'interest_type' => 'personal', 'entity_name' => 'Private interest entity', 'description' => 'Private interest details', 'nature' => 'Private relationship', 'declared_at' => '2026-10-01', 'is_current' => true];
        $interest = BoardMemberInterest::query()->create($base);
        BoardMemberInterest::query()->create([...$base, 'board_member_id' => $other->id]);
        BoardMemberInterest::query()->create([...$base, 'is_current' => false]);
        $this->actingAs($user)->getJson('/dashboard/data?role=governance')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.governance-declarations.total', 1)->assertJsonPath('blocks.governance-declarations.scopeMode', 'personal')
            ->assertJsonPath('blocks.governance-declarations.rows.0.label', 'Your declaration #'.$interest->id)
            ->assertDontSee('Private interest entity')->assertDontSee('Private interest details')->assertDontSee('Private relationship');
    }

    public function test_worker_agenda_merges_own_open_learning_with_shifts_without_invented_times(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->actor('support_worker', $site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $client->supportWorkers()->attach($user->id);
        Shift::factory()->published()->create(['user_id' => $user->id, 'client_id' => $client->id, 'site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(4)]);
        foreach ([['assigned', '2026-10-11'], ['in_progress', '2026-10-14'], ['completed', '2026-10-10'], ['waived', '2026-10-10'], ['assigned', '2026-11-15']] as [$status, $due]) {
            HrCourseAssignment::factory()->create(['user_id' => $user->id, 'status' => $status, 'due_at' => $due]);
        }
        HrCourseAssignment::factory()->create(['status' => 'assigned', 'due_at' => '2026-10-09']);
        $response = $this->actingAs($user)->getJson('/dashboard/data?role=worker')->assertOk()->assertJsonPath('partial', false);
        $rows = collect($response->json('blocks.agenda.rows'));
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-10-11', '2026-10-14'], $rows->where('href', '/hr/my/training')->pluck('date')->values()->all());
        $this->assertSame('Overdue', $rows->first()['status']);
        $this->assertSame(1, collect($response->json('blocks.attention.rows'))->firstWhere('label', 'Learning is overdue')['value']);
    }

    public function test_connection_history_uses_recorded_checks_and_historical_site_custody(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 14:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['it.view', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        $device = Device::factory()->create(['status' => 'active']);
        $old = DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $other->id, 'assigned_at' => now()->subDay()]);
        $monitor = Monitor::factory()->create(['device_id' => $device->id]);
        MonitorObservation::factory()->create(['monitor_id' => $monitor->id, 'state' => 'failed', 'observed_at' => now()->subHours(4)]);
        $old->update(['released_at' => now()->subHours(3)]);
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subHours(3)]);
        foreach ([['healthy', -2], ['degraded', -1], ['failed', -1], ['failed', 1]] as [$state, $hours]) {
            MonitorObservation::factory()->create(['monitor_id' => $monitor->id, 'state' => $state, 'observed_at' => now()->addHours($hours)]);
        }
        $tls = Monitor::factory()->create(['device_id' => $device->id, 'kind' => 'tls']);
        MonitorObservation::factory()->create(['monitor_id' => $tls->id, 'state' => 'failed']);
        $this->actingAs($user)->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonCount(24, 'blocks.sites.rows.0.history')
            ->assertJsonPath('blocks.sites.rows.0.history.19.checks', 0)
            ->assertJsonPath('blocks.sites.rows.0.history.21.state', 'healthy')
            ->assertJsonPath('blocks.sites.rows.0.history.22.state', 'failed')
            ->assertJsonPath('blocks.sites.rows.0.history.22.checks', 2)
            ->assertJsonPath('blocks.sites.rows.0.history.23.state', 'unknown')
            ->assertJsonPath('blocks.sites.rows.0.history.23.checks', 0);
        $permission = Permission::where('key', 'securityDevices.events.view')->sole();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('blocks.sites.rows.0.history', null);
    }

    public function test_device_freshness_batches_monitor_reads_and_preserves_stale_precedence(): void
    {
        $devices = Device::factory()->count(3)->create(['last_seen_at' => now()]);
        foreach ($devices as $index => $device) {
            Monitor::factory()->create(['device_id' => $device->id, 'current_state' => $index === 0 ? 'stale' : 'healthy', 'last_observation_at' => now()]);
        }
        $service = app(CommandObservationFreshnessService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $results = $service->inspectMany($devices);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(2, count($queries));
        $this->assertSame('stale', $results[$devices[0]->id]->state);
        $this->assertSame('fresh', $results[$devices[1]->id]->state);
        foreach ($devices as $device) {
            $this->assertEquals($service->inspect($device), $results[$device->id]);
        }
    }

    public function test_approve_only_manager_can_reach_more_than_six_requests_and_archived_work_is_excluded(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['operations.dashboard.view', 'timesheets.approve', 'timesheets.viewAssigned']);
        $worker = $this->actor('support_worker', $site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        foreach (range(0, 7) as $index) {
            $shift = Shift::factory()->published()->assignedToday($worker)->create(['site_id' => $site->id, 'client_id' => $client->id]);
            Timesheet::factory()->submitted()->create(['shift_id' => $shift->id, 'site_id' => $site->id, 'archived_at' => $index === 7 ? now() : null]);
        }
        $this->actingAs($user)->getJson('/dashboard/data?role=manager')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.attention.reviewTotal', 7)->assertJsonCount(6, 'blocks.attention.reviews')
            ->assertJsonPath('blocks.attention.reviewQueues.0.value', 7)
            ->assertJsonPath('blocks.attention.reviewQueues.0.href', '/operations/timesheets?tab=submitted')
            ->assertJsonPath('blocks.focus.headline', '7 reviews');
    }

    public function test_it_uses_canonical_response_clocks_and_separate_opened_resolved_series(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['it.view']);
        $base = ['site_id' => $site->id, 'created_at' => now()->subHours(4), 'next_response_party' => 'it'];
        $late = ItTicket::factory()->create([...$base, 'first_response_due_at' => now()->subHour(), 'assigned_to_user_id' => $user->id]);
        ItTicket::factory()->create([...$base, 'status' => 'resolved', 'resolved_at' => now()->subHour(), 'first_response_due_at' => now()->subHours(2), 'first_responded_at' => now()->subHours(3)]);
        ItTicket::factory()->create([...$base, 'status' => 'waiting', 'assigned_to_user_id' => $user->id, 'next_response_party' => 'requester']);
        ItTicket::factory()->create([...$base, 'first_response_due_at' => now()->addHour()]);
        ItTicket::factory()->create([...$base, 'site_id' => Site::factory()->create()->id, 'title' => 'Hidden site request']);
        ItTicket::factory()->create([...$base, 'is_sensitive' => true, 'title' => 'Restricted request']);
        $change = ItChange::query()->create(['ticket_id' => $late->id, 'change_type' => 'standard', 'risk_level' => 'low', 'maintenance_starts_at' => now()->addDay(), 'maintenance_ends_at' => now()->addDay()->addHour()]);
        $response = $this->actingAs($user)->getJson('/dashboard/data?role=it&range=week')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.0.value', 3)->assertJsonPath('metrics.3.value', 1)->assertJsonPath('metrics.3.total', 2)
            ->assertJsonPath('blocks.focus.rows.0.href', '/it/tickets/'.$late->id)
            ->assertJsonPath('blocks.focus.rows.0.date', $late->first_response_due_at->toIso8601String())
            ->assertJsonPath('blocks.trend.series.0.value', 4)->assertJsonPath('blocks.trend.series.0.comparison', 1)
            ->assertJsonPath('blocks.trend.series.1.value', null)->assertJsonPath('blocks.trend.series.1.comparison', null)
            ->assertJsonPath('blocks.distribution.rows.0.value', 1)->assertJsonPath('blocks.distribution.rows.1.value', 1)->assertJsonPath('blocks.distribution.rows.2.value', 1)
            ->assertJsonPath('blocks.agenda.rows.0.href', '/it/changes/'.$change->id)->assertJsonPath('blocks.sites.state', 'restricted');
        $this->assertStringNotContainsString('Hidden site request', $response->getContent());
        $this->assertStringNotContainsString('Restricted request', $response->getContent());
    }

    public function test_it_device_reporting_respects_device_sites_and_monitoring_permission(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['it.view', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        foreach ([[$site, now()->subMinute()], [$site, now()->subHour()], [$other, now()->subMinute()]] as [$target, $observed]) {
            $device = Device::factory()->create(['status' => 'active', 'health_status' => 'healthy', 'last_seen_at' => $observed]);
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $target->id, 'assigned_at' => now()->subDay()]);
        }
        $this->actingAs($user)->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.2.display', '50%')->assertJsonPath('blocks.sites.total', 2)->assertJsonCount(1, 'blocks.sites.rows')
            ->assertJsonPath('blocks.sites.rows.0.status', 'Needs attention')->assertJsonPath('blocks.it-assets.total', 2);
        $permission = Permission::where('key', 'securityDevices.events.view')->sole();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=it')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.2.value', null)->assertJsonPath('blocks.sites.rows.0.status', 'Not assessed');
    }

    public function test_governance_uses_permitted_meetings_and_current_risk_assurance_and_strategy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00', 'Pacific/Auckland')->utc());
        $user = $this->grant($this->actor(), ['governance.view', 'governance.meetings.view', 'governance.risks.view', 'governance.compliance.view', 'governance.strategy.view', 'governance.resolutions.view']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'board_observer'], ['label' => 'Board observer'])->id);
        $user = $user->fresh();
        $meeting = GovernanceMeeting::factory()->create(['meeting_type' => 'board', 'title' => 'Visible board meeting', 'scheduled_at' => now()->addDays(3)]);
        $hiddenMeeting = GovernanceMeeting::factory()->create(['meeting_type' => 'executive_session', 'title' => 'Hidden executive meeting', 'scheduled_at' => now()->addDay()]);
        foreach ([[$meeting, 'Visible decision paper'], [$hiddenMeeting, 'Hidden decision paper']] as [$decisionMeeting, $title]) {
            Resolution::query()->create(['governance_meeting_id' => $decisionMeeting->id, 'title' => $title, 'context' => 'Test decision', 'options' => [], 'status' => 'open', 'deadline' => now()->addDays(2), 'proposed_by' => $user->id, 'proposed_at' => now()]);
        }
        GovernanceMeeting::factory()->create(['meeting_type' => 'board', 'title' => 'Cancelled board meeting', 'status' => 'cancelled', 'scheduled_at' => now()->addDay()]);
        $risk = RiskRegisterEntry::query()->create(['identified_at' => '2026-10-01', 'identified_by' => $user->id, 'risk_reference' => 'OV-RISK-1', 'title' => 'Continuity review', 'description' => 'Supporting details', 'category' => 'operational', 'likelihood_score' => 5, 'impact_score' => 4, 'control_effectiveness' => 'none', 'appetite_threshold' => 10, 'next_review_date' => '2026-10-14', 'risk_owner_id' => $user->id, 'status' => 'active']);
        RiskRegisterEntry::query()->create(['identified_at' => '2026-10-01', 'identified_by' => $user->id, 'risk_reference' => 'OV-RISK-2', 'title' => 'Voided risk', 'description' => 'Old record', 'category' => 'operational', 'likelihood_score' => 5, 'impact_score' => 5, 'control_effectiveness' => 'none', 'appetite_threshold' => 10, 'next_review_date' => '2026-10-01', 'status' => 'voided']);
        foreach ([['Due evidence', '2026-10-11', 'due_soon', false], ['Upcoming obligation', '2026-10-14', 'due_soon', true], ['Cancelled obligation', '2026-10-10', 'cancelled', false]] as [$title, $date, $status, $provided]) {
            ComplianceObligation::query()->create(['description' => 'Test obligation', 'next_due_date' => $date, 'obligation_title' => $title, 'framework' => 'internal', 'frequency' => 'annual', 'due_date' => $date, 'status' => $status, 'evidence_required' => true, 'evidence_provided' => $provided, 'owner_id' => $user->id]);
        }
        $plan = StrategicPlan::query()->create(['vision_statement' => 'Safer care', 'mission_statement' => 'Improve service', 'values' => [], 'title' => 'Current plan', 'planning_horizon' => 'annual', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'status' => 'approved', 'created_by' => $user->id]);
        StrategicGoal::query()->create(['strategic_plan_id' => $plan->id, 'title' => 'Safer services', 'description' => 'Recorded priority', 'timeframe' => 'annual', 'pillar' => 'safety', 'progress_pct' => 42, 'status' => 'in_progress', 'lead_executive_id' => $user->id]);
        $response = $this->actingAs($user)->getJson('/dashboard/data?role=governance&range=month')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.0.value', 1)->assertJsonPath('metrics.2.display', '50%')
            ->assertJsonCount(1, 'blocks.attention.decisions')->assertJsonPath('blocks.attention.decisions.0.label', 'Visible decision paper')
            ->assertJsonPath('blocks.focus.rows.0.href', '/governance/meetings/'.$meeting->id)
            ->assertJsonPath('blocks.focus.rows.0.detail', 'No board papers available in your view')
            ->assertJsonPath('blocks.trend.sections.0.rows.0.href', '/governance/risks/'.$risk->id)
            ->assertJsonPath('blocks.trend.sections.0.rows.0.status', 'Critical')->assertJsonPath('blocks.trend.sections.1.rows.0.label', 'Due evidence')
            ->assertJsonPath('blocks.sites.rows.0.value', 42)->assertJsonPath('blocks.sites.presentation', 'priorities');
        foreach (['Hidden executive meeting', 'Hidden decision paper', 'Cancelled board meeting', 'Voided risk', 'Cancelled obligation'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
        $restricted = $this->grant($this->actor(), ['governance.view']);
        $this->actingAs($restricted)->getJson('/dashboard/data?role=governance')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.focus.state', 'restricted')->assertJsonPath('blocks.sites.state', 'restricted')
            ->assertJsonPath('blocks.trend.sections.0.state', 'restricted')->assertJsonPath('blocks.trend.sections.1.state', 'restricted')
            ->assertJsonPath('blocks.governance-risk-milestones.state', 'restricted')->assertJsonPath('blocks.governance-assurance.state', 'restricted')
            ->assertJsonPath('blocks.governance-reporting.state', 'restricted')->assertJsonPath('blocks.governance-policy.state', 'restricted')
            ->assertJsonPath('blocks.governance-committees.state', 'restricted');
    }

    public function test_lead_coverage_uses_requirements_and_actual_on_duty_staff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('team_lead', $site), ['shifts.manageAny', 'operations.dashboard.view', 'rostering.viewAny']);
        $worker = $this->actor('support_worker', $site);
        $worker->update(['name' => 'Team Worker']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        SiteCoverageRequirement::create(['site_id' => $site->id, 'name' => 'Morning cover', 'coverage_type' => 'custom',
            'day_of_week' => 'mon', 'starts_time' => '09:00', 'ends_time' => '13:00', 'minimum_staff' => 2,
            'role_requirements' => [], 'allow_overstaffing' => true, 'is_active' => true]);
        Shift::factory()->published()->assignedToday($worker)->create(['site_id' => $site->id, 'client_id' => $client->id,
            'status' => 'in_progress', 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null]);
        Shift::factory()->published()->assignedToday($user)->create(['site_id' => $site->id, 'client_id' => $client->id, 'status' => 'cancelled']);
        $this->actingAs($user)->getJson('/dashboard/data?role=lead')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.0.display', '1 / 2')->assertJsonPath('metrics.2.label', 'On duty')->assertJsonPath('metrics.2.value', 1)
            ->assertJsonPath('metrics.2.avatars.0', 'TW')
            ->assertJsonPath('blocks.trend.coverage.gaps', 1)->assertJsonPath('blocks.trend.coverage.rows.0.cells.0.0.assigned', 1)
            ->assertJsonPath('blocks.trend.coverage.rows.0.cells.0.0.required', 2)->assertJsonPath('blocks.sites.rows.0.status', 'Cover needed');

        // A contributing Shift with incoherent Client provenance cannot turn a missing position into green coverage.
        $foreignClient = Client::factory()->create();
        Shift::factory()->published()->assignedToday($user)->create(['site_id' => $site->id, 'client_id' => $foreignClient->id]);
        $this->getJson('/dashboard/data?role=lead')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.0.value', null)->assertJsonPath('blocks.trend.coverage.unassessed', 1)
            ->assertJsonPath('blocks.trend.coverage.rows.0.cells.0.0.assigned', null)->assertJsonPath('blocks.distribution.state', 'unavailable');
    }

    public function test_lead_no_requirements_and_owner_only_handovers_remain_honest(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('team_lead', $site), ['operations.dashboard.view', 'shifts.viewAssigned']);
        foreach (['shifts.manageAny', 'shifts.viewAny', 'handovers.viewAny', 'incidents.viewAny'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
        $other = $this->actor('support_worker', $site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        foreach ([$user, $other] as $worker) {
            $out = Shift::factory()->published()->assignedToday($worker)->create(['site_id' => $site->id, 'client_id' => $client->id]);
            $in = Shift::factory()->published()->create(['user_id' => $worker->id, 'site_id' => $site->id, 'client_id' => $client->id,
                'starts_at' => now()->addHours(4), 'ends_at' => now()->addHours(8)]);
            ShiftHandover::factory()->create(['outgoing_shift_id' => $out->id, 'incoming_shift_id' => $in->id,
                'outgoing_staff_id' => $worker->id, 'incoming_staff_id' => $worker->id, 'client_id' => $client->id,
                'handover_notes' => 'Confidential clinical handover']);
        }
        $this->grant($user, ['incidents.viewAssigned']);
        $client->supportWorkers()->attach($user->id);
        $visible = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $site->id]);
        IncidentFollowup::factory()->create(['client_incident_id' => $visible->id, 'completed_at' => null, 'due_at' => now()->subDay()]);
        $hidden = ClientIncident::factory()->create(['client_id' => Client::factory()->create(['site_id' => $site->id])->id, 'site_id' => $site->id]);
        IncidentFollowup::factory()->create(['client_incident_id' => $hidden->id, 'completed_at' => null]);
        $this->actingAs($user->fresh())->getJson('/dashboard/data?role=lead')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('metrics.0.value', null)->assertJsonPath('blocks.trend.coverage.windows', 0)
            ->assertJsonPath('blocks.lead-handover.total', 1)->assertJsonPath('blocks.focus.title', 'The next handover')
            ->assertJsonPath('blocks.sites.presentation', 'connected-teams')
            ->assertJsonPath('blocks.sites.rows.0.followups.label', '1 open')
            ->assertJsonPath('blocks.sites.rows.0.followups.detail', '1 overdue')
            ->assertJsonPath('blocks.sites.rows.0.handover.href', '/operations/handovers/'.ShiftHandover::where('incoming_staff_id', $user->id)->sole()->id)
            ->assertDontSee('Confidential clinical handover');
    }

    public function test_manager_queue_links_permitted_records_and_omits_private_details(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('admin', $site), ['timesheets.viewAny', 'timesheets.approve', 'hr.leave.approve', 'incidents.viewAny', 'incidents.approve']);
        $worker = $this->actor('support_worker', $site);
        $other = $this->actor('support_worker', $otherSite);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->published()->assignedToday($worker)->create(['site_id' => $site->id, 'client_id' => $client->id]);
        $sheet = Timesheet::factory()->submitted()->create(['shift_id' => $shift->id, 'site_id' => $site->id, 'notes' => 'Private time notes']);
        $leave = HrLeaveRequest::factory()->create(['user_id' => $worker->id, 'status' => 'pending', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3), 'approval_due_at' => now()->subHour(), 'reason' => 'Private leave reason']);
        $foreignLeave = HrLeaveRequest::factory()->create(['user_id' => $other->id, 'status' => 'pending']);
        $incident = ClientIncident::factory()->submitted()->highSeverity()->create(['client_id' => $client->id, 'site_id' => $site->id, 'submitted_at' => now()->subHour(), 'description' => 'Private incident detail']);
        $url = '/dashboard/data?role=manager&site='.$site->id;
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.focus.headline', '3 reviews')
            ->assertJsonPath('blocks.focus.rows.0.href', '/incidents/'.$incident->id)
            ->assertJsonPath('blocks.attention.reviewTotal', 3)
            ->assertJsonPath('blocks.attention.reviews.1.href', '/hr/leave/'.$leave->id)
            ->assertJsonPath('blocks.attention.reviews.2.href', '/operations/timesheets/'.$sheet->id)
            ->assertDontSee('Private leave reason')->assertDontSee('Private incident detail')->assertDontSee('Private time notes')
            ->assertDontSee('/hr/leave/'.$foreignLeave->id.'"', false);
        $this->getJson('/operations/timesheets/'.$sheet->id)->assertOk();
        // A generic leave-view grant cannot reveal the approval workspace's queue.
        foreach (['hr.leave.approve', 'hr.leave.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
        $user = $this->grant($user->fresh(), ['hr.leave.viewAny']);
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.attention.reviewTotal', 2)->assertDontSee('/hr/leave/'.$leave->id.'"', false);
    }

    public function test_hr_pipeline_counts_candidates_once_and_rejects_foreign_application_graphs(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('support_worker', $site), ['hr.analytics.view', 'hr.recruitment.view']);
        foreach (['new', 'offer_pending', 'onboarding', 'hired'] as $status) {
            $candidate = HrCandidate::factory()->create(['status' => $status]);
            HrApplication::factory()->count(2)->create(['candidate_id' => $candidate->id, 'target_site_id' => $site->id]);
        }
        $hidden = HrCandidate::factory()->create(['status' => 'new']);
        foreach ([$site, $other] as $target) {
            HrApplication::factory()->create(['candidate_id' => $hidden->id, 'target_site_id' => $target->id]);
        }
        foreach ([[$site, 'published'], [$site, 'draft'], [$other, 'published']] as [$target, $status]) {
            HrJobRequisition::create(['title' => 'Scoped job', 'slug' => 'scoped-'.$target->id.'-'.$status, 'position_role' => 'support_worker',
                'site_id' => $target->id, 'employment_type' => 'full_time', 'status' => $status, 'openings' => 3, 'created_by' => $user->id]);
        }
        $this->actingAs($user)->getJson('/dashboard/data?role=hr')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.sites.presentation', 'pipeline')->assertJsonPath('blocks.sites.total', 3)
            ->assertJsonPath('blocks.sites.rows.0.value', 1)->assertJsonPath('blocks.sites.rows.4.value', 1)->assertJsonPath('blocks.sites.rows.5.value', 1)
            ->assertJsonPath('metrics.3.value', 1)->assertJsonPath('metrics.3.caption', 'Published job adverts')
            ->assertJsonPath('blocks.focus.state', 'restricted')->assertJsonPath('blocks.distribution.state', 'restricted');
    }

    public function test_hr_training_counts_people_and_includes_unmaterialised_requirements(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('admin', $site), ['hr.compliance.view']);
        $current = $this->actor('support_worker', $site);
        $missing = $this->actor('support_worker', $site);
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->actor('support_worker', $otherSite);
        foreach (['A', 'B'] as $code) {
            $requirement = HrComplianceRequirement::create(['code' => 'OV-'.$code, 'name' => 'Private training '.$code,
                'category' => 'training', 'check_type' => 'manual', 'is_active' => true, 'hard_stop' => false]);
            $requirement->matrixEntries()->create(['role' => 'support_worker', 'site_type' => 'all', 'is_mandatory' => true]);
            HrStaffComplianceStatus::create(['user_id' => $current->id, 'requirement_id' => $requirement->id, 'status' => 'compliant', 'evidence_type' => 'manual']);
            if ($code === 'A') {
                HrStaffComplianceStatus::create(['user_id' => $missing->id, 'requirement_id' => $requirement->id, 'status' => 'compliant', 'evidence_type' => 'manual']);
            }
        }
        $this->actingAs($user)->getJson('/dashboard/data?role=hr&site='.$site->id)->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.distribution.total', 3)->assertJsonPath('blocks.distribution.rows.0.value', 1)
            ->assertJsonPath('blocks.distribution.rows.2.value', 1)->assertJsonPath('blocks.distribution.rows.3.value', 1)
            ->assertJsonPath('metrics.2.display', '33%')->assertDontSee('Private training');
    }

    public function test_hr_joiners_and_historical_headcount_use_recorded_dates_without_future_projections(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->grant($this->actor('admin', $site), ['hr.onboarding.view']);
        foreach ([true, false] as $prepared) {
            $starter = $this->actor('support_worker', $site);
            $starter->update(['approved_at' => null]);
            $starter->hrEmployeeProfile->update(['start_date' => '2026-10-15']);
            if ($prepared) {
                $list = HrOnboardingChecklist::create(['employee_profile_id' => $starter->hrEmployeeProfile->id, 'template_key' => 'standard', 'status' => 'completed', 'created_by' => $user->id]);
                $list->tasks()->create(['category' => 'hr', 'title' => 'Confidential onboarding task', 'status' => 'completed']);
            }
        }
        $leaver = $this->actor('support_worker', $site);
        $leaver->hrEmployeeProfile->update(['start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'is_active' => false]);
        $this->actingAs($user)->getJson('/dashboard/data?role=hr&range=month&site='.$site->id)->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.focus.total', 2)->assertJsonPath('blocks.focus.focusMeta.0', '1 checklist complete')
            ->assertJsonPath('blocks.focus.focusMeta.1', '1 needs preparation')->assertJsonPath('metrics.0.value', 1)
            ->assertJsonPath('blocks.trend.series.0.value', 2)->assertJsonPath('blocks.trend.series.11.value', 1)
            ->assertJsonPath('blocks.trend.series.12.value', null)->assertDontSee('Confidential onboarding task');
    }

    public function test_overview_and_data_require_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson('/dashboard/data')->assertUnauthorized();
    }

    public function test_worker_has_separate_summary_and_own_my_day_destination(): void
    {
        $user = $this->actor();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard')->where('role', 'worker')->where('filters.range', 'today')
            ->where('overview.blocks.focus.href', '/my-day')->where('overview.partial', false));
        $this->get('/today')->assertRedirect('/my-day');
    }

    public function test_worker_cannot_request_privileged_views_or_layouts(): void
    {
        $this->actingAs($this->actor());
        foreach (['manager', 'hr', 'it', 'governance'] as $role) {
            $this->getJson('/dashboard/data?role='.$role)->assertForbidden();
            $this->putJson('/dashboard/layout/'.$role, ['revision' => null, 'value' => $this->layout()])->assertForbidden();
        }
    }

    public function test_each_admin_role_view_has_real_or_explicitly_proposed_blocks(): void
    {
        $this->actingAs($this->actor('admin'));
        foreach (OverviewAccess::ROLES as $role) {
            $response = $this->getJson('/dashboard/data?role='.$role)->assertOk()->assertJsonPath('partial', false);
            foreach (array_column(app(OverviewAccess::class)->catalog($role)['blocks'], 'id') as $id) {
                $this->assertContains($response->json('blocks.'.$id.'.state'), ['ready', 'restricted', 'proposed']);
            }
            $response->assertDontSee('Harbour House')->assertDontSee('Kōwhai House');
        }
    }

    public function test_site_filters_and_saved_block_scopes_reject_foreign_sites(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->actor('support_worker', $site);
        $this->actingAs($user)->getJson('/dashboard/data?site='.$other->id)->assertForbidden();
        $value = $this->layout();
        $value['blocks'][0]['site'] = (string) $other->id;
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertUnprocessable();
        $this->assertDatabaseMissing('user_ui_preferences', ['user_id' => $user->id, 'key' => 'overview.workspace.worker']);
    }

    public function test_shift_summaries_are_owner_and_site_scoped_and_omit_clinical_details(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['name' => 'Foreign secret site', 'is_active' => true, 'archived' => false]);
        $user = $this->actor('support_worker', $site);
        $otherUser = $this->actor('support_worker', $site);
        $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Clinical secret name']);
        foreach ([[$site, $user], [$site, $otherUser], [$other, $user]] as [$shiftSite,$staff]) {
            Shift::factory()->published()->assignedToday($staff)->create(['site_id' => $shiftSite->id,
                'client_id' => $shiftSite->is($site) ? $client->id : Client::factory()->create(['site_id' => $other->id])->id,
                'notes' => 'Private clinical note']);
        }
        $response = $this->actingAs($user)->getJson('/dashboard/data?role=worker&range=today')->assertOk()
            ->assertJsonPath('partial', false)->assertJsonPath('blocks.trend.total', 1)
            ->assertJsonPath('blocks.trend.calendar.0.count', 1)
            ->assertJsonPath('blocks.sites.presentation', 'links')
            ->assertJsonPath('blocks.sites.scopeMode', 'personal');
        $this->assertCount(7, $response->json('blocks.trend.calendar'));
        $response->assertDontSee('Foreign secret site')->assertDontSee('Clinical secret name')->assertDontSee('Private clinical note');
    }

    public function test_layout_saves_are_private_and_detect_concurrent_edits(): void
    {
        $user = $this->actor();
        $other = $this->actor();
        $value = $this->layout();
        $saved = $this->actingAs($user)->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertOk();
        $revision = $saved->json('revision');
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertConflict();
        $value['blocks'][0]['span'] = 12;
        $this->putJson('/dashboard/layout/worker', ['revision' => $revision, 'value' => $value])->assertOk();
        $this->actingAs($other)->get('/dashboard')->assertInertia(fn ($page) => $page->where('preference.value.blocks', null));
        $this->assertSame(12, $user->uiPreferences()->where('key', 'overview.workspace.worker')->sole()->value['blocks'][0]['span']);
    }

    public function test_worker_progress_counts_only_visible_own_tasks_and_includes_overnight_work(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->actor('support_worker', $site);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $client->supportWorkers()->attach($user->id);
        $hidden = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->published()->assignedToday($user)->create(['site_id' => $site->id, 'client_id' => $client->id]);
        foreach ([[true, null], [false, null], [false, 'requested']] as [$done, $help]) {
            $shift->tasks()->create(['label' => 'Private task detail', 'task_scope' => 'site', 'is_completed' => $done, 'help_status' => $help]);
        }
        $shift->tasks()->create(['label' => 'Hidden client task', 'task_scope' => 'client', 'client_id' => $hidden->id, 'is_completed' => false]);
        $night = Shift::factory()->published()->create(['user_id' => $user->id, 'site_id' => $site->id, 'client_id' => $client->id,
            'starts_at' => now()->subHours(12), 'ends_at' => now()->subHours(3)]);
        $night->tasks()->create(['label' => 'Night task', 'task_scope' => 'site', 'is_completed' => true]);
        Timesheet::factory()->create(['shift_id' => $night->id, 'user_id' => $user->id, 'client_id' => $client->id, 'site_id' => $site->id,
            'starts_at' => $night->starts_at, 'ends_at' => $night->ends_at, 'work_date' => $night->starts_at->toDateString(), 'status' => 'returned']);
        foreach ([[$user, 'cancelled'], [$this->actor('support_worker', $site), 'scheduled']] as [$staff, $status]) {
            $excluded = Shift::factory()->published()->assignedToday($staff)->create(['site_id' => $site->id, 'client_id' => $client->id, 'status' => $status]);
            $excluded->tasks()->create(['label' => 'Excluded task', 'task_scope' => 'site', 'is_completed' => false]);
        }
        $future = Shift::factory()->published()->create(['user_id' => $user->id, 'site_id' => $site->id, 'client_id' => $client->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(4)]);
        $future->tasks()->create(['label' => 'Future task', 'task_scope' => 'site', 'is_completed' => false]);

        $this->actingAs($user)->getJson('/dashboard/data?role=worker&range=today')->assertOk()->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.distribution.title', 'Your shift progress')->assertJsonPath('blocks.distribution.total', 4)
            ->assertJsonPath('blocks.distribution.rows.0.value', 2)->assertJsonPath('blocks.distribution.rows.1.value', 1)->assertJsonPath('blocks.distribution.rows.2.value', 1)
            ->assertJsonPath('blocks.focus.title', 'Your current shift')->assertJsonPath('blocks.focus.focusTag', 'Rostered now')
            ->assertJsonPath('metrics.0.display', '2 of 4')->assertJsonPath('metrics.0.percent', 50)
            ->assertJsonPath('metrics.2.display', '8h')->assertJsonPath('metrics.3.label', 'Timesheet')->assertJsonPath('metrics.3.value', 1)
            ->assertJsonPath('blocks.trend.calendar.0.hours', 4)->assertDontSee('Private task detail')->assertDontSee('Hidden client task');
    }

    public function test_manager_delivery_comparison_uses_only_the_selected_site_records(): void
    {
        $this->travelTo(now()->setTimezone('Pacific/Auckland')->startOfDay()->addHours(18)->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->actor('admin', $site);
        foreach ([[$site, 'completed'], [$site, 'scheduled'], [$site, 'cancelled'], [$other, 'completed']] as [$shiftSite, $status]) {
            Shift::factory()->published()->assignedToday($user)->create(['site_id' => $shiftSite->id, 'status' => $status,
                'client_id' => Client::factory()->create(['site_id' => $shiftSite->id])->id,
                'actual_starts_at' => $status === 'completed' ? now()->subHour() : null,
                'actual_ends_at' => $status === 'completed' ? now() : null]);
        }
        Shift::factory()->published()->create(['user_id' => $user->id, 'site_id' => $site->id, 'status' => 'scheduled',
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
        $response = $this->actingAs($user)->getJson('/dashboard/data?role=manager&range=today&site='.$site->id)->assertOk()
            ->assertJsonPath('partial', false)
            ->assertJsonPath('blocks.focus.title', 'Your decision queue')
            ->assertJsonPath('blocks.trend.seriesLabel', 'Delivered')
            ->assertJsonPath('blocks.trend.comparisonLabel', 'Planned')
            ->assertJsonPath('blocks.trend.unit', '%')
            ->assertJsonPath('blocks.trend.total', 2)
            ->assertJsonPath('blocks.trend.series.0.value', 50)
            ->assertJsonPath('blocks.trend.series.0.comparison', 100)
            ->assertJsonPath('metrics.0.label', 'Service delivery')
            ->assertJsonPath('metrics.0.display', '50%')
            ->assertJsonPath('metrics.2.label', 'Sites in view')
            ->assertJsonPath('blocks.sites.rows.0.value', 1)
            ->assertJsonPath('blocks.sites.rows.0.target', 2);
        $this->assertCount(1, $response->json('blocks.sites.rows'));
    }

    public function test_manager_delivery_without_ended_shifts_is_unknown_not_zero_or_complete(): void
    {
        $this->actingAs($this->actor('admin'))->getJson('/dashboard/data?role=manager&range=today')->assertOk()
            ->assertJsonPath('metrics.0.value', null)
            ->assertJsonPath('metrics.0.display', '—')
            ->assertJsonPath('blocks.trend.series.0.value', null)
            ->assertJsonPath('blocks.trend.series.0.comparison', null);
    }

    public function test_saved_copies_are_bounded_unique_and_role_specific(): void
    {
        $this->actingAs($this->actor());
        $value = $this->layout('hr-probation');
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertUnprocessable();
        $value = $this->layout();
        $value['copies'] = array_fill(0, 4, ['name' => 'One', 'blocks' => $value['blocks'], 'density' => 'compact']);
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertUnprocessable();
        $value['copies'] = array_slice($value['copies'], 0, 2);
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertUnprocessable();
        $value['copies'][1]['name'] = 'Two';
        $this->putJson('/dashboard/layout/worker', ['revision' => null, 'value' => $value])->assertOk();
    }

    public function test_revoked_sites_and_malformed_generic_preferences_cannot_restore_access(): void
    {
        $user = $this->actor();
        $value = $this->layout();
        $value['blocks'][0]['site'] = '999999';
        $value['blocks'][] = ['id' => 'private-secret', 'span' => 12];
        $user->uiPreferences()->create(['key' => 'overview.workspace.worker', 'value' => $value]);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->has('preference.value.blocks', 1)->where('preference.value.blocks.0.site', 'page'));
    }

    public function test_default_role_is_permitted_and_remembered(): void
    {
        $this->actingAs($this->actor('admin'))->putJson('/dashboard/default-role', ['role' => 'hr'])->assertOk();
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('role', 'hr'));
        $this->actingAs($this->actor())->putJson('/dashboard/default-role', ['role' => 'hr'])->assertUnprocessable();
    }

    public function test_malformed_filters_do_not_run_summary_queries(): void
    {
        $this->actingAs($this->actor())->getJson('/dashboard/data?range=forever')->assertUnprocessable();
        $this->getJson('/dashboard/data?site[]=1')->assertUnprocessable();
    }
}
