<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Sites\HazardWorkspaceConnectionsService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class HazardWorkspaceConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['type' => 'house']);
    }

    public function test_detail_projects_existing_domains_without_copying_or_completing_local_controls(): void
    {
        $hazard = $this->hazard();
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id,
            'source' => 'manual', 'alert_type' => 'Hazard identified', 'status' => 'resolved',
            'notes' => 'Private operational narrative', 'context' => ['private_path' => 'private/alert/file.pdf']]);
        $event = $this->review($hazard, ['control_room_alert_id' => $alert->id, 'status' => 'monitoring', 'owner_user_id' => $this->admin->id]);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id,
            'status' => 'completed', 'title' => 'Verify floor repair', 'due_date' => '2026-10-15',
            'assigned_to_user_id' => $this->admin->id]);
        $local = SiteHazardAction::create(['hazard_id' => $hazard->id, 'reference_number' => 'HACT-LOCAL',
            'action_type' => 'eliminate', 'action_description' => 'Remove loose mat', 'status' => 'pending']);
        $response = $this->actingAs($this->admin)->get('/hazards/'.$hazard->id)->assertOk();
        $detail = data_get($response->viewData('page'), 'props.detail');
        $this->assertSame([$local->id], array_column($detail['actions'], 'id'));
        $this->assertSame($event->id, $detail['connections']['safety_reviews'][0]['id']);
        $this->assertSame($action->id, $detail['connections']['corrective_actions'][0]['id']);
        $this->assertSame('Awaiting independent verification', $detail['connections']['corrective_actions'][0]['display_state']);
        $this->assertSame('2026-10-15', $detail['connections']['corrective_actions'][0]['due_date']);
        $this->assertSame('resolved', $detail['connections']['control_room'][0]['status']);
        $this->assertStringContainsString('return_to=%2Fhazards%2F'.$hazard->id, $detail['connections']['control_room'][0]['href']);
        $alertResponse = $this->get($detail['connections']['control_room'][0]['href'])->assertOk();
        $this->assertSame('/hazards/'.$hazard->id, data_get($alertResponse->viewData('page'), 'props.return_to'));
        $this->assertSame(['hazard-'.$hazard->id, 'hs_event-'.$event->id, 'corrective_action-'.$action->id], array_column($detail['connections']['tasks'], 'id'));
        $this->assertStringNotContainsString('Private operational narrative', json_encode($detail['connections']));
        $this->assertStringNotContainsString('private/alert/file.pdf', json_encode($detail['connections']));
        $this->assertSame('pending', $local->fresh()->status);
        $this->assertSame('open', $hazard->fresh()->status);
        $this->assertSame('completed', $action->fresh()->status);
        $this->assertFalse($detail['close_gate']['actions_ok']);
    }

    public function test_hazard_only_reader_sees_review_and_tasks_without_unrelated_site_or_control_room_capabilities(): void
    {
        $hazard = $this->hazard();
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id]);
        $event = $this->review($hazard, ['control_room_alert_id' => $alert->id]);
        $this->inspection($this->site, $hazard);
        $viewer = $this->reader($this->site, ['hazards.view']);
        $response = $this->actingAs($viewer)->get('/hazards/'.$hazard->id)->assertOk();
        $connections = data_get($response->viewData('page'), 'props.detail.connections');
        $this->assertSame([$event->id], array_column($connections['safety_reviews'], 'id'));
        $this->assertSame([], $connections['control_room']);
        $this->assertSame([], $connections['sources']);
        $this->assertCount(2, $connections['tasks']);
        $this->actingAs($viewer)->get('/control-room/alerts/'.$alert->id)->assertForbidden();
    }

    public function test_source_identity_and_site_agreement_conceal_forged_review_action_and_alert_links(): void
    {
        $hazard = $this->hazard();
        $otherSite = Site::factory()->create(['type' => 'house']);
        $otherHazard = $this->hazard(['site_id' => $otherSite->id]);
        $wrongSource = $this->review($otherHazard);
        $wrongSite = $this->review($hazard, ['site_id' => $otherSite->id]);
        HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $wrongSite->id, 'title' => 'Hidden repair']);
        $foreignAlert = ControlRoomAlert::factory()->create(['site_id' => $otherSite->id]);
        $valid = $this->review($hazard, ['control_room_alert_id' => $foreignAlert->id]);
        $connections = app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->admin);
        $this->assertSame([$valid->id], array_column($connections['safety_reviews'], 'id'));
        $this->assertNotContains($wrongSource->id, array_column($connections['safety_reviews'], 'id'));
        $this->assertSame([], $connections['control_room']);
        $this->assertSame([], $connections['corrective_actions']);
        $this->assertCount(2, $connections['tasks']);
        $restricted = $this->reader($this->site, ['hazards.view']);
        $this->assertSame([], app(HazardWorkspaceConnectionsService::class)->forViewer($otherHazard, $restricted)['safety_reviews']);
        $this->actingAs($restricted)->get('/hazards/'.$otherHazard->id)->assertNotFound();
    }

    public function test_inspection_projection_requires_destination_permissions_and_matching_schedule_site(): void
    {
        $hazard = $this->hazard();
        $record = $this->inspection($this->site, $hazard);
        $viewer = $this->reader($this->site, ['hazards.view', 'checklists.view', 'sites.viewAny']);
        $connections = app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $viewer);
        $this->assertSame([['type' => 'inspection', 'id' => $record->id, 'title' => 'Floor safety inspection',
            'status' => 'fail', 'occurred_at' => $record->completed_at->toISOString(),
            'href' => '/sites/'.$this->site->id.'/inspections']], $connections['sources']);
        $this->actingAs($viewer)->get($connections['sources'][0]['href'])->assertOk();
        $foreignSite = Site::factory()->create(['type' => 'house']);
        $foreign = $this->inspection($foreignSite, $hazard);
        $record->updateQuietly(['schedule_id' => $foreign->schedule_id]);
        $hazard->updateQuietly(['linked_inspection_id' => $foreign->id]);
        $this->assertSame([], app(HazardWorkspaceConnectionsService::class)->forViewer($hazard->fresh(), $viewer)['sources']);
    }

    public function test_conflicting_reciprocal_inspection_link_is_not_treated_as_the_hazards_source(): void
    {
        $hazard = $this->hazard();
        $other = $this->hazard();
        $record = $this->inspection($this->site, $other);
        $hazard->updateQuietly(['linked_inspection_id' => $record->id]);
        $this->assertSame([], app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->admin)['sources']);
    }

    public function test_checklist_projection_rechecks_canonical_execution_provenance(): void
    {
        $hazard = $this->hazard();
        $template = SiteChecklistTemplate::create(['key' => 'hazard-opening-checks', 'name' => 'Opening checks', 'category' => 'safety', 'applicable_to_type' => 'house', 'frequency' => 'daily']);
        $assignment = SiteChecklistAssignment::create(['site_id' => $this->site->id, 'template_id' => $template->id, 'frequency' => 'daily', 'start_date' => '2026-10-01']);
        $run = SiteChecklistRun::createQuietly(['site_id' => $this->site->id, 'template_id' => $template->id,
            'assignment_id' => $assignment->id, 'scheduled_date' => '2026-10-08', 'status' => 'in_progress']);
        $hazard->updateQuietly(['linked_checklist_run_id' => $run->id]);
        $connections = app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->admin);
        $this->assertSame('checklist', $connections['sources'][0]['type']);
        $this->actingAs($this->admin)->get($connections['sources'][0]['href'])->assertRedirect('/sites/'.$this->site->id.'/checklists?run='.$run->id);
        $assignment->update(['site_id' => Site::factory()->create()->id]);
        $this->assertSame([], app(HazardWorkspaceConnectionsService::class)->forViewer($hazard->fresh(), $this->admin)['sources']);
    }

    public function test_connection_access_is_rechecked_after_permission_revocation(): void
    {
        $hazard = $this->hazard();
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id]);
        $this->review($hazard, ['control_room_alert_id' => $alert->id]);
        $viewer = $this->reader($this->site, ['hazards.view', 'controlRoom.alerts.view']);
        $this->assertCount(1, app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $viewer)['control_room']);
        $viewer->permissionOverrides()->sync([Permission::where('key', 'hazards.view')->firstOrFail()->id => ['allowed' => true]]);
        $this->assertSame([], app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $viewer->fresh())['control_room']);
    }

    public function test_history_keeps_status_snapshots_and_records_evidence_and_due_date_changes(): void
    {
        $hazard = $this->hazard(['control_hierarchy' => ['ppe'], 'residual_risk_rating' => 'high', 'resolution_summary' => 'Later summary']);
        foreach ([
            ['hazard.status_changed', ['to' => 'mitigated', 'control_hierarchy' => ['eliminate'], 'residual_risk_rating' => 'low']],
            ['hazard.status_changed', ['to' => 'closed', 'resolution_summary' => 'Original closure']],
            ['hazard.evidence_added', ['kind' => 'document', 'names' => ['verification.pdf'], 'count' => 1]],
            ['hazard.due_date_changed', ['from' => '2026-10-08', 'to' => '2026-10-15']],
            ['hazard.progress_note', ['note' => 'Repair booked']],
        ] as [$action, $meta]) {
            AuditLog::create(['auditable_type' => $hazard->getMorphClass(), 'auditable_id' => $hazard->id,
                'action' => $action, 'user_id' => $this->admin->id, 'meta' => $meta]);
        }
        $response = $this->actingAs($this->admin)->get('/hazards/'.$hazard->id)->assertOk();
        $notes = array_column(data_get($response->viewData('page'), 'props.detail.history'), 'note', 'title');
        $this->assertSame('Controls: Eliminate · Residual: Low', $notes['Marked Mitigated']);
        $this->assertSame('Original closure', $notes['Hazard closed']);
        $this->assertSame('verification.pdf', $notes['Evidence added']);
        $this->assertSame('Due 2026-10-15', $notes['Due date changed']);
        $this->assertSame('Repair booked', $notes['Progress note recorded']);
    }

    public function test_history_never_substitutes_later_controls_closure_or_assignee_for_unrecorded_history(): void
    {
        $hazard = $this->hazard(['control_hierarchy' => ['ppe'], 'residual_risk_rating' => 'high', 'resolution_summary' => 'Later closure']);
        $owner = User::factory()->create(['approved_at' => now()]);
        $recorded = SiteHazardAction::create(['hazard_id' => $hazard->id, 'reference_number' => 'HACT-RECORDED',
            'action_description' => 'Original action', 'status' => 'pending', 'assigned_to_user_id' => $this->admin->id]);
        $legacy = SiteHazardAction::create(['hazard_id' => $hazard->id, 'reference_number' => 'HACT-LEGACY',
            'action_description' => 'Legacy action', 'status' => 'pending', 'assigned_to_user_id' => $this->admin->id]);
        AuditLog::create(['auditable_type' => $hazard->getMorphClass(), 'auditable_id' => $hazard->id,
            'action' => 'hazard.action_added', 'user_id' => $this->admin->id, 'meta' => [
                'action_id' => $recorded->id, 'reference_number' => $recorded->reference_number, 'action_description' => 'Original action',
            ]]);
        $recorded->updateQuietly(['assigned_to_user_id' => $owner->id, 'action_description' => 'Later action wording']);
        foreach (['mitigated', 'closed'] as $status) {
            AuditLog::create(['auditable_type' => $hazard->getMorphClass(), 'auditable_id' => $hazard->id,
                'action' => 'hazard.status_changed', 'user_id' => $this->admin->id, 'meta' => ['to' => $status]]);
        }
        $response = $this->actingAs($this->admin)->get('/hazards/'.$hazard->id)->assertOk();
        $history = collect(data_get($response->viewData('page'), 'props.detail.history'));
        $created = $history->where('type', 'action_added');
        $this->assertCount(2, $created);
        $this->assertSame($this->admin->name, $created->firstWhere('note', 'HACT-RECORDED · Original action')['actor']);
        $this->assertNull($created->firstWhere('note', 'HACT-LEGACY · Legacy action')['actor']);
        $this->assertNull($history->firstWhere('type', 'mitigated')['note']);
        $this->assertNull($history->firstWhere('type', 'closed')['note']);
    }

    public function test_complete_hazard_scoped_connections_are_not_silently_truncated(): void
    {
        $hazard = $this->hazard();
        $events = [];
        for ($i = 0; $i < 21; $i++) {
            $events[] = $this->review($hazard);
            $this->inspection($this->site, $hazard);
        }
        HsCorrectiveAction::factory()->count(51)->createQuietly(['hs_event_id' => $events[0]->id]);
        $connections = app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->admin);
        $this->assertCount(21, $connections['safety_reviews']);
        $this->assertCount(21, $connections['sources']);
        $this->assertCount(51, $connections['corrective_actions']);
        $this->assertCount(73, $connections['tasks']);
    }

    public function test_task_return_link_preserves_existing_query_fragment_and_uses_canonical_due_date(): void
    {
        $hazard = $this->hazard(['due_date' => '2026-10-15']);
        $event = $this->review($hazard);
        $item = new TaskItem(id: 'hs_event-'.$event->id, source: 'hs_event', sourceLabel: 'Safety reviews',
            ref: null, title: 'Hazard review', status: 'open', bucket: 'open', severity: 'low',
            dueAt: '2026-10-14T10:00:00Z', link: '/health-safety/events/'.$event->id.'?section=actions#review');
        $tasks = $this->mock(TaskAggregator::class);
        $tasks->shouldReceive('findItemFor')->with(Mockery::on(fn ($user) => $user instanceof User && $user->id === $this->admin->id), 'hazard', $hazard->id, ['include_done' => true])->andReturn(null);
        $tasks->shouldReceive('findItemFor')->with(Mockery::on(fn ($user) => $user instanceof User && $user->id === $this->admin->id), 'hs_event', $event->id, ['include_done' => true], Mockery::on(fn ($context) => $context instanceof HealthSafetyReadContext))->andReturn($item);
        $connections = app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->admin);
        $this->assertSame('/health-safety/events/'.$event->id.'?section=actions&return_to=%2Fhazards%2F'.$hazard->id.'#review', $connections['tasks'][0]['href']);
        $this->assertNull($connections['tasks'][0]['reference_number']);
        // H&S reviews own no due date; a provider instant cannot fabricate one.
        $this->assertNull($connections['tasks'][0]['due_date']);
    }

    public function test_warmed_module_grant_revocation_and_restore_are_fresh_before_source_discovery(): void
    {
        $hazard = $this->hazard();
        $event = $this->review($hazard);
        $viewer = $this->reader($this->site, ['hazards.view']);
        $service = app(HazardWorkspaceConnectionsService::class);
        $this->assertSame([$event->id], array_column($service->forViewer($hazard, $viewer)['safety_reviews'], 'id'));
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $permission = Permission::where('key', 'hazards.view')->sole();
        $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $hidden = $service->forViewer($hazard, $viewer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $hidden['safety_reviews']);
        $this->assertSame([], $hidden['tasks']);
        $this->assertNull($hidden['tasks_href']);
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?(?:hs_events|first_aid_records|restraint_events|workplace_injuries)[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->assertSame([$event->id], array_column($service->forViewer($hazard, $viewer)['safety_reviews'], 'id'));
    }

    public function test_loaded_hazard_and_profile_cannot_retain_a_former_site_connection(): void
    {
        $hazard = $this->hazard();
        $event = $this->review($hazard);
        $viewer = $this->reader($this->site, ['hazards.view']);
        $service = app(HazardWorkspaceConnectionsService::class);
        $this->assertSame([$event->id], array_column($service->forViewer($hazard, $viewer)['safety_reviews'], 'id'));
        $hazard->load('site');
        $destination = Site::factory()->create(['type' => 'house']);
        SiteHazard::whereKey($hazard->id)->update(['site_id' => $destination->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $hidden = $service->forViewer($hazard, $viewer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $hidden['safety_reviews']);
        $this->assertSame([], $hidden['tasks']);
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?hs_events[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $destination->id, 'secondary_site_ids' => []]);
        $this->assertSame([], $service->forViewer($hazard, $viewer)['safety_reviews']);
        $event->updateQuietly(['site_id' => $destination->id]);
        $this->assertSame([$event->id], array_column($service->forViewer($hazard, $viewer)['safety_reviews'], 'id'));
    }

    public function test_current_hazard_site_type_and_exact_event_category_precede_connections(): void
    {
        $hazard = $this->hazard();
        $valid = $this->review($hazard);
        $this->review($hazard, ['event_category' => HsEvent::CATEGORY_INCIDENT]);
        $viewer = $this->reader($this->site, ['hazards.view', 'sites.type.house.view']);
        $service = app(HazardWorkspaceConnectionsService::class);
        $this->assertSame([$valid->id], array_column($service->forViewer($hazard, $viewer)['safety_reviews'], 'id'));
        $viewer->load('permissionOverrides');
        $viewer->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'sites.type.house.view')->sole()->id => ['allowed' => false],
            Permission::where('key', 'sites.type.facility.view')->sole()->id => ['allowed' => true],
        ]);
        $this->assertSame([], $service->forViewer($hazard, $viewer)['safety_reviews']);
    }

    public function test_initial_key_union_preserves_real_hazard_linked_non_alert_tasks_without_cr_grant(): void
    {
        $hazard = $this->hazard();
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id,
            'notes' => 'PRIVATE-CR-KEY-PREP', 'context' => ['private' => 'PRIVATE-CR-CONTEXT-KEY-PREP']]);
        $event = $this->review($hazard, ['control_room_alert_id' => $alert->id]);
        $viewer = $this->reader($this->site, ['hazards.view']);
        $response = $this->actingAs($viewer)->get('/hazards/'.$hazard->id)->assertOk();
        $connections = data_get($response->viewData('page'), 'props.detail.connections');
        $this->assertSame(['hazard-'.$hazard->id, 'hs_event-'.$event->id], array_column($connections['tasks'], 'id'));
        $this->assertSame([], $connections['control_room']);
        $this->assertFalse($viewer->canDo('controlRoom.alerts.view'));
        $this->assertStringNotContainsString('PRIVATE-CR-KEY-PREP', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-CR-CONTEXT-KEY-PREP', $response->getContent());
        $this->get($connections['safety_reviews'][0]['href'])->assertOk();
        $this->get('/control-room/alerts/'.$alert->id)->assertForbidden();
        $this->assertSame('open', $hazard->fresh()->status);
    }

    public function test_initial_preparation_does_not_authorise_foreign_hazard_or_fabricate_alert_tasks(): void
    {
        $viewer = $this->reader($this->site, ['hazards.view']);
        $local = $this->hazard();
        $foreign = $this->hazard(['site_id' => Site::factory()->create(['type' => 'house'])->id,
            'description' => 'PRIVATE-FOREIGN-HAZARD-KEY-PREP']);
        $this->actingAs($viewer)->get('/hazards/'.$foreign->id)->assertForbidden()
            ->assertDontSee('PRIVATE-FOREIGN-HAZARD-KEY-PREP');
        $response = $this->get('/hazards/'.$local->id)->assertOk();
        $connections = data_get($response->viewData('page'), 'props.detail.connections');
        $this->assertSame(['hazard-'.$local->id], array_column($connections['tasks'], 'id'));
        $this->assertSame([], $connections['control_room']);
        $this->assertSame([], $connections['safety_reviews']);
    }

    private function hazard(array $overrides = []): SiteHazard
    {
        return SiteHazard::withoutEvents(fn () => SiteHazard::create([...[
            'site_id' => $this->site->id, 'reference_number' => 'HAZ-'.fake()->unique()->numerify('######'),
            'hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare',
            'risk_rating' => 'low', 'description' => 'Loose mat', 'status' => 'open',
            'reported_by_user_id' => $this->admin->id,
        ], ...$overrides]));
    }

    private function review(SiteHazard $hazard, array $overrides = []): HsEvent
    {
        return HsEvent::factory()->createQuietly([...[
            'source_type' => SiteHazard::class, 'source_id' => $hazard->id,
            'site_id' => $hazard->site_id, 'event_category' => HsEvent::CATEGORY_HAZARD,
            'created_by' => $this->admin->id,
        ], ...$overrides]);
    }

    private function inspection(Site $site, SiteHazard $hazard): SiteInspectionRecord
    {
        $schedule = SiteInspectionSchedule::create(['site_id' => $site->id, 'title' => 'Floor safety inspection',
            'inspection_type' => 'safety', 'frequency' => 'monthly', 'first_due_date' => '2026-10-08', 'next_due_date' => '2026-11-08']);

        return SiteInspectionRecord::createQuietly(['site_id' => $site->id, 'schedule_id' => $schedule->id,
            'due_date' => '2026-10-08', 'completed_at' => now(), 'result' => 'fail', 'linked_hazard_id' => $hazard->id,
            'findings' => 'Source-only narrative', 'evidence_photos' => ['private/source/file.jpg']]);
    }

    private function reader(Site $site, array $permissions): User
    {
        $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        $viewer->permissionOverrides()->sync(Permission::whereIn('key', $permissions)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));

        return $viewer->fresh();
    }
}
