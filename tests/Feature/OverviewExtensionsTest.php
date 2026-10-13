<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Roadmap\Models\Initiative;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteFeedback;
use App\Models\User;
use App\Models\WorkPlanningRecord;
use App\Services\Overview\OverviewAccess;
use App\Services\Overview\OverviewConnectedBlocks;
use App\Services\Overview\OverviewPlanDefinitions;
use App\Services\Overview\OverviewPlans;
use Carbon\CarbonImmutable;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OverviewExtensionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, OperationsPermissionsSeeder::class]);
    }

    private function person(string $role, Site $site, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::create(['user_id' => $user->id, 'employee_number' => 'PLAN-'.$user->id, 'work_email' => $user->email,
            'position_title' => 'Planning test', 'position_role' => 'staff', 'employment_type' => 'full_time', 'start_date' => today()->subMonth(),
            'is_active' => true, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach ($permissions as $key) {
            $p = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$p->id => ['allowed' => true]]);
        }

        return $user->fresh();
    }

    private function administrator(Site $site): User
    {
        $permissions = ['hr.analytics.view', 'governance.view', 'it.view', 'it.manage', 'operations.dashboard.view', 'operations.dashboard.viewAllSites', 'sites.update', 'sites.viewAny', 'sites.viewAll', 'hr.employees.viewAllSites', 'hr.assets.view', 'hr.assets.viewAllSites', 'hr.performance.view', 'hr.performance.manage', 'hr.wellbeing.view', 'hr.payroll.view', 'roadmap.view', 'roadmap.decisions.view', 'governance.actions.view', 'governance.actions.manage', 'checklists.view', 'checklists.schedule', 'care_plans.viewAny', 'clients.viewAny', 'fleet.viewAny', 'assets.viewAny', 'progress_notes.viewAny', 'shifts.viewAny', 'shifts.manageAny', 'hr.employees.viewAny', 'hr.employees.manage'];

        return $this->person('admin', $site, $permissions);
    }

    private function payload(User $owner, array $definition, ?int $site): array
    {
        $details = [];
        foreach ($definition['fields'] as $key => $label) {
            $details[$key] = in_array($key, $definition['numbers'] ?? [], true) ? 2 : 'Recorded planning detail';
        }

        return ['title' => 'A useful plan', 'due_date' => today()->addDay()->toDateString(), 'status' => 'planned', 'progress' => 0,
            'owner_id' => $owner->id, 'site_id' => $definition['scope'] === 'site' ? $site : null, 'details' => $details, 'completion_note' => null];
    }

    public function test_all_six_roles_resolve_every_library_block_without_proposals_or_errors(): void
    {
        $site = Site::factory()->create();
        $user = $this->administrator($site);
        foreach (OverviewAccess::ROLES as $role) {
            $response = $this->actingAs($user)->getJson('/dashboard/data?role='.$role)->assertOk();
            foreach ($response->json('blocks') as $id => $block) {
                $this->assertContains($block['state'], ['ready', 'restricted'], $role.' / '.$id.' failed: '.json_encode($block));
            }
        }
        $this->assertCount(21, OverviewConnectedBlocks::BLOCKS);
        $this->assertCount(18, OverviewPlanDefinitions::all());
    }

    public function test_every_planning_type_can_be_created_completed_reopened_and_reloaded(): void
    {
        $site = Site::factory()->create();
        $user = $this->administrator($site);
        $this->actingAs($user);
        foreach (OverviewPlanDefinitions::all() as $kind => $definition) {
            $data = $this->payload($user, $definition, $site->id);
            $this->post('/work-plans/'.$kind, $data)->assertSessionHasNoErrors()->assertRedirect();
            $record = WorkPlanningRecord::where('kind', $kind)->sole();
            $this->get('/work-plans/'.$kind.'?record='.$record->id)->assertOk();
            $summary = app(OverviewPlans::class)->summary($user, $kind, [$site->id]);
            $this->assertSame('ready', $summary['state']);
            $this->assertSame(1, $summary['total'], $kind);
            $finished = [...$data, 'status' => 'completed', 'progress' => 50, 'completion_note' => 'Completed with recorded evidence', 'version' => 1];
            $this->put('/work-plans/'.$kind.'/'.$record->id, $finished)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame(100, $record->fresh()->progress);
            $this->assertNotNull($record->fresh()->completed_at);
            $this->assertSame($kind === 'it-licences' ? 1 : 0, app(OverviewPlans::class)->summary($user, $kind, [$site->id])['total']);
            $this->put('/work-plans/'.$kind.'/'.$record->id, [...$data, 'status' => 'in_progress', 'progress' => 25, 'version' => 2])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertNull($record->fresh()->completed_at);
            $this->assertSame(3, DB::table('work_planning_revisions')->where('record_id', $record->id)->count());
        }
    }

    public function test_overdue_and_blocked_plans_feed_the_six_role_priority_queues_once(): void
    {
        $site = Site::factory()->create();
        $user = $this->administrator($site);
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        foreach (OverviewPlanDefinitions::all() as $kind => $definition) {
            foreach ([['blocked', -2], ['blocked', 2], ['planned', 0], ['completed', -2], ['cancelled', -2]] as [$status, $days]) {
                WorkPlanningRecord::create([...$this->payload($user, $definition, $site->id), 'kind' => $kind, 'created_by' => $user->id,
                    'status' => $status, 'due_date' => $today->addDays($days)->toDateString()]);
            }
        }
        $this->actingAs($user);
        foreach (OverviewAccess::ROLES as $role) {
            $response = $this->getJson('/dashboard/data?role='.$role.'&range=today&site='.$site->id)->assertOk();
            $rows = collect($response->json('blocks.attention.rows'));
            foreach (OverviewPlanDefinitions::all() as $kind => $definition) {
                if ($definition['role'] !== $role) {
                    continue;
                }
                $row = $rows->first(fn ($row) => str_starts_with($row['href'], '/work-plans/'.$kind.'?'));
                $this->assertNotNull($row, $kind.' must appear without requiring its optional block');
                $this->assertSame(2, $row['value'], $kind.' counts each open overdue or blocked record once');
                $this->get($row['href'])->assertOk()->assertInertia(fn ($page) => $page
                    ->where('filters.status', 'attention')->where('records.total', 2));
            }
        }
    }

    public function test_planning_priorities_respect_ownership_sites_and_read_permissions(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $worker = $this->person('support_worker', $site);
        $other = $this->person('support_worker', $site);
        $lead = $this->person('team_lead', $site, ['shifts.manageAny']);
        $service = app(OverviewPlans::class);
        foreach ([$worker, $other] as $owner) {
            WorkPlanningRecord::create([...$this->payload($owner, OverviewPlanDefinitions::all()['worker-reminders'], null),
                'kind' => 'worker-reminders', 'created_by' => $owner->id, 'due_date' => today()->subDay()]);
        }
        $this->assertSame(1, $service->attention($worker, 'worker-reminders', [$site->id])['value']);
        foreach ([$site, $foreign] as $location) {
            WorkPlanningRecord::create([...$this->payload($lead, OverviewPlanDefinitions::all()['lead-break-cover'], $location->id),
                'kind' => 'lead-break-cover', 'created_by' => $lead->id, 'due_date' => today()->subDay()]);
        }
        $this->assertSame(1, $service->attention($lead, 'lead-break-cover', [$site->id, $foreign->id])['value']);
        $this->assertNull($service->attention($lead, 'lead-break-cover', [$foreign->id]));
        $this->assertNull($service->attention($worker, 'lead-break-cover', [$site->id]));
        $this->actingAs($lead)->get('/work-plans/lead-break-cover?status=attention')->assertOk()
            ->assertInertia(fn ($page) => $page->where('records.total', 1)->where('records.data.0.site_id', $site->id));
    }

    public function test_personal_records_and_revision_history_never_leak_to_another_worker(): void
    {
        $site = Site::factory()->create();
        $owner = $this->person('support_worker', $site);
        $other = $this->person('support_worker', $site);
        $kind = 'worker-reminders';
        $data = $this->payload($owner, OverviewPlanDefinitions::all()[$kind], null);
        $this->actingAs($owner)->post('/work-plans/'.$kind, $data)->assertSessionHasNoErrors();
        $record = WorkPlanningRecord::sole();
        $this->actingAs($other)->get('/work-plans/'.$kind.'?record='.$record->id)->assertNotFound();
        $this->put('/work-plans/'.$kind.'/'.$record->id, [...$data, 'version' => 1])->assertNotFound();
        $this->assertSame(0, app(OverviewPlans::class)->summary($other, $kind, [$site->id])['total']);
        $this->postJson('/work-plans/'.$kind, $data)->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }

    public function test_site_scope_role_and_owner_forgery_are_rejected(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $lead = $this->person('team_lead', $site, ['shifts.manageAny']);
        $other = $this->person('team_lead', $foreign, ['shifts.manageAny']);
        $worker = $this->person('support_worker', $site);
        $kind = 'lead-break-cover';
        $data = $this->payload($lead, OverviewPlanDefinitions::all()[$kind], $site->id);
        $this->actingAs($lead)->post('/work-plans/'.$kind, $data)->assertSessionHasNoErrors();
        $record = WorkPlanningRecord::sole();
        $this->postJson('/work-plans/'.$kind, [...$data, 'site_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $this->postJson('/work-plans/'.$kind, [...$data, 'owner_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $this->actingAs($other)->get('/work-plans/'.$kind.'?record='.$record->id)->assertNotFound();
        $this->put('/work-plans/'.$kind.'/'.$record->id, [...$data, 'version' => 1])->assertNotFound();
        $this->actingAs($worker)->post('/work-plans/'.$kind, $data)->assertForbidden();
        $this->get('/work-plans/'.$kind)->assertForbidden();
    }

    public function test_completion_validation_and_concurrent_edit_protection_preserve_the_saved_record(): void
    {
        $user = $this->person('support_worker', Site::factory()->create());
        $kind = 'worker-readiness';
        $data = $this->payload($user, OverviewPlanDefinitions::all()[$kind], null);
        $this->actingAs($user)->post('/work-plans/'.$kind, $data)->assertSessionHasNoErrors();
        $record = WorkPlanningRecord::sole();
        $url = '/work-plans/'.$kind.'/'.$record->id;
        $this->putJson($url, [...$data, 'status' => 'completed', 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('completion_note');
        $this->put($url, [...$data, 'title' => 'Saved by another window', 'version' => 1])->assertSessionHasNoErrors();
        $this->putJson($url, [...$data, 'title' => 'Stale draft', 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('Saved by another window', $record->fresh()->title);
        $this->assertSame(2, DB::table('work_planning_revisions')->count());
        $this->postJson('/work-plans/'.$kind, [...$data, 'details' => ['secret' => 'unexpected field']])->assertUnprocessable()->assertJsonValidationErrors('details');
    }

    public function test_it_read_permission_does_not_grant_plan_editing(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->person('support_worker', $site, ['it.view']);
        $kind = 'it-backups';
        $this->actingAs($viewer)->get('/work-plans/'.$kind)->assertOk();
        $this->post('/work-plans/'.$kind, $this->payload($viewer, OverviewPlanDefinitions::all()[$kind], $site->id))->assertForbidden();
    }

    public function test_care_plan_updates_are_assigned_and_never_return_plan_content(): void
    {
        $site = Site::factory()->create();
        $user = $this->person('support_worker', $site, ['care_plans.viewAny']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $client->supportWorkers()->attach($user->id);
        $otherClient = Client::factory()->create(['site_id' => $site->id]);
        foreach ([$client, $otherClient] as $subject) {
            CarePlan::create(['client_id' => $subject->id, 'title' => 'Private plan title', 'plan_type' => 'support', 'content' => ['private' => 'PRIVATE CARE CONTENT'], 'status' => 'active', 'version' => 1]);
        }
        $data = app(OverviewConnectedBlocks::class)->read('worker-plan-updates', $user, [$site->id], CarbonImmutable::today(), CarbonImmutable::tomorrow());
        $this->assertSame(1, $data['total']);
        $this->assertStringNotContainsString('PRIVATE CARE CONTENT', json_encode($data));
        $this->assertStringNotContainsString('Private plan title', json_encode($data));
        $this->assertStringContainsString('/operations/care-plans/', $data['rows'][0]['href']);
    }

    public function test_feedback_and_roadmap_summaries_exclude_other_sites_and_private_or_financial_fields(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $user = $this->administrator($site);
        foreach ([$site, $foreign] as $location) {
            SiteFeedback::create(['site_id' => $location->id, 'feedback_type' => 'complaint', 'content' => 'PRIVATE FEEDBACK', 'submitted_by_name' => 'PRIVATE RESPONDENT', 'status' => 'new']);
            $initiative = Initiative::create(['code' => 'OV-'.$location->id, 'title' => 'Improvement at '.$location->id, 'status' => 'in_progress', 'summary' => 'PRIVATE BUSINESS DETAILS', 'cost_estimate_low' => 918273]);
            $scope = $initiative->siteScope()->create(['scope_type' => 'selected']);
            $scope->sites()->create(['site_id' => $location->id]);
        }
        $provider = app(OverviewConnectedBlocks::class);
        $start = CarbonImmutable::today();
        $feedback = $provider->read('manager-feedback', $user, [$site->id], $start, $start->addDay());
        $roadmap = $provider->read('manager-improvements', $user, [$site->id], $start, $start->addDay());
        $this->assertSame(1, $feedback['total']);
        $this->assertSame(1, $roadmap['total']);
        $this->assertSame('Improvement at '.$site->id, $roadmap['rows'][0]['label']);
        $this->assertStringNotContainsString('PRIVATE', json_encode([$feedback, $roadmap]));
        $this->assertStringNotContainsString('918273', json_encode($roadmap));
        $foreignScope = Initiative::where('title', 'Improvement at '.$site->id)->sole()->siteScope()->first();
        $foreignScope->sites()->create(['site_id' => $foreign->id]);
        $this->assertSame(0, $provider->read('manager-improvements', $user, [$site->id], $start, $start->addDay())['total']);
    }

    public function test_reassignment_is_scoped_and_licence_seat_totals_do_not_use_task_progress(): void
    {
        $site = Site::factory()->create();
        $user = $this->administrator($site);
        $kind = 'it-licences';
        $data = $this->payload($user, OverviewPlanDefinitions::all()[$kind], $site->id);
        $data['details'] = ['product' => 'Example software', 'entitled' => 10, 'assigned' => 12, 'review' => 'Inventory review'];
        $this->actingAs($user)->post('/work-plans/'.$kind, $data)->assertSessionHasNoErrors();
        $summary = app(OverviewPlans::class)->summary($user, $kind, [$site->id]);
        $this->assertSame(12, $summary['rows'][0]['value']);
        $this->assertSame(10, $summary['rows'][0]['target']);
        $this->assertSame('Over allocation', $summary['rows'][0]['status']);
        $this->get($summary['href'])->assertOk();
        $record = WorkPlanningRecord::sole();
        $other = $this->person('support_worker', $site, ['it.view', 'it.manage']);
        $this->put('/work-plans/'.$kind.'/'.$record->id, [...$data, 'owner_id' => $other->id, 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $record->fresh()->owner_id);
        $this->put('/work-plans/'.$kind.'/'.$record->id, [...$data, 'owner_id' => $other->id, 'status' => 'cancelled', 'version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(0, app(OverviewPlans::class)->summary($user, $kind, [$site->id])['total']);
    }
}
