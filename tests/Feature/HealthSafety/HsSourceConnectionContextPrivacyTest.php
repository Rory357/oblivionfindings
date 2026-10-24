<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\EmergencyDrillConnectionsService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidConnectionsService;
use App\Services\HealthSafety\FirstAidSourceProjectionService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\LoneWorkerSessionConnectionsService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

/** Real source readers and Tasks providers; no mocked source authorization. */
class HsSourceConnectionContextPrivacyTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
        $this->app->instance(TaskAggregator::class, new TaskAggregator([new HsCorrectiveActionProvider]));
        foreach (['healthSafety.viewAllSites', 'reports.viewAny', 'sites.viewAll', 'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage'] as $key) {
            $this->grant($key, false);
        }
    }

    public function test_first_aid_connections_keep_actual_tasks_and_archived_treatment_without_copying_narratives(): void
    {
        $source = $this->source();
        $event = $this->treatmentEvent($source);
        $action = $this->action($event);
        $source->delete();
        $rows = app(FirstAidConnectionsService::class)->forViewer($source, $this->manager, '/tasks?assigned=me');
        $this->assertSame([$event->id], array_column($rows['safety_reviews'], 'id'));
        $this->assertSame([$action->id], array_column($rows['corrective_actions'], 'id'));
        $item = app(TaskAggregator::class)->findItemFor($this->manager, 'corrective_action', $action->id, ['include_done' => true]);
        $this->assertSame($item->title, $rows['corrective_actions'][0]['title']);
        $this->assertSame($item->status, $rows['corrective_actions'][0]['status']);
        $this->assertCount(1, app(TaskAggregator::class)->itemsFor($this->manager));
        $this->assertSame(1, app(TaskAggregator::class)->navigationBadgeFor($this->manager)['badge']);
        $this->assertStringNotContainsString('Private source narrative', json_encode($rows));
        $entry = app(FirstAidSourceProjectionService::class)->sourceForReview($event, $this->manager);
        $this->assertSame($source->id, $entry['id']);
        $this->assertSame(5, count($entry));
    }

    public function test_first_aid_entry_uses_current_event_identity_instead_of_stale_source_type_or_tuple(): void
    {
        $source = $this->source();
        $event = $this->treatmentEvent($source);
        $stale = $event->fresh();
        $stale->source_type = HsEvent::class;
        $reader = app(FirstAidSourceProjectionService::class);
        $this->assertSame($source->id, $reader->sourceForReview($stale, $this->manager)['id']);
        $event->forceFill(['staff_id' => $this->firstAider->id])->saveQuietly();
        $this->assertNull($reader->sourceForReview($stale, $this->manager));
        $event->forceFill(['staff_id' => $this->worker->id])->saveQuietly();
        $this->assertSame($source->id, $reader->sourceForReview($stale, $this->manager)['id']);
        $event->forceFill(['event_category' => HsEvent::CATEGORY_INCIDENT])->saveQuietly();
        $this->assertNull($reader->sourceForReview($stale, $this->manager));
    }

    public function test_warmed_first_aid_reader_rechecks_permission_site_hr_and_client_assignment_then_restores(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $source = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id]);
        $event = $this->treatmentEvent($source);
        $reader = app(FirstAidSourceProjectionService::class);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile');
        $this->assertNotNull($reader->sourceForReview($event, $this->manager));
        $this->grant('hazards.view', false);
        $this->assertNull($reader->sourceForReview($event, $this->manager));
        $this->grant('hazards.view', true);
        $this->grant('clients.viewAny', false);
        $this->grant('clients.viewAssigned', true);
        $this->assertNull($reader->sourceForReview($event, $this->manager));
        $client->supportWorkers()->attach($this->manager->id);
        $this->assertNotNull($reader->sourceForReview($event, $this->manager));
        $client->supportWorkers()->detach($this->manager->id);
        $this->assertNull($reader->sourceForReview($event, $this->manager));
        $this->grant('clients.viewAny', true);
        $foreign = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $foreign->id]);
        $this->assertNull($reader->sourceForReview($event, $this->manager));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->sourceSite->id, 'is_active' => false]);
        $this->assertNull($reader->sourceForReview($event, $this->manager));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertNotNull($reader->sourceForReview($event, $this->manager));
    }

    public function test_first_aid_linked_incident_requires_current_independent_permission_and_complete_producer_tuple(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->sourceSite->id,
            'reported_by' => $this->worker->id, 'type' => 'near_miss', 'is_hr_confidential' => false]);
        $source = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id]);
        $source->forceFill(['related_incident_id' => $incident->id])->saveQuietly();
        $own = $this->treatmentEvent($source);
        $linked = $this->event(['source_type' => ClientIncident::class, 'source_id' => $incident->id, 'client_id' => $client->id,
            'staff_id' => $incident->reported_by, 'event_category' => HsEvent::CATEGORY_NEAR_MISS]);
        $reader = app(FirstAidConnectionsService::class);
        $this->assertEqualsCanonicalizing([$own->id, $linked->id], array_column($reader->forViewer($source, $this->manager)['safety_reviews'], 'id'));
        $this->grant('incidents.viewAny', false);
        $this->grant('incidents.viewAssigned', false);
        $this->assertSame([$own->id], array_column($reader->forViewer($source, $this->manager)['safety_reviews'], 'id'));
        $this->grant('incidents.viewAny', true);
        $linked->forceFill(['staff_id' => $this->firstAider->id])->saveQuietly();
        $this->assertSame([$own->id], array_column($reader->forViewer($source, $this->manager)['safety_reviews'], 'id'));
        $linked->forceFill(['staff_id' => $incident->reported_by, 'event_category' => HsEvent::CATEGORY_INCIDENT])->saveQuietly();
        $this->assertSame([$own->id], array_column($reader->forViewer($source, $this->manager)['safety_reviews'], 'id'));
    }

    public function test_first_aid_entry_consumes_same_prepared_callback_and_cannot_reuse_it_after_exit(): void
    {
        $source = $this->source();
        $event = $this->treatmentEvent($source);
        $saved = null;
        $row = HealthSafetyReadContext::within($this->manager, self::permissions(), function ($context) use ($event, &$saved) {
            $saved = $context;

            return app(FirstAidSourceProjectionService::class)->sourceForReview($event, $this->manager, $context);
        });
        $this->assertSame($source->id, $row['id']);
        $this->expectException(LogicException::class);
        app(FirstAidSourceProjectionService::class)->sourceForReview($event, $this->manager, $saved);
    }

    public function test_first_aid_entry_rejects_unprepared_or_foreign_viewer_context_without_a_fresh_fallback(): void
    {
        $event = $this->treatmentEvent($this->source());
        foreach ([FirstAidAccessService::PERMISSIONS, self::permissions()] as $index => $permissions) {
            try {
                HealthSafetyReadContext::within($this->manager, $permissions, fn ($context) =>
                    app(FirstAidSourceProjectionService::class)->sourceForReview($event, $index === 0 ? $this->manager : $this->worker, $context));
                $this->fail('A source entry must not refresh or substitute missing evidence.');
            } catch (LogicException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_drill_finding_rereads_added_and_removed_link_instead_of_the_preloaded_child(): void
    {
        $drill = $this->drill();
        $event = $this->drillEvent($drill);
        $action = $this->action($event);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id, 'created_by' => $this->manager->id,
            'hs_corrective_action_id' => null]);
        $stale = $finding->fresh();
        $finding->forceFill(['hs_corrective_action_id' => $action->id])->saveQuietly();
        $reader = app(EmergencyDrillConnectionsService::class);
        $this->assertSame($action->id, $reader->forFinding($drill, $stale, $this->manager)['id']);
        $finding->forceFill(['hs_corrective_action_id' => null])->saveQuietly();
        $this->assertNull($reader->forFinding($drill, $stale, $this->manager));
    }

    public function test_drill_source_tuple_is_complete_before_action_content_and_unrelated_private_source_discovery(): void
    {
        $drill = $this->drill();
        $valid = $this->drillEvent($drill);
        $validAction = $this->action($valid);
        $wrong = $this->drillEvent($drill, ['staff_id' => $this->worker->id]);
        $privateAction = $this->action($wrong, ['title' => 'Private wrong action', 'description' => 'Private wrong description']);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id, 'created_by' => $this->manager->id,
            'hs_corrective_action_id' => $privateAction->id]);
        $this->unrelatedPrivateSources();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $reader = app(EmergencyDrillConnectionsService::class);
            $rows = $reader->forViewer($drill, $this->manager);
            $this->assertSame([$valid->id], array_column($rows['safety_reviews'], 'id'));
            $this->assertSame([$validAction->id], array_column($rows['corrective_actions'], 'id'));
            $this->assertNull($reader->forFinding($drill, $finding, $this->manager));
            $this->assertStringNotContainsString('Private wrong', json_encode($rows));
            $this->assertNoUnrelatedPrivateSourceSql(DB::getQueryLog());
            foreach (DB::getQueryLog() as $query) {
                if (str_contains($query['query'], 'from `hs_corrective_actions`') && in_array($privateAction->id, $query['bindings'], true)) {
                    $this->assertStringNotContainsString('select *', strtolower($query['query']));
                    $this->assertStringNotContainsString('description', strtolower($query['query']));
                }
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('open', $privateAction->fresh()->status);
    }

    public function test_drill_links_preserve_full_list_and_previous_next_provenance_with_fresh_revoke_restore(): void
    {
        $previous = $this->drill();
        $drill = $this->drill(['previous_drill_id' => $previous->id]);
        $next = $this->drill(['previous_drill_id' => $drill->id]);
        $this->drill(['previous_drill_id' => $drill->id, 'site_id' => Site::factory()->create()->id]);
        $ids = [];
        for ($index = 0; $index < 12; $index++) {
            $ids[] = $this->drillEvent($drill)->id;
        }
        $reader = app(EmergencyDrillConnectionsService::class);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile');
        $rows = $reader->forViewer($drill, $this->manager);
        $this->assertSame($ids, array_column($rows['safety_reviews'], 'id'));
        $this->assertSame($previous->id, $rows['previous_drill']['id']);
        $this->assertSame([$next->id], array_column($rows['next_drills'], 'id'));
        $this->grant('hazards.view', false);
        $this->assertHttpDenied(fn () => $reader->forViewer($drill, $this->manager), 403);
        $this->grant('hazards.view', true);
        $this->assertCount(12, $reader->forViewer($drill, $this->manager)['safety_reviews']);
        $drill->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->assertHttpDenied(fn () => $reader->forViewer($drill, $this->manager), 404);
        $drill->forceFill(['site_id' => $this->sourceSite->id])->saveQuietly();
        $this->assertCount(12, $reader->forViewer($drill, $this->manager)['safety_reviews']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->assertHttpDenied(fn () => $reader->forViewer($drill, $this->manager), 403);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertCount(12, $reader->forViewer($drill, $this->manager)['safety_reviews']);
    }

    public function test_lone_worker_independent_reviews_and_incident_remain_visible_when_control_room_is_denied(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $source = $this->loneSession(['client_id' => $client->id]);
        $alert = $this->loneAlert($source);
        $event = $this->event(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class,
            'source_id' => $alert->id, 'client_id' => $client->id]);
        $action = $this->action($event);
        $incident = ClientIncident::factory()->create(['control_room_alert_id' => $alert->id, 'site_id' => $this->sourceSite->id,
            'client_id' => $client->id, 'reported_by' => $this->worker->id, 'is_hr_confidential' => false]);
        $reader = app(LoneWorkerSessionConnectionsService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $rows = $reader->forViewer($source, $this->manager);
            foreach (DB::getQueryLog() as $query) {
                if (str_contains($query['query'], 'from `control_room_alerts`')) {
                    // Independent Tasks policy may execute an explicitly empty
                    // CR query. It must never hydrate a denied response payload.
                    if (str_contains(strtolower($query['query']), 'select *')) {
                        $this->assertStringContainsString('1 = 0', strtolower($query['query']));
                    }
                    $this->assertStringNotContainsString('`notes`', strtolower($query['query']));
                }
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([], $rows['control_room']);
        $this->assertSame([$event->id], array_column($rows['safety_reviews'], 'id'));
        $this->assertSame([$incident->id], array_column($rows['incidents'], 'id'));
        $this->assertSame([$action->id], array_column($rows['corrective_actions'], 'id'));
        $this->grant('controlRoom.viewAny', true);
        $this->grant('controlRoom.alerts.view', true);
        $this->assertSame([$alert->id], array_column($reader->forViewer($source, $this->manager)['control_room'], 'id'));
        $this->grant('controlRoom.alerts.view', false);
        $this->assertSame([], $reader->forViewer($source, $this->manager)['control_room']);
        $this->assertStringNotContainsString('Private response narrative', json_encode($rows));
    }

    public function test_lone_worker_wrong_alert_or_review_tuple_never_discovers_unrelated_private_source_content(): void
    {
        $source = $this->loneSession();
        $alert = $this->loneAlert($source);
        $valid = $this->event(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class, 'source_id' => $alert->id]);
        $this->event(['control_room_alert_id' => $alert->id, 'site_id' => Site::factory()->create()->id]);
        $this->event(['control_room_alert_id' => $alert->id, 'client_id' => Client::factory()->create(['site_id' => $this->sourceSite->id])->id]);
        $foreign = $this->loneAlert($this->loneSession());
        $foreign->forceFill(['context' => $alert->context])->saveQuietly();
        $this->unrelatedPrivateSources();
        $reader = app(LoneWorkerSessionConnectionsService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $rows = $reader->forViewer($source, $this->manager);
            $this->assertSame([$valid->id], array_column($rows['safety_reviews'], 'id'));
            $this->assertNoUnrelatedPrivateSourceSql(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_lone_worker_warmed_source_rechecks_staff_site_and_view_permission_without_losing_owned_access(): void
    {
        $source = $this->loneSession();
        $alert = $this->loneAlert($source);
        $event = $this->event(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class, 'source_id' => $alert->id]);
        $reader = app(LoneWorkerSessionConnectionsService::class);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame([$event->id], array_column($reader->forViewer($source, $this->manager)['safety_reviews'], 'id'));
        $this->grant('hazards.view', false);
        $this->assertHttpDenied(fn () => $reader->forViewer($source, $this->manager), 403);
        $this->grant('hazards.view', true);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->assertHttpDenied(fn () => $reader->forViewer($source, $this->manager), 403);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertCount(1, $reader->forViewer($source, $this->manager)['safety_reviews']);
        $this->worker->load('permissionOverrides', 'hrEmployeeProfile');
        $this->assertNotNull($reader->forViewer($source, $this->worker));
        $source->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->assertHttpDenied(fn () => $reader->forViewer($source, $this->manager), 403);
    }

    public function test_lone_worker_context_bridge_requires_current_internal_staff_and_actual_approved_sites(): void
    {
        $access = app(LoneWorkerSessionAccessService::class);
        $keys = array_values(array_unique([...self::permissions(), ...LoneWorkerSessionAccessService::PERMISSIONS]));
        $read = fn (User $viewer) => HealthSafetyReadContext::within($viewer, $keys, fn ($context) =>
            $access->withReadContext($context, fn ($current, $reads, $sites) => ['actor_id' => (int) $current->id, 'sites' => $sites]));
        $this->assertSame($this->manager->id, $read($this->manager)['actor_id']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->assertHttpDenied(fn () => $read($this->manager), 403);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->manager->forceFill(['role' => 'external_clinician'])->saveQuietly();
        $this->assertHttpDenied(fn () => $read($this->manager), 403);
        $this->manager->forceFill(['role' => 'support_worker'])->saveQuietly();
        $this->sourceSite->forceFill(['is_active' => false])->saveQuietly();
        $this->assertHttpDenied(fn () => $read($this->manager), 403);
        $this->sourceSite->forceFill(['is_active' => true])->saveQuietly();
        $this->assertSame([$this->sourceSite->id], $read($this->manager)['sites']);
    }

    public function test_drill_finding_from_another_source_is_concealed_before_its_private_action_is_read(): void
    {
        $drill = $this->drill();
        $foreign = $this->drill();
        $action = $this->action($this->drillEvent($foreign), ['description' => 'Foreign private corrective narrative']);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $foreign->id, 'created_by' => $this->manager->id,
            'hs_corrective_action_id' => $action->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertHttpDenied(fn () => app(EmergencyDrillConnectionsService::class)->forFinding($drill, $finding, $this->manager), 404);
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('from `hs_corrective_actions`', strtolower($query['query']));
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_lone_worker_context_bridge_rejects_missing_preparation_and_expired_evidence(): void
    {
        $access = app(LoneWorkerSessionAccessService::class);
        try {
            HealthSafetyReadContext::within($this->manager, FirstAidAccessService::PERMISSIONS, fn ($context) =>
                $access->withReadContext($context, fn () => ['visible' => true]));
            $this->fail('The bridge must not substitute a fresh lookup for missing preparation.');
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $captured = null;
        HealthSafetyReadContext::within($this->manager, LoneWorkerSessionAccessService::PERMISSIONS, function ($context) use (&$captured, $access) {
            $captured = $context;

            return $access->withReadContext($context, fn ($current) => ['actor_id' => (int) $current->id]);
        });
        $this->expectException(LogicException::class);
        $access->withReadContext($captured, fn () => ['visible' => true]);
    }

    public function test_first_aid_exact_candidates_do_not_hydrate_unrelated_source_families_or_foreign_reviews(): void
    {
        $source = $this->source();
        $valid = $this->treatmentEvent($source);
        $this->treatmentEvent($this->source());
        $wrong = $this->treatmentEvent($source);
        $wrong->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->unrelatedPrivateSources();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $rows = app(FirstAidConnectionsService::class)->forViewer($source, $this->manager);
            $entry = app(FirstAidSourceProjectionService::class)->sourceForReview($valid, $this->manager);
            $this->assertSame([$valid->id], array_column($rows['safety_reviews'], 'id'));
            $this->assertSame($source->id, $entry['id']);
            $this->assertNoUnrelatedPrivateSourceSql(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }

    private function event(array $values): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $this->sourceSite->id, 'client_id' => null, 'staff_id' => null,
            'asset_id' => null, 'created_by' => $this->manager->id, 'severity' => 'low', 'idempotency_key' => (string) Str::uuid(), ...$values]);
    }

    private function treatmentEvent(FirstAidRecord $source): HsEvent
    {
        return $this->event(['source_type' => FirstAidRecord::class, 'source_id' => $source->id, 'event_category' => HsEvent::CATEGORY_INJURY,
            'site_id' => $source->site_id, 'client_id' => $source->client_id, 'staff_id' => $source->treated_person_type === 'staff' ? $source->treated_person_id : null]);
    }

    private function drill(array $values = []): EmergencyDrill
    {
        return EmergencyDrill::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id, ...$values]);
    }

    private function drillEvent(EmergencyDrill $source, array $values = []): HsEvent
    {
        return $this->event(['source_type' => EmergencyDrill::class, 'source_id' => $source->id, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE, ...$values]);
    }

    private function action(HsEvent $event, array $values = []): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'assigned_to_user_id' => $this->manager->id,
            'created_by' => $this->manager->id, 'description' => 'Private source narrative', 'due_date' => now()->subDay()->toDateString(), ...$values]);
    }

    private function loneSession(array $values = []): LoneWorkerSession
    {
        return LoneWorkerSession::create(['user_id' => $this->worker->id, 'site_id' => $this->sourceSite->id, 'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHour(), 'check_in_interval_minutes' => 30, 'status' => 'active', 'created_by' => $this->worker->id, ...$values]);
    }

    private function unrelatedPrivateSources(): void
    {
        // Real retained legacy sources in the same readable Site make an accidental
        // global source discovery observable; these grants still do not select them.
        $this->grant('restraints.view', true);
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $restraint = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $client->id,
            'site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id, 'restraint_description' => 'Unrelated private restraint narrative']));
        $this->event(['source_type' => RestraintEvent::class, 'source_id' => $restraint->id, 'event_category' => HsEvent::CATEGORY_RESTRAINT,
            'client_id' => $client->id, 'staff_id' => $this->manager->id]);
        $injury = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create(['user_id' => $this->worker->id,
            'site_id' => $this->sourceSite->id, 'description' => 'Unrelated injury occurrence', 'immediate_treatment' => 'Unrelated private treatment']));
        $this->event(['source_type' => WorkplaceInjury::class, 'source_id' => $injury->id, 'event_category' => HsEvent::CATEGORY_INJURY,
            'staff_id' => $this->worker->id]);
    }

    private function loneAlert(LoneWorkerSession $session): ControlRoomAlert
    {
        $facts = ['source_module' => 'lone_worker', 'signal_type' => LoneWorkerSignalService::TYPE_EMERGENCY,
            'lone_worker_session_id' => $session->id, 'worker_user_id' => $session->user_id, 'site_id' => $session->site_id, 'client_id' => $session->client_id];
        $source = SignalSource::firstOrCreate(['slug' => 'lone_worker'], ['name' => 'Lone worker safety', 'status' => 'active']);
        $signal = Signal::create(['signal_source_id' => $source->id, 'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
            'site_id' => $session->site_id, 'client_id' => $session->client_id, 'occurred_at' => now(), 'payload' => [],
            'normalized_data' => $facts, 'status' => 'processed', 'idempotency_key' => (string) Str::uuid()]);
        $alert = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'origin_signal_id' => $signal->id,
            'alert_type' => LoneWorkerSignalService::canonicalAlertType(LoneWorkerSignalService::TYPE_EMERGENCY), 'site_id' => $session->site_id,
            'client_id' => $session->client_id, 'context' => ['signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY, 'normalized_data' => $facts],
            'notes' => 'Private response narrative']);
        $signal->forceFill(['alert_id' => $alert->id])->save();

        return $alert;
    }

    private static function permissions(): array
    {
        return array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS]));
    }

    private function assertNoUnrelatedPrivateSourceSql(array $queries): void
    {
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('from `restraint_events`', $sql);
            $this->assertStringNotContainsString('from `workplace_injuries`', $sql);
            $this->assertStringNotContainsString('from `workplace_injury_source_versions`', $sql);
            $this->assertStringNotContainsString('from `behaviour_support_plan_versions`', $sql);
        }
    }

    private function assertHttpDenied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Current source denial must survive warmed caller state.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(404, $status);
        }
    }
}
