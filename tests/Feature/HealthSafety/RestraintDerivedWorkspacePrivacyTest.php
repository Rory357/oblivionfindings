<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistQuery;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\HealthSafety\HsVisibilityService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyPresenter;
use App\Services\Incidents\IncidentJourneyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotInThisBuild;

/** Complete source/read regressions against the real shared presenters and HTTP routes. */
class RestraintDerivedWorkspacePrivacyTest extends RestraintEventCommandTestCase
{
    private function hs(RestraintEvent $source, array $values = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => RestraintEvent::class, 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_RESTRAINT, 'site_id' => $source->site_id,
            'client_id' => $source->client_id, 'staff_id' => $source->created_by,
            'created_by' => $source->created_by, 'severity' => 'medium',
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, ...$values,
        ]));
    }

    private function response(RestraintEvent $source, array $context = []): ControlRoomAlert
    {
        return ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'source' => 'operations', 'alert_type' => 'operations.restraint_event',
            'client_id' => $source->client_id, 'site_id' => $source->site_id,
            'severity' => 'medium', 'status' => ControlRoomAlert::STATUS_OPEN,
            'notes' => 'Legacy copied confidential note',
            'context' => ['restraint_event_id' => $source->id, 'description' => 'Copied confidential description', ...$context],
        ]));
    }

    private function action(HsEvent $event, User $owner, array $values = []): HsCorrectiveAction
    {
        return HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id, 'assigned_to_user_id' => $owner->id,
            'status' => HsCorrectiveAction::STATUS_OPEN, 'priority' => 'high',
            'due_date' => now('Pacific/Auckland')->addDay()->toDateString(), ...$values,
        ]));
    }

    private function grant(User $viewer, string $key): void
    {
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
    }

    /**
     * Fixture only: the closure guard trigger admits this one close, as HsEventClosureService does.
     * A query update, so the caller's loaded model keeps its stale pre-close state.
     */
    private function closedFixture(HsEvent $event, array $attributes): void
    {
        DB::statement('SET @hs_canonical_close_event_id = '.(int) $event->id);
        try {
            HsEvent::whereKey($event->id)->update($attributes);
        } finally {
            DB::statement('SET @hs_canonical_close_event_id = NULL');
        }
    }

    public function test_visibility_reconstructs_current_facts_and_people_instead_of_caller_loaded_relations(): void
    {
        $source = $this->event();
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $oldOwner = $this->staff([]);
        $newOwner = $this->staff([]);
        $event = $this->hs($source, ['owner_user_id' => $oldOwner->id, 'acceptance_notes' => 'Former sensitive decision']);
        $event->load('owner', 'acceptedBy');
        HsEvent::whereKey($event->id)->update(['owner_user_id' => $newOwner->id, 'acceptance_notes' => 'Current sensitive decision']);
        $event->setRelation('owner', new User(['id' => $oldOwner->id, 'name' => 'Injected stale private identity']));
        $dto = app(HsVisibilityService::class)->forEvent($event, $viewer);
        $this->assertSame('readable', $dto['access']);
        $this->assertSame($newOwner->id, $dto['handover']['owner']['id']);
        $this->assertSame('Current sensitive decision', $dto['handover']['notes']);
        $this->assertStringNotContainsString('Injected stale private identity', json_encode($dto));
        $this->assertStringNotContainsString('Former sensitive decision', json_encode($dto));
    }

    public function test_optional_summary_projection_cannot_export_current_private_model_authority(): void
    {
        $source = $this->event();
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $this->expectException(\LogicException::class);
        app(HsVisibilityService::class)->forEvent($this->hs($source), $viewer,
            fn (HsEvent $current): array => ['access' => 'readable', 'private_model' => $current]);
    }

    public function test_reused_visibility_consumers_recheck_private_rbac_client_assignment_hr_and_site(): void
    {
        $source = $this->event();
        $event = $this->hs($source, ['acceptance_notes' => 'Private source decision']);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $service = app(HsVisibilityService::class);
        $this->assertSame('readable', $service->forEvent($event, $viewer)['access']);
        $this->client->supportWorkers()->detach($viewer->id);
        $restricted = $service->forEvent($event, $viewer);
        $this->assertSame('restricted', $restricted['access']);
        $this->assertSame($event->status, $restricted['status']);
        $this->assertSame($event->handover_status, $restricted['handover']['status']);
        $this->client->supportWorkers()->attach($viewer->id);
        $this->revoke($viewer, 'restraints.view');
        $restricted = $service->forEvent($event, $viewer);
        $this->assertSame('restricted', $restricted['access']);
        $this->assertSame($event->status, $restricted['status']);
        $this->assertSame($event->handover_status, $restricted['handover']['status']);
        $this->grant($viewer, 'restraints.view');
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $restricted = $service->forEvent($event, $viewer);
        $this->assertSame('restricted', $restricted['access']);
        $this->assertNull($restricted['status']);
        $this->assertNull($restricted['handover']['status']);
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->site->update(['is_active' => false]);
        $dto = $service->forEvent($event, $viewer);
        $this->assertSame(['access', 'status', 'handover'], array_keys($dto));
        $this->assertSame('restricted', $dto['access']);
        $this->assertStringNotContainsString('Private source decision', json_encode($dto));
        $this->assertArrayNotHasKey('id', $dto);
        $this->assertNull($dto['status']);
        $this->assertNull($dto['handover']['status']);
    }

    public function test_denied_workflow_dependency_is_site_scoped_even_when_caller_changes_loaded_site(): void
    {
        $event = $this->hs($this->event(), ['status' => HsEvent::STATUS_MONITORING,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $viewer = $this->staff(['hazards.view', 'clients.viewAny'], $otherSite);
        // A stale caller's Site cannot supply the current recorded source scope.
        $event->site_id = $otherSite->id;
        $event->setRelation('site', $otherSite);
        $service = app(HsVisibilityService::class);
        $dto = $service->forEvent($event, $viewer);
        $this->assertSame(['access' => 'restricted', 'status' => null, 'handover' => ['status' => null]], $dto);
        $this->grant($viewer, 'healthSafety.viewAllSites');
        $dto = $service->forEvent($event, $viewer);
        $this->assertSame('restricted', $dto['access']);
        $this->assertSame(HsEvent::STATUS_MONITORING, $dto['status']);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $dto['handover']['status']);
        $this->site->update(['is_active' => false]);
        $this->assertSame(['access' => 'restricted', 'status' => null, 'handover' => ['status' => null]], $service->forEvent($event, $viewer));
    }

    public function test_journey_uses_current_hs_projection_and_never_hydrates_denied_private_relations(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny', 'clients.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id,
            'owner_user_id' => $viewer->id, 'acceptance_notes' => 'Current governance decision',
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $event->acceptance_notes = 'Caller stale private decision';
        $event->setRelation('owner', new User(['id' => 999, 'name' => 'Injected private owner']));
        $presenter = app(IncidentJourneyPresenter::class);
        $journey = new IncidentJourney($incident, null, $event);
        $dto = $presenter->present($journey, $viewer);
        $this->assertSame('Current governance decision', $dto['health_safety']['handover']['notes']);
        $this->assertSame($viewer->id, $dto['health_safety']['handover']['owner']['id']);
        $this->assertStringNotContainsString('Injected private owner', json_encode($dto));
        $this->revoke($viewer, 'hazards.view');
        $event->unsetRelations();
        $dto = $presenter->present($journey, $viewer);
        $this->assertNull($dto['health_safety']);
        $this->assertNull($dto['references']['health_safety']);
        $this->assertNull($dto['lifecycle'][2]['href']);
        $this->assertFalse($event->relationLoaded('owner'));
        $this->assertFalse($event->relationLoaded('activeInvestigation'));
        $this->assertStringNotContainsString('Current governance decision', json_encode($dto));
    }

    public function test_journey_snapshot_materializes_permitted_action_fact_and_denial_never_queries_or_closes_private_work(): void
    {
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'incidents.viewAny', 'clients.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id, 'status' => 'closed',
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id,
            'status' => HsEvent::STATUS_MONITORING, 'handover_status' => HsEvent::HANDOVER_ACCEPTED,
        ]));
        $event->load('correctiveActions');
        $this->action($event, $viewer, ['status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $journey = new IncidentJourney($incident, null, $event);
        $presenter = app(IncidentJourneyPresenter::class);
        $this->assertSame('Awaiting independent verification', $presenter->present($journey, $viewer)['journey_state']);
        $this->assertSame($presenter->journeyState($incident, null, $event), $presenter->present($journey, $viewer)['journey_state']);
        $this->revoke($viewer, 'hazards.view');
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $dto = $presenter->present($journey, $viewer);
            $this->assertNull($dto['health_safety']);
            $this->assertSame('Journey needs review', $dto['journey_state']);
            $this->assertSame('blocked', $dto['lifecycle'][2]['state']);
            $this->assertNull($dto['lifecycle'][2]['href']);
            foreach (DB::getQueryLog() as $entry) {
                $this->assertStringNotContainsString('hs_corrective_actions', strtolower($entry['query']));
            }
        } finally {
            DB::disableQueryLog();
        }
        // A retained denied-source reference is uncertain, never a completed journey.
        $this->closedFixture($event, ['status' => HsEvent::STATUS_CLOSED]);
        $this->assertSame('Journey needs review', $presenter->present($journey, $viewer)['journey_state']);
    }

    public function test_readable_pending_journey_revoked_manage_or_site_cannot_offer_stale_hs_command(): void
    {
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'incidents.viewAny', 'clients.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]));
        $presenter = app(IncidentJourneyPresenter::class);
        $journey = new IncidentJourney($incident, null, $event);
        $this->assertSame('Accept H&S handover', $presenter->present($journey, $viewer)['next_action']['label']);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->revoke($viewer, 'hazards.manage');
        $dto = $presenter->present($journey, $viewer);
        $this->assertSame('readable', $dto['linked_access']['health_safety']);
        $this->assertSame('waiting', $dto['lifecycle'][2]['state']);
        $this->assertSame('View incident', $dto['next_action']['label']);
        $this->grant($viewer, 'hazards.manage');
        $otherSite = Site::factory()->create(['is_active' => true]);
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $dto = $presenter->present($journey, $viewer);
        $this->assertNull($dto['health_safety']);
        $this->assertSame('blocked', $dto['lifecycle'][2]['state']);
        $this->assertSame('Journey needs review', $dto['journey_state']);
        $this->assertNotSame('health_safety', $dto['next_action']['stage']);
        $this->assertNull($dto['lifecycle'][2]['href']);
    }

    public function test_real_workspace_summary_and_journey_share_current_closed_facts_despite_stale_governance_reference(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $alert->updateQuietly(['status' => ControlRoomAlert::STATUS_CLOSED]);
        $viewer = $this->staff(['controlRoom.alerts.view', 'hazards.view', 'restraints.view', 'clients.viewAny']);
        $event = $this->hs($source, ['control_room_alert_id' => $alert->id, 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE]);
        $event->load('correctiveActions');
        // Inject only the stale canonical identity at the dependency boundary;
        // the real workspace, fresh source reader and presenter remain intact.
        $journeys = \Mockery::mock(app(IncidentJourneyService::class))->makePartial();
        $journeys->shouldReceive('governanceRecordsForAlert')->andReturn(['incident' => null, 'hs_event' => $event]);
        $this->instance(IncidentJourneyService::class, $journeys);
        $this->closedFixture($event, ['status' => HsEvent::STATUS_CLOSED, 'handover_status' => HsEvent::HANDOVER_ACCEPTED]);
        $service = app(AlertWorkspaceService::class);
        $dto = $service->build($viewer, $alert->id);
        $this->assertSame(HsEvent::STATUS_CLOSED, $dto['linked_hs_event']['status']);
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $dto['linked_hs_event']['handover']['status']);
        $this->assertSame('Journey closed', $dto['journey_state']);
        $this->assertSame(HsEvent::STATUS_OPEN, $event->status);
        $default = app(HsVisibilityService::class)->forEvent($event, $viewer);
        $this->assertSame($default, $dto['linked_hs_event']);
        $this->assertArrayNotHasKey('workflow_facts', $default);
        // The retained reference cannot become an absent/closed case after deletion.
        HsEvent::whereKey($event->id)->delete();
        $dto = $service->build($viewer, $alert->id);
        $this->assertSame('restricted', $dto['linked_hs_event']['access']);
        $this->assertNull($dto['linked_hs_event']['status']);
        $this->assertSame('Journey needs review', $dto['journey_state']);
    }

    public function test_real_workspace_rechecks_source_hr_and_site_without_querying_denied_completed_actions(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $alert->updateQuietly(['status' => ControlRoomAlert::STATUS_CLOSED]);
        // Broad operational read deliberately does not grant H&S all-Sites or source access.
        $viewer = $this->staff(['controlRoom.alerts.view', 'reports.viewAny', 'hazards.view', 'restraints.view', 'clients.viewAny']);
        $event = $this->hs($source, ['control_room_alert_id' => $alert->id, 'status' => HsEvent::STATUS_MONITORING,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, 'acceptance_notes' => 'Current private H&S acceptance']);
        $this->action($event, $viewer, ['status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $service = app(AlertWorkspaceService::class);
        $this->assertSame('Awaiting independent verification', $service->build($viewer, $alert->id)['journey_state']);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $otherSite = Site::factory()->create(['is_active' => true]);
        foreach (['source', 'hr', 'site'] as $revocation) {
            $this->grant($viewer, 'restraints.view');
            $viewer->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => []]);
            if ($revocation === 'source') {
                $this->revoke($viewer, 'restraints.view');
            } elseif ($revocation === 'hr') {
                $viewer->hrEmployeeProfile()->update(['is_active' => false]);
            } else {
                $viewer->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
            }
            DB::enableQueryLog();
            try {
                DB::flushQueryLog();
                $dto = $service->build($viewer, $alert->id);
                $this->assertNotNull($dto, $revocation);
                $this->assertSame('restricted', $dto['linked_hs_event']['access'], $revocation);
                $this->assertSame('Journey needs review', $dto['journey_state'], $revocation);
                $this->assertStringNotContainsString('Current private H&S acceptance', json_encode($dto));
                if ($revocation !== 'source') {
                    $this->assertNull($dto['linked_hs_event']['status']);
                    $this->assertNull($dto['linked_hs_event']['handover']['status']);
                }
                foreach (DB::getQueryLog() as $entry) {
                    $this->assertStringNotContainsString('hs_corrective_actions', strtolower($entry['query']), $revocation);
                }
            } finally {
                DB::disableQueryLog();
            }
        }
    }

    public function test_real_workspace_preserves_active_operational_priority_with_restricted_hs_dependency(): void
    {
        $source = $this->event();
        $alert = $this->response($source);
        $viewer = $this->staff(['controlRoom.alerts.view', 'hazards.view', 'clients.viewAny']);
        $event = $this->hs($source, ['control_room_alert_id' => $alert->id, 'status' => HsEvent::STATUS_CLOSED]);
        $this->action($event, $viewer, ['status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $dto = app(AlertWorkspaceService::class)->build($viewer, $alert->id);
        $this->assertSame('restricted', $dto['linked_hs_event']['access']);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $dto['alert']['status']);
        $this->assertSame('Operational response active', $dto['journey_state']);
    }

    public function test_workspace_private_entry_and_legacy_notes_revoke_while_operational_work_remains(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $viewer = $this->staff(['controlRoom.alerts.view', 'restraints.view', 'clients.viewAny']);
        $service = app(AlertWorkspaceService::class);
        $dto = $service->build($viewer, $alert->id);
        $this->assertSame('readable', $dto['alert']['context']['restraint_content_access']);
        $this->assertSame('/health-safety/restraints/events/'.$source->id, $dto['alert']['context']['restraint_source']['href']);
        $this->assertSame($alert->notes, $dto['alert']['notes']);
        $this->revoke($viewer, 'restraints.view');
        $dto = $service->build($viewer, $alert->id);
        $this->assertNotNull($dto);
        $this->assertNull($dto['alert']['context']['restraint_source']);
        $this->assertNull($dto['alert']['notes']);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $dto['alert']['status']);
        $alert->updateQuietly(['notes' => 'Operator contacted on-call support', 'context' => [
            ...$alert->context, 'source_deliveries' => [], 'activity_log' => [['content' => 'Independent operator response']],
        ]]);
        $dto = $service->build($viewer, $alert->id);
        $this->assertSame('Operator contacted on-call support', $dto['alert']['notes']);
        $this->assertSame('Independent operator response', $dto['alert']['context']['activity_log'][0]['content']);
        $this->assertArrayNotHasKey('restraint_event_id', $dto['alert']['context']);
        $this->assertArrayNotHasKey('source_deliveries', $dto['alert']['context']);
        $this->assertStringNotContainsString('Copied confidential description', json_encode($dto));
    }

    public function test_workspace_preserves_truthful_source_access_without_a_missing_guarded_destination(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $viewer = $this->staff(['controlRoom.alerts.view', 'restraints.view', 'clients.viewAny']);
        Route::partialMock()->shouldReceive('has')->with('health-safety.restraints.events.show')->once()->andReturn(false);
        $dto = app(AlertWorkspaceService::class)->build($viewer, $alert->id);
        $this->assertSame('readable', $dto['alert']['context']['restraint_content_access']);
        $this->assertSame($source->id, $dto['alert']['context']['restraint_source']['id']);
        $this->assertNull($dto['alert']['context']['restraint_source']['href']);
    }

    public function test_journey_conceals_copied_restraint_notes_but_keeps_canonical_operational_notes(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $viewer = $this->staff(['controlRoom.alerts.view', 'incidents.viewAny', 'clients.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'control_room_alert_id' => $alert->id,
        ]));
        $journey = new IncidentJourney($incident, $alert, null);
        $presenter = app(IncidentJourneyPresenter::class);
        $dto = $presenter->present($journey, $viewer);
        $this->assertNotNull($dto['control_room']);
        $this->assertNull($dto['control_room']['notes']);
        $alert->updateQuietly(['notes' => 'Current independently authored response note', 'context' => [...$alert->context, 'source_deliveries' => []]]);
        $dto = $presenter->present($journey, $viewer);
        $this->assertSame('Current independently authored response note', $dto['control_room']['notes']);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $dto['control_room']['status']);
        $this->grant($viewer, 'restraints.view');
        $alert->updateQuietly(['notes' => 'Authorised copied note', 'context' => ['restraint_event_id' => $source->id]]);
        $this->assertSame('Authorised copied note', $presenter->present($journey, $viewer)['control_room']['notes']);
    }

    public function test_actual_incident_detail_preserves_rich_authorised_investigation_action_and_handover_fields(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAny', 'clients.viewAny', 'hazards.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'status' => 'closed', 'reviewed_at' => now(), 'reviewed_by' => $viewer->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'status' => HsEvent::STATUS_MONITORING,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, 'owner_user_id' => $viewer->id,
            'accepted_by_user_id' => $viewer->id, 'acceptance_notes' => 'Current private acceptance',
            'worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED, 'worksafe_notified_at' => now()->subHour(),
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $investigation = HsInvestigation::withoutEvents(fn () => HsInvestigation::factory()->create([
            'hs_event_id' => $event->id, 'status' => HsInvestigation::STATUS_COMPLETED, 'methodology' => '5_whys',
            'root_causes' => ['Private established cause'], 'contributing_factors' => ['Private factual factor'],
            'recommendations' => ['Private source recommendation'], 'lessons_learned' => 'Private retained learning',
        ]));
        $action = $this->action($event, $viewer, ['title' => 'Current corrective outcome', 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('incidents/show')
                ->where('detail.linked_access.health_safety', 'readable')
                ->where('detail.journey_state', 'Awaiting independent verification')
                ->where('detail.hs_event.id', $event->id)
                ->where('detail.worksafe_notified_at', $event->worksafe_notified_at->toJSON())
                ->where('detail.hs_event.worksafe_notified_at', $event->worksafe_notified_at->toIso8601String())
                ->where('detail.hs_event.handover.owner.id', $viewer->id)
                ->where('detail.hs_event.handover.accepted_by.id', $viewer->id)
                ->where('detail.hs_event.handover.notes', 'Current private acceptance')
                ->where('detail.hs_event.handover.can_accept', false)
                ->where('detail.hs_event.investigation.reference_number', $investigation->reference_number)
                ->where('detail.hs_event.investigation.methodology', '5_whys')
                ->where('detail.hs_event.investigation.root_causes.0', 'Private established cause')
                ->where('detail.hs_event.investigation.contributing_factors.0', 'Private factual factor')
                ->where('detail.hs_event.investigation.recommendations.0', 'Private source recommendation')
                ->where('detail.hs_event.investigation.lessons_learned', 'Private retained learning')
                ->where('detail.hs_event.corrective_actions.0.id', $action->id)
                ->where('detail.hs_event.corrective_actions.0.assigned_to', $viewer->name)
                ->where('detail.hs_event.corrective_actions.0.due_date', $action->due_date->toJSON()));
    }

    public function test_actual_incident_detail_uses_current_closed_projection_instead_of_stale_pending_journey_model(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAny', 'clients.viewAny', 'hazards.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'status' => 'closed', 'reviewed_at' => now(), 'reviewed_by' => $viewer->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, 'acceptance_notes' => 'Old copied acceptance',
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $event->load('correctiveActions', 'owner', 'acceptedBy');
        $journeys = \Mockery::mock(app(IncidentJourneyService::class))->makePartial();
        $journeys->shouldReceive('journeyForIncident')->andReturn(new IncidentJourney($incident, null, $event));
        $this->instance(IncidentJourneyService::class, $journeys);
        $this->closedFixture($event, ['status' => HsEvent::STATUS_CLOSED,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, 'acceptance_notes' => 'Current accepted governance']);
        $response = $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('detail.hs_event.status', HsEvent::STATUS_CLOSED)
            ->where('detail.hs_event.handover.status', HsEvent::HANDOVER_ACCEPTED)
            ->where('detail.hs_event.handover.notes', 'Current accepted governance')
            ->where('detail.journey_state', 'Journey closed'));
        $this->assertStringNotContainsString('Old copied acceptance', $response->getContent());
        $this->assertSame(HsEvent::STATUS_OPEN, $event->status);
    }

    public function test_actual_incident_detail_rechecks_source_hr_and_site_and_never_queries_denied_private_actions(): void
    {
        // Isolate the actual HTTP detail from unrelated shared Tasks chrome.
        // Route permissions, record policy and canonical source readers stay real.
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAny', 'clients.viewAny', 'hazards.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'status' => 'closed', 'reviewed_at' => now(), 'reviewed_by' => $viewer->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'status' => HsEvent::STATUS_MONITORING,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, 'acceptance_notes' => 'Private source decision',
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $this->action($event, $viewer, ['title' => 'Private completed action', 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.journey_state', 'Awaiting independent verification'));
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $otherSite = Site::factory()->create(['is_active' => true]);
        foreach (['source', 'hr', 'site'] as $revocation) {
            $this->grant($viewer, 'hazards.view');
            $viewer->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => []]);
            if ($revocation === 'source') {
                $this->revoke($viewer, 'hazards.view');
            } elseif ($revocation === 'hr') {
                $viewer->hrEmployeeProfile()->update(['is_active' => false]);
            } else {
                $viewer->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
            }
            DB::enableQueryLog();
            try {
                DB::flushQueryLog();
                $response = $this->get('/incidents/'.$incident->id);
                if ($revocation === 'source') {
                    $response->assertOk();
                }
                if (in_array($response->status(), [403, 404], true)) {
                    // Independent incident authority can also deny the whole page; an incident
                    // outside the viewer's current Site scope is concealed as 404 (kept Codex rule).
                    $this->assertNotSame('source', $revocation);
                } else {
                    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.hs_event', null)
                        ->where('detail.journey_state', 'Journey needs review')
                        ->where('detail.corrective_action_owners', [])
                        ->where('detail.is_notifiable', null)
                        ->where('detail.worksafe_reference', null));
                }
                $this->assertStringNotContainsString('Private source decision', $response->getContent());
                $this->assertStringNotContainsString('Private completed action', $response->getContent());
                foreach (DB::getQueryLog() as $entry) {
                    $this->assertStringNotContainsString('hs_corrective_actions', strtolower($entry['query']), $revocation);
                }
            } finally {
                DB::disableQueryLog();
            }
        }
    }

    public function test_actual_incident_detail_preserves_journey_repair_and_active_operational_response_priority(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAny', 'clients.viewAny', 'controlRoom.alerts.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
        ]));
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'source' => 'incident',
            'alert_type' => 'operations.client_incident', 'status' => ControlRoomAlert::STATUS_OPEN,
            'context' => ['incident_id' => $incident->id],
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'control_room_alert_id' => $alert->id,
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id, 'control_room_alert_id' => $alert->id]);
        $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.hs_event', null)
                ->where('detail.linked_access.health_safety', 'restricted')
                ->where('detail.journey_state', 'Operational response active'));
        $foreign = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => Site::factory()->create()->id]));
        $incident->updateQuietly(['hs_event_id' => $foreign->id]);
        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.journey_repair_required', true)
                ->where('detail.journey_state', 'Journey repair required')
                ->where('detail.hs_event', null)->where('detail.close_gate.allowed', false));
    }

    public function test_actual_incident_detail_retains_missing_hs_reference_as_repair_without_redacting_legitimate_unlinked_incident(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAny', 'clients.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'status' => 'closed', 'reviewed_at' => now(), 'reviewed_by' => $viewer->id,
            'is_notifiable' => true, 'worksafe_notification_status' => 'notified',
            'worksafe_notified_at' => now()->subHour(), 'worksafe_reference' => 'Retained incident notification',
        ]));
        $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.journey_repair_required', false)
                ->where('detail.linked_access.health_safety', 'absent')->where('detail.hs_event', null)
                ->where('detail.journey_state', 'Journey closed')
                ->where('detail.worksafe_reference', 'Retained incident notification'));
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'source' => 'incident',
            'alert_type' => 'operations.client_incident', 'status' => ControlRoomAlert::STATUS_OPEN,
            'context' => ['incident_id' => $incident->id],
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_CLOSED, 'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_reference' => 'Private governance notification', 'worksafe_notified_at' => now(),
        ]));
        $this->action($event, $viewer, ['title' => 'Private vanished-source action']);
        $incident->updateQuietly(['hs_event_id' => $event->id, 'control_room_alert_id' => $alert->id]);
        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.journey_repair_required', false)
                ->where('detail.journey_state', 'Operational response active')->where('detail.hs_event.id', $event->id));
        $event->deleteQuietly();
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $response = $this->get('/incidents/'.$incident->id)->assertOk();
            $response->assertInertia(fn (Assert $page) => $page->where('detail.journey_repair_required', true)
                ->where('detail.journey_state', 'Journey repair required')->where('detail.close_gate.allowed', false)
                ->where('detail.linked_access.health_safety', 'restricted')->where('detail.hs_event', null)
                ->where('detail.control_room_alert.status', ControlRoomAlert::STATUS_OPEN)
                ->where('detail.is_notifiable', null)->where('detail.worksafe_notification_status', null)
                ->where('detail.worksafe_notified_at', null)->where('detail.worksafe_reference', null));
            $this->assertStringNotContainsString('Private governance notification', $response->getContent());
            $this->assertStringNotContainsString('Retained incident notification', $response->getContent());
            $this->assertStringNotContainsString('Private vanished-source action', $response->getContent());
            foreach (DB::getQueryLog() as $entry) {
                $this->assertStringNotContainsString('hs_corrective_actions', strtolower($entry['query']));
            }
        } finally {
            DB::disableQueryLog();
        }
        $incident->updateQuietly(['hs_event_id' => null]);
        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.journey_repair_required', false)
                ->where('detail.linked_access.health_safety', 'absent')
                ->where('detail.journey_state', 'Operational response active')
                ->where('detail.worksafe_reference', 'Retained incident notification'));
    }

    public function test_all_three_hs_detail_entries_preserve_authorised_record_parity_and_register_date_scope(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $source = $this->event();
        $event = $this->hs($source, ['occurred_at' => now()->subHour(), 'status' => HsEvent::STATUS_MONITORING,
            'acceptance_notes' => 'Current canonical source handover']);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $action = $this->action($event, $viewer);
        $day = $event->occurred_at->copy()->setTimezone('Pacific/Auckland')->toDateString();
        $entries = [
            ['/health-safety/events/'.$event->id, 'health-safety/events/show'],
            ['/health-safety/events?'.http_build_query(['event' => $event->id, 'site_id' => $this->site->id, 'from' => $day, 'to' => $day]), 'health-safety/events/index'],
            ['/health-safety/corrective-actions?'.http_build_query(['event' => $event->id, 'site_id' => $this->site->id]), 'health-safety/corrective-actions/index'],
        ];
        foreach ($entries as [$url, $component]) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)
                ->where('detail.id', $event->id)->where('detail.reference_number', $event->reference_number)
                ->where('detail.status', HsEvent::STATUS_MONITORING)
                ->where('detail.handover.status', HsEvent::HANDOVER_ACCEPTED)
                ->where('detail.handover.notes', 'Current canonical source handover')
                ->where('detail.corrective_actions.0.id', $action->id));
        }
        $nextDay = $event->occurred_at->copy()->setTimezone('Pacific/Auckland')->addDay()->toDateString();
        $this->get('/health-safety/events?'.http_build_query(['event' => $event->id, 'site_id' => $this->site->id, 'from' => $nextDay, 'to' => $nextDay]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail', null)->has('events.data', 0));
    }

    public function test_all_three_hs_detail_entries_use_current_manage_grant_instead_of_preloaded_request_actor(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'clients.viewAny', 'incidents.viewAny',
            'healthSafety.events.close', 'healthSafety.closureExceptions.request', 'healthSafety.closureExceptions.approve']);
        // Handover acceptance needs an actually submitted incident; the factory state sets status only.
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'submitted_at' => now()->subHour(),
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, 'owner_user_id' => $viewer->id,
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $entries = ['/health-safety/events/'.$event->id, '/health-safety/events?event='.$event->id,
            '/health-safety/corrective-actions?event='.$event->id];
        foreach ($entries as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)->where('detail.can.manage', true)
                ->where('detail.can.close', true)->where('detail.can.request_closure_exception', true)
                ->where('detail.can.approve_closure_exception', true)
                ->where('detail.handover.can_accept', true)
                ->where('detail.handover_summary.next_action.label', 'Accept this H&S handover')
                ->where('detail.assignable_staff', fn ($staff): bool => collect($staff)->contains('id', $viewer->id)));
        }
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertTrue($viewer->canDo('hazards.manage'));
        $this->revoke($viewer, 'hazards.manage');
        // Demonstrate the exact stale caller premise without resetting its evidence.
        $this->assertTrue($viewer->canDo('hazards.manage'));
        foreach ($entries as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)->where('detail.can.manage', false)
                ->where('detail.can.close', true)->where('detail.can.request_closure_exception', true)
                ->where('detail.can.approve_closure_exception', true)
                ->where('detail.can.manage_corrective_action_lifecycle', false)
                ->where('detail.can.verify_corrective_actions', false)
                ->where('detail.handover.can_accept', false)->where('detail.handover_summary.next_action', null)
                ->where('detail.assignable_staff', [])->where('detail.action_handover.eligible_owners', []));
        }
        foreach (['healthSafety.events.close', 'healthSafety.closureExceptions.request', 'healthSafety.closureExceptions.approve'] as $key) {
            $this->revoke($viewer, $key);
            $this->assertTrue($viewer->canDo($key));
        }
        foreach ($entries as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)->where('detail.can.close', false)
                ->where('detail.can.request_closure_exception', false)->where('detail.can.approve_closure_exception', false));
        }
        $this->grant($viewer, 'hazards.manage');
        foreach ($entries as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('detail.can.manage', true)->where('detail.handover.can_accept', true));
        }
    }

    public function test_all_three_hs_detail_entries_recheck_private_source_and_current_site_between_requests(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $source = $this->event();
        $event = $this->hs($source, ['acceptance_notes' => 'Private canonical handover']);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $this->action($event, $viewer, ['title' => 'Private corrective outcome']);
        $entries = ['/health-safety/events/'.$event->id, '/health-safety/events?event='.$event->id,
            '/health-safety/corrective-actions?event='.$event->id];
        foreach ($entries as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.id', $event->id));
        }
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->revoke($viewer, 'restraints.view');
        foreach ($entries as $url) {
            $response = $this->get($url)->assertNotFound();
            $this->assertStringNotContainsString('Private canonical handover', $response->getContent());
            $this->assertStringNotContainsString('Private corrective outcome', $response->getContent());
        }
        $this->grant($viewer, 'restraints.view');
        $otherSite = Site::factory()->create(['is_active' => true]);
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        foreach ($entries as $url) {
            $this->get($url)->assertNotFound();
        }
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->client->update(['site_id' => $otherSite->id]);
        foreach ($entries as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_actual_corrective_action_record_get_denies_source_revoke_and_preserves_ordinary_work(): void
    {
        $source = $this->event();
        $event = $this->hs($source);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $action = $this->action($event, $viewer, ['title' => 'Source restricted corrective action']);
        $ordinary = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id]));
        $ordinaryAction = $this->action($ordinary, $viewer);
        $this->actingAs($viewer)->get('/health-safety/corrective-actions/'.$action->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/corrective-actions/show')->where('workspace.action.id', $action->id));
        $this->revoke($viewer, 'restraints.view');
        $this->get('/health-safety/corrective-actions/'.$action->id)->assertNotFound();
        $this->get('/health-safety/corrective-actions/'.$ordinaryAction->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.action.id', $ordinaryAction->id));
        $foreign = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => Site::factory()->create()->id]));
        $this->get('/health-safety/corrective-actions/'.$this->action($foreign, $viewer)->id)->assertNotFound();
    }

    public function test_action_list_source_reference_search_and_parent_action_filter_remain_exact_after_source_revoke(): void
    {
        $source = $this->event();
        $event = $this->hs($source, ['reference_number' => 'HS-PRIV-RESTR-NDL']);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $action = $this->action($event, $viewer);
        $ordinary = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id, 'reference_number' => 'HS-ORDINARY-NEEDLE']));
        $ordinaryAction = $this->action($ordinary, $viewer);
        $this->actingAs($viewer)->get('/health-safety/corrective-actions?'.http_build_query(['q' => $event->reference_number, 'site_id' => $this->site->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('actions.data', 1)->where('actions.data.0.id', $action->id)->where('tabCounts.all', 1));
        $parentUrl = '/health-safety/events?'.http_build_query(['q' => $event->reference_number, 'action_context' => ['q' => $event->reference_number, 'priority' => 'high', 'site_id' => $this->site->id]]);
        $this->get($parentUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.id', $event->id));
        $this->revoke($viewer, 'restraints.view');
        $this->get('/health-safety/corrective-actions?'.http_build_query(['q' => $event->reference_number]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('actions.data', 0)->where('tabCounts.all', 0));
        $this->get($parentUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 0));
        $this->get('/health-safety/corrective-actions?'.http_build_query(['q' => $ordinary->reference_number]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('actions.data', 1)->where('actions.data.0.id', $ordinaryAction->id));
    }

    public function test_control_room_filtered_reference_search_intersects_source_read_without_hiding_operational_response(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room restraint changes (restraint_content_access, source-checked search and journey states), which this build doesn\'t port.', 'port with the Control Room work.'));
        $source = $this->event();
        $alert = $this->response($source);
        $event = $this->hs($source, ['control_room_alert_id' => $alert->id, 'reference_number' => 'HS-RESTR-SRCH-UNQ']);
        $viewer = $this->staff(['controlRoom.viewAny', 'controlRoom.alerts.view', 'hazards.view', 'restraints.view', 'clients.viewAny']);
        $query = app(AlertWorklistQuery::class);
        $filters = ['q' => $event->reference_number, 'site_id' => $this->site->id, 'severity' => 'medium', 'ids' => [$alert->id]];
        $this->assertSame([$alert->id], $query->forUser($viewer, $filters)->pluck('control_room_alerts.id')->all());
        $this->assertSame([], $query->forUser($viewer, [...$filters, 'severity' => 'critical'])->pluck('control_room_alerts.id')->all());
        $this->assertSame([], $query->forUser($viewer, [...$filters, 'ids' => []])->pluck('control_room_alerts.id')->all());
        $url = '/control-room/incidents?'.http_build_query(['search' => $event->reference_number, 'site_id' => $this->site->id, 'severity' => 'medium']);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('journeys.data', 1)->where('journeys.data.0.alert.id', $alert->id));
        $viewer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($viewer, 'restraints.view');
        $this->assertSame([], $query->forUser($viewer, $filters)->pluck('control_room_alerts.id')->all());
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('journeys.data', 0));
        $this->assertSame([$alert->id], $query->forUser($viewer, ['ids' => [$alert->id]])->pluck('control_room_alerts.id')->all());
        $this->assertNotNull(app(AlertWorkspaceService::class)->build($viewer, $alert->id));
    }

    public function test_control_room_search_retains_ordinary_reference_and_excludes_foreign_site_reference(): void
    {
        $viewer = $this->staff(['controlRoom.viewAny', 'controlRoom.alerts.view', 'hazards.view']);
        $localAlert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'severity' => 'medium', 'context' => []]));
        $local = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id, 'control_room_alert_id' => $localAlert->id, 'reference_number' => 'HS-ORD-SRCH-LOCAL', 'handover_status' => HsEvent::HANDOVER_ACCEPTED]));
        $otherSite = Site::factory()->create();
        $foreignAlert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create(['site_id' => $otherSite->id, 'status' => 'open', 'severity' => 'medium', 'context' => []]));
        $foreign = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $otherSite->id, 'control_room_alert_id' => $foreignAlert->id, 'reference_number' => 'HS-ORD-SRCH-FOREIGN', 'handover_status' => HsEvent::HANDOVER_ACCEPTED]));
        $query = app(AlertWorklistQuery::class);
        $this->assertSame([$localAlert->id], $query->forUser($viewer, ['q' => $local->reference_number])->pluck('control_room_alerts.id')->all());
        $this->assertSame([], $query->forUser($viewer, ['q' => $foreign->reference_number])->pluck('control_room_alerts.id')->all());
        $this->actingAs($viewer)->get('/control-room/incidents?'.http_build_query(['search' => $local->reference_number]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('journeys.data', 1)->where('journeys.data.0.alert.id', $localAlert->id));
        $this->get('/control-room/incidents?'.http_build_query(['search' => $foreign->reference_number]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('journeys.data', 0));
    }

    public function test_unlinked_assigned_incident_corrective_action_flag_uses_fresh_compliance_grant(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['incidents.viewAssigned', 'clients.viewAssigned', 'compliance.view']);
        $viewer->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'incidents.viewAny')->sole()->id => ['allowed' => false],
            Permission::where('key', 'hazards.view')->sole()->id => ['allowed' => false],
        ]);
        $this->client->supportWorkers()->attach($viewer->id);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'hs_event_id' => null, 'control_room_alert_id' => null,
        ]));
        $this->assertFalse(HsEvent::where('source_type', ClientIncident::class)->where('source_id', $incident->id)->exists());
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertFalse($viewer->canDo('incidents.viewAny'));
        $this->assertFalse($viewer->canDo('hazards.view'));
        $this->assertTrue($viewer->canDo('compliance.view'));
        $url = '/incidents/'.$incident->id;
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('incidents/show')->where('detail.linked_access.health_safety', 'absent')
            ->where('detail.hs_event', null)->where('detail.can.raiseCorrectiveAction', true)
            ->where('detail.corrective_action_owners', []));
        $this->revoke($viewer, 'compliance.view');
        $this->assertTrue($viewer->canDo('compliance.view'), 'The original request actor deliberately keeps stale loaded evidence.');
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'absent')->where('detail.hs_event', null)
            ->where('detail.can.raiseCorrectiveAction', false)->where('detail.corrective_action_owners', []));
        $this->grant($viewer, 'compliance.view');
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.can.raiseCorrectiveAction', true)->where('detail.corrective_action_owners', []));
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertFalse(HsEvent::where('source_type', ClientIncident::class)->where('source_id', $incident->id)->exists());
    }

    public function test_linked_incident_keeps_hazards_positive_but_denied_hs_source_has_no_flag_or_owner_choices(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        [$viewer, $incident, $event, $owner] = $this->ownerChoiceFixture();
        $this->grant($viewer, 'compliance.view');
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $url = '/incidents/'.$incident->id;
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'readable')->where('detail.hs_event.id', $event->id)
            ->where('detail.can.raiseCorrectiveAction', true)
            ->where('detail.corrective_action_owners', fn ($owners): bool => collect($owners)->contains('id', $owner->id)));
        $this->revoke($viewer, 'compliance.view');
        // Readability requires hazards.view, which also satisfies the existing flag OR.
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'readable')->where('detail.can.raiseCorrectiveAction', true)
            ->where('detail.corrective_action_owners', fn ($owners): bool => collect($owners)->contains('id', $owner->id)));
        $this->grant($viewer, 'compliance.view');
        $this->revoke($viewer, 'hazards.view');
        $this->assertTrue($viewer->canDo('hazards.view'));
        $this->assertTrue($viewer->canDo('compliance.view'));
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'restricted')->where('detail.hs_event', null)
            ->where('detail.can.raiseCorrectiveAction', false)->where('detail.corrective_action_owners', []));
        $this->grant($viewer, 'hazards.view');
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'readable')->where('detail.can.raiseCorrectiveAction', true)
            ->where('detail.corrective_action_owners', fn ($owners): bool => collect($owners)->contains('id', $owner->id)));
    }

    #[DataProvider('ownerEligibilityChanges')]
    public function test_current_owner_employment_approval_and_manage_grant_changes_restore_readable_entry_parity(string $change): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        [$viewer, $incident, $event, $owner] = $this->ownerChoiceFixture();
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $owner->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, true);
        match ($change) {
            'inactive' => $owner->hrEmployeeProfile()->update(['is_active' => false]),
            'ended' => $owner->hrEmployeeProfile()->update(['end_date' => now('Pacific/Auckland')->subDay()->toDateString()]),
            'future' => $owner->hrEmployeeProfile()->update(['start_date' => now('Pacific/Auckland')->addDay()->toDateString()]),
            'unapproved' => $owner->update(['approved_at' => null]),
            'manage revoked' => $this->revoke($owner, 'hazards.manage'),
        };
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, false);
        match ($change) {
            'inactive' => $owner->hrEmployeeProfile()->update(['is_active' => true]),
            'ended' => $owner->hrEmployeeProfile()->update(['end_date' => null]),
            'future' => $owner->hrEmployeeProfile()->update(['start_date' => now('Pacific/Auckland')->subYear()->toDateString()]),
            'unapproved' => $owner->update(['approved_at' => now()]),
            'manage revoked' => $this->grant($owner, 'hazards.manage'),
        };
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, true);
    }

    public static function ownerEligibilityChanges(): array
    {
        return [['inactive'], ['ended'], ['future'], ['unapproved'], ['manage revoked']];
    }

    public function test_current_owner_primary_and_secondary_site_changes_restore_same_readable_event_choices(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        [$viewer, $incident, $event, $owner] = $this->ownerChoiceFixture();
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $owner->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, true);
        $owner->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, false);
        $owner->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, true);
        $owner->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, false);
        $owner->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $owner, true);
        $this->assertSame($this->site->id, $event->fresh()->site_id);
        $this->assertSame($this->site->id, $incident->fresh()->site_id);
    }

    public function test_current_site_bypass_cannot_widen_nonnull_event_site_owner_choices(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        [$viewer, $incident, $event, $localOwner] = $this->ownerChoiceFixture();
        $otherSite = Site::factory()->create(['is_active' => true]);
        $foreignOwner = $this->staff(['hazards.view', 'hazards.manage'], $otherSite);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $localOwner, true);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, false);
        $this->grant($viewer, 'healthSafety.viewAllSites');
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertTrue($viewer->canDo('healthSafety.viewAllSites'));
        $this->assertOwnerEntryParity($viewer, $incident, $event, $localOwner, true);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, false);
        $this->revoke($viewer, 'healthSafety.viewAllSites');
        $this->assertTrue($viewer->canDo('healthSafety.viewAllSites'), 'The original actor remains deliberately stale.');
        $this->assertOwnerEntryParity($viewer, $incident, $event, $localOwner, true);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, false);
        $this->grant($viewer, 'healthSafety.viewAllSites');
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, false);
        // The canonical event Site invariant still wins over a viewer's all-Sites grant.
        $foreignOwner->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, true);
        $foreignOwner->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->assertOwnerEntryParity($viewer, $incident, $event, $foreignOwner, false);
    }

    private function ownerChoiceFixture(): array
    {
        $viewer = $this->staff(['incidents.viewAssigned', 'clients.viewAssigned', 'hazards.view', 'hazards.manage']);
        $viewer->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'incidents.viewAny')->sole()->id => ['allowed' => false],
        ]);
        $this->client->supportWorkers()->attach($viewer->id);
        // Handover and action owners must be able to open the event (decision 9), so they hold H&S read too.
        $owner = $this->staff(['hazards.view', 'hazards.manage']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'reported_by' => $viewer->id,
            'hs_event_id' => null, 'control_room_alert_id' => null,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]));
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        return [$viewer, $incident, $event, $owner];
    }

    private function assertOwnerEntryParity(User $viewer, ClientIncident $incident, HsEvent $event, User $owner, bool $eligible): void
    {
        $this->actingAs($viewer)->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.linked_access.health_safety', 'readable')->where('detail.hs_event.id', $event->id)
            ->where('detail.can.raiseCorrectiveAction', true)
            ->where('detail.corrective_action_owners', fn ($owners): bool => collect($owners)->contains('id', $owner->id) === $eligible));
        foreach (['/health-safety/events/'.$event->id, '/health-safety/events?event='.$event->id,
            '/health-safety/corrective-actions?event='.$event->id] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)->where('detail.can.manage', true)
                ->where('detail.assignable_staff', fn ($owners): bool => collect($owners)->contains('id', $owner->id) === $eligible)
                ->where('detail.action_handover.eligible_owners', fn ($owners): bool => collect($owners)->contains('id', $owner->id) === $eligible));
        }
    }
}
