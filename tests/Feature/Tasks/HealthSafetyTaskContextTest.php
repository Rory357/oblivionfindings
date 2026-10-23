<?php

namespace Tests\Feature\Tasks;

use App\Exceptions\TaskProviderNavigationException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidSourceDelivery;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\ProcedureReviewRequest;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidDeliveryService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\Providers\EmergencyDrillFindingProvider;
use App\Services\Tasks\Providers\EmergencyDrillProvider;
use App\Services\Tasks\Providers\FirstAidFollowupProvider;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\Providers\HsEventProvider;
use App\Services\Tasks\Providers\HsInvestigationProvider;
use App\Services\Tasks\Providers\ProcedureReviewRequestProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as BoundRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

class HealthSafetyTaskContextTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    /** @var ContextualHealthSafetyTaskProvider[] */
    private array $providers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
        $this->grant('procedures.view', true);
        $this->grant('procedures.manage', true);
        $this->providers = [new HsEventProvider, new HsInvestigationProvider, new HsCorrectiveActionProvider,
            new FirstAidFollowupProvider, new ProcedureReviewRequestProvider, new EmergencyDrillProvider, new EmergencyDrillFindingProvider];
    }

    private function grant(string $key, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $this->worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }

    /** @return array<string, int> */
    private function rows(bool $done = false): array
    {
        $event = HsEvent::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id,
            'owner_user_id' => $this->worker->id, 'status' => $done ? HsEvent::STATUS_CLOSED : HsEvent::STATUS_OPEN]);
        $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'lead_investigator_id' => $this->worker->id, 'status' => $done ? HsInvestigation::STATUS_COMPLETED : HsInvestigation::STATUS_IN_PROGRESS]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'assigned_to_user_id' => $this->worker->id, 'status' => $done ? HsCorrectiveAction::STATUS_CLOSED : HsCorrectiveAction::STATUS_OPEN]);
        $record = $this->source();
        $followup = FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'created_by' => $this->manager->id,
            'assigned_to_user_id' => $this->worker->id, 'notes' => 'Context follow-up', 'completed_at' => $done ? now() : null]);
        $drill = EmergencyDrill::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id,
            'conducted_by' => $this->worker->id, 'title' => 'ContextContractDrill', 'status' => $done ? 'completed' : 'scheduled']);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id, 'created_by' => $this->manager->id,
            'assigned_to' => $this->worker->id, 'due_date' => '2026-10-11', 'status' => $done ? 'resolved' : 'open']);
        $procedure = SafeWorkProcedure::factory()->approved()->create(['title' => 'Context procedure', 'applicable_roles' => [],
            'applicable_sites' => [$this->sourceSite->id], 'created_by' => $this->worker->id, 'approved_by' => $this->worker->id])->refresh();
        $review = ProcedureReviewRequest::create(['safe_work_procedure_id' => $procedure->id, 'hs_event_id' => $event->id,
            'source_site_id' => $event->site_id, 'requested_version_id' => $procedure->published_version_id,
            'requested_by_user_id' => $this->manager->id, 'assigned_to_user_id' => $this->worker->id, 'note' => 'Context procedure review',
            'idempotency_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', (string) $event->id), 'requested_at' => now(),
            'status' => $done ? 'resolved' : 'requested', 'lock_version' => 1]);

        return ['hs_event' => $event->id, 'hs_investigation' => $investigation->id, 'corrective_action' => $action->id,
            'first_aid_followup' => $followup->id, 'procedure_review_request' => $review->id,
            'emergency_drill' => $drill->id, 'emergency_drill_finding' => $finding->id];
    }

    public function test_all_seven_contextual_adapters_preserve_standalone_dtos_exact_item_filters_and_completed_parity(): void
    {
        $open = $this->rows();
        $done = $this->rows(true);
        $this->worker->unsetRelation('permissionOverrides');
        foreach ($this->providers as $provider) {
            foreach ([$open, $done] as $ids) {
                $filters = ['id' => $ids[$provider->sourceKey()], 'include_done' => true, 'return_to' => '/tasks?assigned=me'];
                $standalone = $provider->authorizedTasks($this->worker, $filters);
                $this->assertCount(1, $standalone, $provider::class);
                $contextual = HealthSafetyReadContext::within($this->worker,
                    array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
                        ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS]),
                    fn ($context) => $provider->authorizedTasksInContext($this->worker, $filters, $context));
                $this->assertEquals($standalone, $contextual, $provider::class);
                $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $done[$provider->sourceKey()]]));
            }
        }
        $aggregator = new TaskAggregator($this->providers);
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
        $this->assertCount(14, $aggregator->itemsFor($this->worker, ['include_done' => true]));
        $subset = $aggregator->itemsFor($this->worker, ['sources' => ['first_aid_followup', 'emergency_drill']]);
        $this->assertEqualsCanonicalizing(['first_aid_followup', 'emergency_drill'], array_column($subset, 'source'));
        $search = $aggregator->itemsFor($this->worker, ['q' => 'ContextContractDrill']);
        $this->assertSame(['emergency_drill-'.$open['emergency_drill']], array_column($search, 'id'));
        $finding = collect($aggregator->itemsFor($this->worker))->firstWhere('source', 'emergency_drill_finding');
        $this->assertSame('2026-10-11T23:59:59+13:00', $finding->dueAt);
    }

    public function test_navigation_matches_full_feed_for_mixed_assignment_due_and_completed_work_without_poisoning_feed_or_search(): void
    {
        $mine = $this->rows();
        $other = $this->rows();
        $this->rows(true);
        HsEvent::findOrFail($other['hs_event'])->update(['owner_user_id' => $this->manager->id]);
        HsInvestigation::findOrFail($other['hs_investigation'])->update(['lead_investigator_id' => $this->manager->id, 'target_completion_date' => now()->subDay()->toDateString()]);
        HsCorrectiveAction::findOrFail($other['corrective_action'])->update(['assigned_to_user_id' => null, 'due_date' => now()->subDay()->toDateString()]);
        FirstAidFollowup::findOrFail($other['first_aid_followup'])->update(['assigned_to_user_id' => null, 'due_at' => now()->subMinute()]);
        ProcedureReviewRequest::findOrFail($other['procedure_review_request'])->update(['assigned_to_user_id' => $this->manager->id]);
        EmergencyDrill::findOrFail($other['emergency_drill'])->update(['conducted_by' => $this->manager->id]);
        EmergencyDrillFinding::findOrFail($other['emergency_drill_finding'])->update(['assigned_to' => $this->manager->id]);
        $aggregator = new TaskAggregator($this->providers);
        $this->assertSame(['view' => true, 'badge' => 10, 'degraded' => false], $aggregator->navigationBadgeFor($this->worker));
        $this->assertSame(10, $aggregator->badgeCountFor($this->worker));
        $items = $aggregator->itemsFor($this->worker);
        $this->assertCount(11, $items);
        $mineFollowup = collect($items)->firstWhere('id', 'first_aid_followup-'.$mine['first_aid_followup']);
        $this->assertSame('Context follow-up', $mineFollowup->description);
        $this->assertSame($this->worker->name, $mineFollowup->assignee['name']);
        $this->assertNotNull($mineFollowup->link);
        $this->assertCount(18, $aggregator->itemsFor($this->worker, ['include_done' => true]));
        $this->assertSame(['emergency_drill-'.$mine['emergency_drill']],
            array_column($aggregator->itemsFor($this->worker, ['q' => 'ContextContractDrill']), 'id'));

        // A corrupt staff/Client tuple cannot contribute even an anonymous overdue counter.
        $followup = FirstAidFollowup::findOrFail($other['first_aid_followup']);
        $followup->record->forceFill(['client_id' => Client::factory()->create(['site_id' => $this->sourceSite->id])->id])->saveQuietly();
        $this->assertSame(9, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame(9, $aggregator->badgeCountFor($this->worker));

        // Neither a future unassigned row nor an other-owned row with no due date is mine or overdue.
        HsInvestigation::findOrFail($other['hs_investigation'])->update(['target_completion_date' => null]);
        HsCorrectiveAction::findOrFail($other['corrective_action'])->update(['due_date' => now()->addDay()->toDateString()]);
        $this->assertSame(7, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame(7, $aggregator->badgeCountFor($this->worker));
    }

    public function test_navigation_preserves_procedure_version_source_site_and_archived_working_denial(): void
    {
        $ids = $this->rows();
        $row = ProcedureReviewRequest::findOrFail($ids['procedure_review_request']);
        $versionId = $row->requested_version_id;
        $procedure = $row->procedure;
        $aggregator = new TaskAggregator([new ProcedureReviewRequestProvider]);
        $this->assertSame(1, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame(1, $aggregator->badgeCountFor($this->worker));
        $foreign = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => [],
            'applicable_sites' => [$this->sourceSite->id], 'created_by' => $this->worker->id, 'approved_by' => $this->worker->id])->refresh();
        $row->update(['requested_version_id' => $foreign->published_version_id]);
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $row->update(['requested_version_id' => $versionId, 'source_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $row->update(['source_site_id' => $this->sourceSite->id]);
        $this->assertSame(1, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $procedure->update(['status' => 'archived']);
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame([], $aggregator->itemsFor($this->worker));
    }

    public function test_navigation_retains_the_original_top_three_hundred_authorized_event_cutoff(): void
    {
        $old = HsEvent::factory()->create(['site_id' => $this->sourceSite->id, 'client_id' => null,
            'owner_user_id' => $this->worker->id, 'created_by' => $this->manager->id, 'status' => HsEvent::STATUS_OPEN, 'occurred_at' => now()->subDay()]);
        HsEvent::factory()->count(300)->createQuietly(['site_id' => $this->sourceSite->id, 'client_id' => null,
            'owner_user_id' => $this->manager->id, 'created_by' => $this->manager->id, 'status' => HsEvent::STATUS_OPEN, 'occurred_at' => now()]);
        $aggregator = new TaskAggregator([new HsEventProvider]);
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertCount(300, $aggregator->itemsFor($this->worker));
        $this->assertNotContains('hs_event-'.$old->id, array_column($aggregator->itemsFor($this->worker), 'id'));
        $old->update(['occurred_at' => now()->addMinute()]);
        $this->assertSame(1, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->assertSame(1, $aggregator->badgeCountFor($this->worker));
        $this->assertContains('hs_event-'.$old->id, array_column($aggregator->itemsFor($this->worker), 'id'));
    }

    public function test_navigation_retains_finding_end_of_day_at_the_new_zealand_daylight_saving_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-09-27T23:59:58+13:00'));
        $drill = EmergencyDrill::factory()->create(['site_id' => $this->sourceSite->id, 'conducted_by' => $this->worker->id, 'status' => 'in_progress']);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id,
            'assigned_to' => $this->worker->id, 'due_date' => '2026-09-27', 'status' => 'open']);
        $provider = new EmergencyDrillFindingProvider;
        $navigation = fn () => HealthSafetyReadContext::within($this->worker, EmergencyDrillAccessService::PERMISSIONS,
            fn ($context) => $provider->authorizedNavigationTasksInContext($this->worker, $context));
        $items = $navigation();
        $this->assertCount(1, $items);
        $this->assertSame('emergency_drill_finding-'.$finding->id, $items[0]->id);
        $this->assertSame('2026-09-27T23:59:59+13:00', $items[0]->dueAt);
        $this->assertFalse($items[0]->isOverdue());
        $this->assertSame($provider->authorizedTasks($this->worker)[0]->dueAt, $items[0]->dueAt);
        $this->travelTo(Carbon::parse('2026-09-28T00:00:00+13:00'));
        $this->assertTrue($navigation()[0]->isOverdue());
        $drill->update(['status' => 'cancelled']);
        $this->assertSame([], $navigation());
        $this->assertSame([], $provider->authorizedTasks($this->worker));
    }

    private function navigationRequest(int $eventId, ?User $viewer = null): Request
    {
        $request = Request::create('/health-safety/events/'.$eventId, 'GET');
        $route = new BoundRoute('GET', 'health-safety/events/{hsEvent}', fn () => null);
        $route->name('health-safety.events.show')->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $viewer ?? $this->worker);

        return $request;
    }

    private function detachedNavigation(Request $request, ?User $viewer = null): array
    {
        return (new \ReflectionMethod(HandleInertiaRequests::class, 'requestTaskNavigation'))
            ->invoke(app(HandleInertiaRequests::class), $request, $viewer ?? $this->worker);
    }

    public function test_event_show_json_and_partial_navigation_ignore_ttl_and_recheck_client_site_and_rbac_between_requests(): void
    {
        $ids = $this->rows();
        $other = $this->rows();
        $secondary = Site::factory()->create(['is_active' => true]);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => [$secondary->id]]);
        EmergencyDrill::findOrFail($other['emergency_drill'])->update(['site_id' => $secondary->id]);
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $client->supportWorkers()->attach($this->worker->id);
        $this->grant('clients.viewAny', false);
        $this->grant('clients.viewAssigned', true);
        FirstAidFollowup::findOrFail($other['first_aid_followup'])->record
            ->forceFill(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id])->saveQuietly();
        $this->app->instance(TaskAggregator::class, new TaskAggregator($this->providers));
        $cacheKey = 'user:'.$this->worker->id.':task-nav:v1';
        $cached = ['view' => false, 'badge' => 999, 'badgeDegraded' => false];
        Cache::put($cacheKey, $cached, 90);
        $url = '/health-safety/events/'.$ids['hs_event'];
        $assertNavigation = function (int $badge, array $headers = []) use ($url) {
            return $this->actingAs($this->worker)->getJson($url, ['X-Inertia' => 'true', ...$headers])
                ->assertOk()->assertJsonPath('props.auth.can.tasks', ['view' => true, 'badge' => $badge, 'badgeDegraded' => false]);
        };
        $assertNavigation(14);
        $assertNavigation(14, ['X-Inertia-Partial-Component' => 'health-safety/events/show', 'X-Inertia-Partial-Data' => 'auth.can.tasks'])
            ->assertJsonMissingPath('props.detail');
        $client->supportWorkers()->detach($this->worker->id);
        $assertNavigation(13);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        // HR observer may invalidate the old global cache; force it stale again to exercise the route bypass.
        Cache::put($cacheKey, $cached, 90);
        $assertNavigation(11);
        $this->grant('procedures.manage', false);
        $assertNavigation(9, ['X-Inertia-Partial-Component' => 'health-safety/events/show', 'X-Inertia-Partial-Data' => 'auth.can.tasks']);
        $this->assertSame($cached, Cache::get($cacheKey));
    }

    public function test_missing_invalid_wrong_actor_and_wrong_event_projection_fall_back_fresh_after_callback_ends(): void
    {
        $ids = $this->rows();
        $aggregator = new TaskAggregator($this->providers);
        $this->app->instance(TaskAggregator::class, $aggregator);
        Cache::put('user:'.$this->worker->id.':task-nav:v1', ['view' => false, 'badge' => 999, 'badgeDegraded' => false], 90);
        $expected = ['view' => true, 'badge' => 7, 'badgeDegraded' => false];
        $request = $this->navigationRequest($ids['hs_event']);
        $this->assertSame($expected, $this->detachedNavigation($request));
        $projection = HealthSafetyReadContext::within($this->worker,
            [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
                ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS],
            fn ($context) => $aggregator->navigationBadgeInContext($this->worker, $context));
        $request->attributes->set(TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, $projection);
        $request->attributes->set(TaskAggregator::NAVIGATION_USER_ATTRIBUTE, (int) $this->worker->id);
        $request->attributes->set(TaskAggregator::NAVIGATION_EVENT_ATTRIBUTE, (int) $ids['hs_event']);
        $this->assertSame($expected, $this->detachedNavigation($request));

        foreach ([
            [TaskAggregator::NAVIGATION_USER_ATTRIBUTE, (int) $this->manager->id],
            [TaskAggregator::NAVIGATION_EVENT_ATTRIBUTE, (int) $ids['hs_event'] + 1],
            [TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, ['view' => true, 'badge' => '999', 'degraded' => false]],
            [TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, ['view' => true, 'badge' => -1, 'degraded' => false]],
        ] as [$key, $invalid]) {
            $request->attributes->set(TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, ['view' => true, 'badge' => 999, 'degraded' => false]);
            $request->attributes->set(TaskAggregator::NAVIGATION_USER_ATTRIBUTE, (int) $this->worker->id);
            $request->attributes->set(TaskAggregator::NAVIGATION_EVENT_ATTRIBUTE, (int) $ids['hs_event']);
            $request->attributes->set($key, $invalid);
            $this->assertSame($expected, $this->detachedNavigation($request));
        }

        // A late/deferred consumer has only detached scalars; a missing projection rechecks fresh authority.
        $request->attributes->remove(TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE);
        $this->grant('hazards.view', false);
        $this->assertSame(['view' => false, 'badge' => 0, 'badgeDegraded' => false], $this->detachedNavigation($request));
    }

    public function test_navigation_fallback_preserves_legitimate_view_on_provider_error_without_using_stale_cache(): void
    {
        Exceptions::fake();
        $provider = new class implements TaskProvider
        {
            public function sourceKey(): string
            {
                return 'failed_navigation';
            }

            public function label(): string
            {
                return 'Failed navigation';
            }

            public function canView(User $viewer): bool
            {
                return true;
            }

            public function authorizedTasks(User $viewer, array $filters = []): array
            {
                throw new RuntimeException('Navigation source failed');
            }
        };
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$provider]));
        Cache::put('user:'.$this->worker->id.':task-nav:v1', ['view' => false, 'badge' => 999, 'badgeDegraded' => false], 90);
        $this->assertSame(['view' => true, 'badge' => 0, 'badgeDegraded' => true], $this->detachedNavigation($this->navigationRequest(1)));
        Exceptions::assertReported(fn (TaskProviderNavigationException $exception) => $exception->sourceKey === 'failed_navigation');
    }

    public function test_active_context_navigation_returns_only_scalars_and_cannot_be_reused_after_callback(): void
    {
        $this->rows();
        $aggregator = new TaskAggregator($this->providers);
        $escaped = null;
        $projection = HealthSafetyReadContext::within($this->worker,
            [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
                ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS],
            function ($context) use ($aggregator, &$escaped) {
                $escaped = $context;

                return $aggregator->navigationBadgeInContext($this->worker, $context);
            });
        $this->assertSame(['view' => true, 'badge' => 7, 'degraded' => false], $projection);
        $this->expectException(LogicException::class);
        $aggregator->navigationBadgeInContext($this->worker, $escaped);
    }

    public function test_external_clinician_share_and_other_route_task_cache_policy_remain_unchanged(): void
    {
        $middleware = app(HandleInertiaRequests::class);
        $external = User::factory()->create(['role' => 'external_clinician', 'approved_at' => now()]);
        $request = $this->navigationRequest(1, $external);
        $this->assertSame([], $middleware->share($request)['auth']['can']);
        $cached = ['view' => true, 'badge' => 123, 'badgeDegraded' => false];
        Cache::put('user:'.$this->worker->id.':task-nav:v1', $cached, 90);
        $this->assertSame($cached, (new \ReflectionMethod(HandleInertiaRequests::class, 'taskNavigation'))->invoke($middleware, $this->worker));
    }

    public function test_reused_aggregator_and_loaded_actor_recheck_module_revocation_and_regrant(): void
    {
        $this->rows();
        $aggregator = new TaskAggregator($this->providers);
        $this->worker->load('permissionOverrides', 'roles.permissions');
        $this->assertTrue($this->worker->canDo('hazards.view'));
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
        $this->grant('hazards.view', false);
        $this->assertTrue($this->worker->canDo('hazards.view'), 'The caller deliberately retains loaded evidence.');
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $this->assertSame(['view' => false, 'badge' => 0, 'degraded' => false], $aggregator->navigationBadgeFor($this->worker));
        $this->grant('hazards.view', true);
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
    }

    public function test_reused_aggregator_rechecks_current_site_membership_and_site_status(): void
    {
        $this->rows();
        $aggregator = new TaskAggregator($this->providers);
        $this->worker->load('hrEmployeeProfile');
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
        $this->assertSame(7, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
        $this->sourceSite->forceFill(['archived' => true, 'archived_at' => now()])->saveQuietly();
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $this->sourceSite->forceFill(['archived' => false, 'archived_at' => null])->saveQuietly();
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
    }

    public function test_strict_source_eligibility_denial_preserves_ordinary_hs_with_its_existing_site_bypass(): void
    {
        $ids = $this->rows();
        $this->grant('healthSafety.viewAllSites', true);
        $aggregator = new TaskAggregator($this->providers);
        $this->assertCount(7, $aggregator->itemsFor($this->worker));
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertEqualsCanonicalizing(['hs_event-'.$ids['hs_event'], 'hs_investigation-'.$ids['hs_investigation'], 'corrective_action-'.$ids['corrective_action']],
            array_column($aggregator->itemsFor($this->worker), 'id'));
        $this->assertSame(3, $aggregator->navigationBadgeFor($this->worker)['badge']);
    }

    public function test_source_client_assignment_is_rechecked_for_all_treatment_derived_task_sources(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $this->grant('clients.viewAny', false);
        $this->grant('clients.viewAssigned', true);
        $client->supportWorkers()->attach($this->worker->id);
        $record = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id, 'ambulance_called' => true]);
        $delivery = FirstAidSourceDelivery::where('first_aid_record_id', $record->id)->where('purpose', 'source_review')->sole();
        app(FirstAidDeliveryService::class)->deliver($delivery->id);
        $event = HsEvent::findOrFail($delivery->fresh()->hs_event_id);
        HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id, 'lead_investigator_id' => $this->worker->id]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id, 'assigned_to_user_id' => $this->worker->id]);
        FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'assigned_to_user_id' => $this->worker->id, 'created_by' => $this->manager->id]);
        $aggregator = new TaskAggregator(array_slice($this->providers, 0, 4));
        $this->assertCount(4, $aggregator->itemsFor($this->worker));
        $this->assertSame($aggregator->badgeCountFor($this->worker), $aggregator->navigationBadgeFor($this->worker)['badge']);
        $client->supportWorkers()->detach($this->worker->id);
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $client->supportWorkers()->attach($this->worker->id);
        $this->assertCount(4, $aggregator->itemsFor($this->worker));
    }

    public function test_standalone_hs_adapters_recheck_loaded_grant_denial_and_restore_exact_completed_projection(): void
    {
        $open = $this->rows();
        $done = $this->rows(true);
        $providers = array_slice($this->providers, 0, 3);
        $this->worker->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        foreach ($providers as $provider) {
            $filters = ['id' => $open[$provider->sourceKey()], 'return_to' => '/tasks?assigned=me'];
            $this->assertCount(1, $provider->authorizedTasks($this->worker, $filters));
            $this->grant('hazards.view', false);
            $this->assertTrue($this->worker->canDo('hazards.view'), 'Loaded caller keeps the old grant.');
            $this->assertSame([], $provider->authorizedTasks($this->worker, $filters));
            $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
            $this->worker->load('permissionOverrides', 'roles.permissions');
            $this->assertFalse($this->worker->canDo('hazards.view'));
            $this->grant('hazards.view', true);
            $this->assertFalse($this->worker->canDo('hazards.view'), 'Loaded caller keeps the old denial.');
            $restored = $provider->authorizedTasks($this->worker, $filters);
            $this->assertCount(1, $restored);
            $contextual = HealthSafetyReadContext::within($this->worker,
                [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS],
                fn ($context) => $provider->authorizedTasksInContext($this->worker, $filters, $context));
            $this->assertEquals($restored, $contextual);
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $done[$provider->sourceKey()]]));
            $terminal = $provider->authorizedTasks($this->worker, ['id' => $done[$provider->sourceKey()], 'include_done' => true]);
            $this->assertCount(1, $terminal);
            $this->assertSame(TaskItem::BUCKET_DONE, $terminal[0]->bucket);
            $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
            $this->worker->load('permissionOverrides', 'roles.permissions');
        }
        $aggregator = new TaskAggregator($providers);
        $this->assertCount(3, $aggregator->itemsFor($this->worker));
        $this->assertCount(6, $aggregator->itemsFor($this->worker, ['include_done' => true]));
        $this->assertSame(['view' => true, 'badge' => 3, 'degraded' => false], $aggregator->navigationBadgeFor($this->worker));
    }

    public function test_standalone_hs_adapters_recheck_current_site_reassignment_with_preloaded_profile(): void
    {
        $ids = $this->rows();
        $providers = array_slice($this->providers, 0, 3);
        $this->grant('healthSafety.viewAllSites', false);
        $this->worker->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $foreign = Site::factory()->create(['is_active' => true]);
        $aggregator = new TaskAggregator($providers);
        foreach ($providers as $provider) {
            $this->assertCount(1, $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]));
        }
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->assertSame((int) $this->sourceSite->id, (int) $this->worker->hrEmployeeProfile->primary_site_id);
        foreach ($providers as $provider) {
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]));
        }
        $this->assertSame([], $aggregator->itemsFor($this->worker));
        $this->assertSame(0, $aggregator->navigationBadgeFor($this->worker)['badge']);
        HsEvent::findOrFail($ids['hs_event'])->updateQuietly(['site_id' => $foreign->id]);
        foreach ($providers as $provider) {
            $items = $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]);
            $this->assertCount(1, $items, $provider::class);
            $this->assertSame((int) $foreign->id, (int) $items[0]->site['id']);
        }
        $this->assertCount(3, $aggregator->itemsFor($this->worker));
        $this->assertSame(3, $aggregator->navigationBadgeFor($this->worker)['badge']);
        HsEvent::findOrFail($ids['hs_event'])->updateQuietly(['site_id' => $this->sourceSite->id]);
        foreach ($providers as $provider) {
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]));
        }
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        foreach ($providers as $provider) {
            $this->assertCount(1, $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]));
        }
    }

    public function test_standalone_hs_adapters_deny_private_wrong_tuple_and_foreign_parent_without_hiding_ordinary_work(): void
    {
        $ordinary = $this->rows();
        $record = $this->source(['injury_illness_description' => 'PRIVATE-TUPLE-NARRATIVE', 'treatment_given' => 'PRIVATE-TREATMENT-NARRATIVE']);
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => \App\Models\FirstAidRecord::class, 'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'site_id' => $this->sourceSite->id,
            'client_id' => null, 'asset_id' => null, 'staff_id' => $this->firstAider->id,
            'created_by' => $this->manager->id, 'owner_user_id' => $this->worker->id, 'status' => HsEvent::STATUS_OPEN,
        ]));
        $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'lead_investigator_id' => $this->worker->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'assigned_to_user_id' => $this->worker->id, 'status' => HsCorrectiveAction::STATUS_OPEN]);
        $privateIds = ['hs_event' => $event->id, 'hs_investigation' => $investigation->id, 'corrective_action' => $action->id];
        $providers = array_slice($this->providers, 0, 3);
        $this->worker->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            foreach ($providers as $provider) {
                $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $privateIds[$provider->sourceKey()]]));
                $allowed = $provider->authorizedTasks($this->worker, ['id' => $ordinary[$provider->sourceKey()]]);
                $this->assertCount(1, $allowed);
                $this->assertStringNotContainsString('PRIVATE-TUPLE-NARRATIVE', json_encode($allowed[0]->toArray(), JSON_THROW_ON_ERROR));
            }
            foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $query) {
                foreach (['treatment_given', 'injury_illness_description', 'work_capacity_assessments', 'return_to_work_plans', 'acc_claim_number'] as $privateColumn) {
                    $this->assertStringNotContainsString($privateColumn, strtolower($query['query']));
                }
            }
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
        $aggregator = new TaskAggregator($providers);
        $this->assertCount(3, $aggregator->itemsFor($this->worker));
        $this->assertSame(3, $aggregator->navigationBadgeFor($this->worker)['badge']);
        $foreign = HsEvent::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id,
            'created_by' => $this->manager->id, 'status' => HsEvent::STATUS_OPEN]);
        $investigation->updateQuietly(['hs_event_id' => $foreign->id]);
        $action->updateQuietly(['hs_event_id' => $foreign->id]);
        foreach ([$providers[1], $providers[2]] as $provider) {
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $privateIds[$provider->sourceKey()]]));
            $this->assertCount(1, $provider->authorizedTasks($this->worker, ['id' => $ordinary[$provider->sourceKey()]]));
        }
        $this->assertCount(3, $aggregator->itemsFor($this->worker));
    }

    public function test_exact_task_lookup_reuses_prepared_context_without_reloading_authority_and_keeps_completed_fallback(): void
    {
        $open = $this->rows();
        $done = $this->rows(true);
        $providers = array_slice($this->providers, 0, 3);
        $aggregator = new TaskAggregator($providers);
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS];
        $result = HealthSafetyReadContext::within($this->worker, $keys, function ($context) use ($open, $done, $providers, $aggregator): array {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            try {
                $items = [];
                foreach ($providers as $provider) {
                    foreach ([$open, $done] as $ids) {
                        $item = $aggregator->findItemFor($this->worker, $provider->sourceKey(), $ids[$provider->sourceKey()],
                            ['return_to' => '/tasks?assigned=me'], $context);
                        $this->assertNotNull($item);
                        $this->assertSame($provider->sourceKey().'-'.$ids[$provider->sourceKey()], $item->id);
                        $items[] = $item->toArray();
                    }
                }
                foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $query) {
                    foreach (['permission_user', 'role_user', 'role_permission'] as $authorityTable) {
                        $this->assertStringNotContainsString($authorityTable, strtolower($query['query']), 'Provided context must not reacquire RBAC.');
                    }
                }
                return $items;
            } finally {
                \Illuminate\Support\Facades\DB::disableQueryLog();
                \Illuminate\Support\Facades\DB::flushQueryLog();
            }
        });
        $this->assertCount(6, $result);
        $this->assertSame(TaskItem::BUCKET_DONE, $result[1]['bucket']);
        $this->grant('hazards.view', false);
        $this->assertNull(HealthSafetyReadContext::within($this->worker, $keys, fn ($context) =>
            $aggregator->findItemFor($this->worker, 'hs_event', $open['hs_event'], [], $context)));
    }

    public function test_exact_task_lookup_never_replaces_missing_foreign_or_expired_prepared_context(): void
    {
        $ids = $this->rows();
        $aggregator = new TaskAggregator(array_slice($this->providers, 0, 3));
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS];
        foreach (['missing' => FirstAidAccessService::PERMISSIONS, 'foreign' => $keys] as $kind => $prepared) {
            HealthSafetyReadContext::within($this->worker, $prepared, function ($context) use ($kind, $ids, $aggregator): array {
                \Illuminate\Support\Facades\DB::enableQueryLog();
                \Illuminate\Support\Facades\DB::flushQueryLog();
                try {
                    try {
                        $aggregator->findItemFor($kind === 'foreign' ? $this->manager : $this->worker, 'hs_event', $ids['hs_event'], [], $context);
                        $this->fail('Invalid caller context was replaced.');
                    } catch (LogicException $error) {
                        $this->assertNotEmpty($error->getMessage());
                    }
                    $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog(), 'Invalid context must fail before any fallback query.');
                    return [];
                } finally {
                    \Illuminate\Support\Facades\DB::disableQueryLog();
                    \Illuminate\Support\Facades\DB::flushQueryLog();
                }
            });
        }
        $expired = null;
        HealthSafetyReadContext::within($this->worker, $keys, function ($context) use (&$expired): array {
            $expired = $context;
            return [];
        });
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            try {
                $aggregator->findItemFor($this->worker, 'hs_event', $ids['hs_event'], [], $expired);
                $this->fail('Expired caller context was replaced.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('callback', $error->getMessage());
            }
            $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
    }

    public function test_hs_providers_exact_id_bound_treatment_discovery_by_current_site_before_source_proof(): void
    {
        [$events, $rows] = $this->candidateTreatmentEvents();
        $providers = array_slice($this->providers, 0, 3);
        $identityColumns = ['hs_event' => 'hs_events.id', 'hs_investigation' => 'hs_investigations.id', 'corrective_action' => 'hs_corrective_actions.id'];
        foreach ($providers as $provider) {
            foreach ($rows as $index => $ids) {
                IlluminateSupportFacadesDB::enableQueryLog();
                IlluminateSupportFacadesDB::flushQueryLog();
                try {
                    $items = $provider->authorizedTasks($this->worker, ['id' => $ids[$provider->sourceKey()]]);
                    $this->assertCount($index === 0 ? 1 : 0, $items, $provider::class);
                    $proofs = array_filter(IlluminateSupportFacadesDB::getQueryLog(),
                        fn ($query) => str_contains(strtolower($query['query']), 'first_aid_records')
                            && str_contains(strtolower($query['query']), 'exists'));
                    $this->assertNotEmpty($proofs, 'Actual canonical treatment proof must execute.');
                    foreach ($proofs as $query) {
                        $sql = strtolower(str_replace(chr(96), '', $query['query']));
                        $this->assertStringContainsString('hs_events.id in (select hs_events.id from hs_events', $sql);
                        $this->assertStringContainsString($identityColumns[$provider->sourceKey()].' = ?', $sql);
                        $this->assertStringContainsString('site_id in (', $sql);
                        $this->assertStringNotContainsString('treatment_given', $sql);
                        $this->assertStringNotContainsString('injury_illness_description', $sql);
                    }
                } finally {
                    IlluminateSupportFacadesDB::disableQueryLog();
                    IlluminateSupportFacadesDB::flushQueryLog();
                }
            }
        }
        $events[0]->updateQuietly(['status' => HsEvent::STATUS_CLOSED]);
        HsInvestigation::findOrFail($rows[0]['hs_investigation'])->updateQuietly(['status' => HsInvestigation::STATUS_COMPLETED]);
        HsCorrectiveAction::findOrFail($rows[0]['corrective_action'])->updateQuietly(['status' => HsCorrectiveAction::STATUS_CLOSED]);
        foreach ($providers as $provider) {
            $id = $rows[0][$provider->sourceKey()];
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $id]));
            $terminal = $provider->authorizedTasks($this->worker, ['id' => $id, 'include_done' => true]);
            $this->assertCount(1, $terminal);
            $this->assertSame(TaskItem::BUCKET_DONE, $terminal[0]->bucket);
        }
    }

    public function test_hs_providers_search_keep_site_in_independent_candidates_without_sharing_disjoint_whitelists(): void
    {
        [$events, $rows] = $this->candidateTreatmentEvents();
        $providers = array_slice($this->providers, 0, 3);
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
        $items = HealthSafetyReadContext::within($this->worker, $keys, function ($context) use ($rows, $providers): array {
            $all = [];
            foreach ($providers as $provider) {
                $this->assertCount(1, $provider->authorizedTasksInContext($this->worker, ['id' => $rows[0][$provider->sourceKey()]], $context));
                $this->assertSame([], $provider->authorizedTasksInContext($this->worker, ['id' => $rows[1][$provider->sourceKey()]], $context));
                IlluminateSupportFacadesDB::enableQueryLog();
                IlluminateSupportFacadesDB::flushQueryLog();
                try {
                    $found = $provider->authorizedTasksInContext($this->worker, ['q' => 'CandidateScope'], $context);
                    $proofs = array_filter(IlluminateSupportFacadesDB::getQueryLog(),
                        fn ($query) => str_contains(strtolower($query['query']), 'first_aid_records')
                            && str_contains(strtolower($query['query']), 'exists'));
                    $this->assertNotEmpty($proofs);
                    foreach ($proofs as $query) {
                        $sql = strtolower(str_replace(chr(96), '', $query['query']));
                        $this->assertStringContainsString('hs_events.id in (select hs_events.id from hs_events', $sql);
                        $this->assertStringContainsString('reference_number', $sql);
                        $this->assertStringContainsString('site_id in (', $sql);
                        $this->assertContains('%CandidateScope%', $query['bindings']);
                        $this->assertStringNotContainsString('treatment_given', $sql);
                        $this->assertStringNotContainsString('injury_illness_description', $sql);
                    }
                    $this->assertSame([$provider->sourceKey().'-'.$rows[0][$provider->sourceKey()]], array_column($found, 'id'));
                    array_push($all, ...$found);
                } finally {
                    IlluminateSupportFacadesDB::disableQueryLog();
                    IlluminateSupportFacadesDB::flushQueryLog();
                }
            }
            return $all;
        });
        $expected = array_map(fn ($provider) => $provider->sourceKey().'-'.$rows[0][$provider->sourceKey()], $providers);
        $this->assertEqualsCanonicalizing($expected, array_column($items, 'id'));
        $aggregator = new TaskAggregator($providers);
        $this->assertEqualsCanonicalizing($expected, array_column($aggregator->itemsFor($this->worker, ['q' => 'CandidateScope']), 'id'));
        $this->assertSame(3, $aggregator->navigationBadgeFor($this->worker)['badge']);
    }

    private function candidateTreatmentEvents(): array
    {
        $own = $this->source();
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $permission = Permission::where('key', 'healthSafety.viewAllSites')->firstOrFail();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->firstAider->hrEmployeeProfile()->update(['secondary_site_ids' => [$foreignSite->id]]);
        $other = $this->source(['site_id' => $foreignSite->id, 'treated_person_id' => null,
            'treated_person_name' => 'Recorded foreign staff', 'injury_illness_description' => 'PRIVATE-FOREIGN-TREATMENT']);
        $events = $rows = [];
        foreach ([$own, $other] as $index => $record) {
            $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
                'source_type' => AppModelsFirstAidRecord::class, 'source_id' => $record->id,
                'event_category' => HsEvent::CATEGORY_INJURY, 'site_id' => $record->site_id,
                'client_id' => null, 'asset_id' => null, 'staff_id' => $record->treated_person_id,
                'reference_number' => 'HS-CandidateScope-'.$index.'-'.$record->id,
                'created_by' => $this->manager->id, 'owner_user_id' => $this->worker->id,
                'status' => HsEvent::STATUS_OPEN,
            ]));
            $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
                'lead_investigator_id' => $this->worker->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS]);
            $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
                'assigned_to_user_id' => $this->worker->id, 'status' => HsCorrectiveAction::STATUS_OPEN]);
            $events[] = $event;
            $rows[] = ['hs_event' => $event->id, 'hs_investigation' => $investigation->id, 'corrective_action' => $action->id];
        }
        return [$events, $rows];
    }

    private function unrelatedProvider(User $viewer): TaskProvider
    {
        return new class($viewer) implements TaskProvider
        {
            public function __construct(private readonly User $original) {}

            public function sourceKey(): string
            {
                return 'unrelated';
            }

            public function label(): string
            {
                return 'Unrelated work';
            }

            public function canView(User $user): bool
            {
                return $user === $this->original;
            }

            public function authorizedTasks(User $user, array $filters = []): array
            {
                if ($user !== $this->original) {
                    throw new RuntimeException('An unrelated destination received the H&S authority object.');
                }

                return [new TaskItem(id: 'unrelated-1', source: 'unrelated', sourceLabel: $this->label(), ref: null,
                    title: 'Other work survives', status: 'open', bucket: TaskItem::BUCKET_OPEN, severity: 'low',
                    assignee: ['id' => $user->id, 'name' => $user->name])];
            }
        };
    }

    public function test_missing_context_actor_conceals_hs_without_denial_of_unrelated_tasks_or_navigation(): void
    {
        $viewer = User::factory()->make(['id' => 2147483647, 'approved_at' => now()]);
        $aggregator = new TaskAggregator([new FirstAidFollowupProvider, $this->unrelatedProvider($viewer)]);
        $this->assertSame(['unrelated-1'], array_column($aggregator->itemsFor($viewer), 'id'));
        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => false], $aggregator->navigationBadgeFor($viewer));
    }

    public function test_contextual_provider_errors_retain_navigation_isolation_and_strict_feed_failure(): void
    {
        Exceptions::fake();
        $failure = new RuntimeException('Contextual source failure');
        $provider = new class($failure) extends HsEventProvider
        {
            public function __construct(private readonly RuntimeException $failure) {}

            public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
            {
                $context->assertViewer($viewer);
                throw $this->failure;
            }

            public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
            {
                $context->assertViewer($viewer);
                throw $this->failure;
            }
        };
        $aggregator = new TaskAggregator([$provider, $this->unrelatedProvider($this->worker)]);
        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => true], $aggregator->navigationBadgeFor($this->worker));
        Exceptions::assertReported(fn (TaskProviderNavigationException $exception) => $exception->getPrevious() === $failure);
        $this->expectExceptionObject($failure);
        $aggregator->itemsFor($this->worker);
    }
}
