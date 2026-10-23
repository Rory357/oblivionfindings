<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\TaskWatcher;
use App\Models\WorkplaceInjurySourceVersion;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\InjurySourceProjectionService;
use App\Services\HealthSafety\WorkplaceInjurySourceService;
use App\Services\HealthSafety\WorkplaceInjuryRecoveryService;
use App\Services\HealthSafety\RestraintCommandValues;
use App\Services\Tasks\Providers\SiteHazardProvider;
use App\Services\Tasks\Providers\WorkplaceInjuryProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Complete real-source Tasks/H&S/copied-alert regressions; source-prepared and runtime UNRUN. */
class InjurySourceProjectionPrivacyTest extends InjuryWorkspaceReadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This genuine legacy row has no model events; supply a real ticket for search parity.
        $this->injury->updateQuietly(['reference_number' => 'INJ-PROJECTION-'.$this->injury->id]);
    }

    public function test_factual_provider_returns_stable_identity_and_canonical_link_without_medical_queries(): void
    {
        $viewer = $this->staff(['hazards.view']);
        [$items, $queries] = $this->queries(fn () => (new WorkplaceInjuryProvider)->authorizedTasks($viewer));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(TaskItem::class, $items[0]);
        $dto = $items[0]->toArray();
        $this->assertSame('injury-'.$this->injury->id, $dto['id']);
        $this->assertSame('injury', $dto['source']);
        $this->assertSame('/health-safety/injuries/'.$this->injury->id, $dto['link']);
        $this->assertStringContainsString($this->worker->name, $dto['title']);
        $this->assertSame('Factual workplace occurrence', $dto['description']);
        $this->assertTrue($dto['restricted']);
        $this->assertNoPrivateQueries($queries);
        $this->assertNoPrivateFacts($dto);
    }

    public function test_wellbeing_only_read_preserves_factual_task_without_projecting_private_medical_fields(): void
    {
        $viewer = $this->staff(['hr.wellbeing.view']);
        [$items, $queries] = $this->queries(fn () => (new WorkplaceInjuryProvider)->authorizedTasks($viewer));
        $this->assertCount(1, $items);
        $this->assertNoPrivateFacts($items[0]->toArray());
        $this->assertNoPrivateQueries($queries);
        $projection = $this->projection()->forInjury($this->injury->id, $viewer);
        $this->assertSame('excluded', $projection['medical_access']);
        $this->assertSame($this->worker->id, $projection['worker']['id']);
        $this->assertNoPrivateFacts($projection);
    }

    public function test_manage_or_create_without_actual_read_cannot_materialize_source_task_or_alert_facts(): void
    {
        $viewer = $this->staff(['hazards.create', 'hazards.manage']);
        $this->assertSame([], (new WorkplaceInjuryProvider)->authorizedTasks($viewer));
        $this->assertNull($this->projection()->forInjury($this->injury->id, $viewer));
        $this->assertSame(['access' => 'restricted', 'source' => null], $this->projection()->forAlert($this->alert(), $viewer));
    }

    public function test_current_permission_override_revoke_and_restore_ignores_warmed_source_caller(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $provider = new WorkplaceInjuryProvider;
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $this->revoke($viewer, 'hazards.view');
        $this->assertTrue($viewer->canDo('hazards.view'));
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $this->assertNull($this->projection()->forInjury($this->injury->id, $viewer));
        $this->grant($viewer, 'hazards.view');
        $this->assertCount(1, $provider->authorizedTasks($viewer));
    }

    public function test_current_role_permission_removal_and_restore_ignores_loaded_role_grants(): void
    {
        $viewer = $this->staff([]);
        $role = Role::create(['name' => 'injury_projection_reader', 'label' => 'Injury source reader', 'level' => 10, 'type' => 'custom']);
        $permission = Permission::where('key', 'hazards.view')->sole();
        $role->permissions()->attach($permission->id);
        $viewer->roles()->attach($role->id);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertTrue($viewer->canDo('hazards.view'));
        $provider = new WorkplaceInjuryProvider;
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $role->permissions()->detach($permission->id);
        $this->assertTrue($viewer->canDo('hazards.view'));
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $role->permissions()->attach($permission->id);
        $this->assertCount(1, $provider->authorizedTasks($viewer));
    }

    #[DataProvider('ineligibleViewers')]
    public function test_current_internal_staff_eligibility_is_rechecked_for_all_source_entries(string $condition): void
    {
        $viewer = $this->staff(['hazards.view']);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertCount(1, (new WorkplaceInjuryProvider)->authorizedTasks($viewer));
        match ($condition) {
            'inactive' => $viewer->hrEmployeeProfile()->update(['is_active' => false]),
            'ended' => $viewer->hrEmployeeProfile()->update(['end_date' => now('Pacific/Auckland')->subDay()->toDateString()]),
            'future' => $viewer->hrEmployeeProfile()->update(['start_date' => now('Pacific/Auckland')->addDay()->toDateString()]),
            'unapproved' => $viewer->update(['approved_at' => null]),
            'external' => $viewer->update(['external_clinical_account' => true]),
            'client' => $viewer->update(['role' => 'client']),
        };
        $this->assertSame([], (new WorkplaceInjuryProvider)->authorizedTasks($viewer));
        $this->assertNull($this->projection()->forInjury($this->injury->id, $viewer));
        $this->assertSame(['access' => 'restricted', 'source' => null], $this->projection()->forAlert($this->alert(), $viewer));
    }

    public static function ineligibleViewers(): array
    {
        return [['inactive'], ['ended'], ['future'], ['unapproved'], ['external'], ['client']];
    }

    public function test_secondary_site_source_and_bypass_revocations_are_fresh_without_report_grant_expansion(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true]);
        $other = $this->injuryAt($otherSite);
        $viewer = $this->staff(['hazards.view', 'reports.viewAny', 'hr.employees.viewAllSites']);
        $provider = new WorkplaceInjuryProvider;
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertCount(2, $provider->authorizedTasks($viewer));
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $this->assertNull($this->projection()->forInjury($other->id, $viewer));
        $this->grant($viewer, 'healthSafety.viewAllSites');
        $this->assertCount(2, $provider->authorizedTasks($viewer));
        $this->revoke($viewer, 'healthSafety.viewAllSites');
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $this->site->update(['is_active' => true]);
        $this->assertCount(1, $provider->authorizedTasks($viewer));
    }

    public function test_historical_injured_worker_identity_remains_factual_when_employment_ends(): void
    {
        $this->worker->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => now('Pacific/Auckland')->subYear()->toDateString()]);
        $source = $this->projection()->forInjury($this->injury->id, $this->staff(['hazards.view']));
        $this->assertSame($this->worker->id, $source['worker']['id']);
        $this->assertSame($this->worker->name, $source['worker']['name']);
        $this->assertSame('legacy_unknown', $source['attribution_state']);
        $this->assertArrayNotHasKey('fit_for_work', $source);
    }

    public function test_current_coordinator_and_recorded_nz_review_date_drive_assignment_and_due_state(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $this->injury->updateQuietly(['coordinator_user_id' => $viewer->id, 'coordinator_snapshot' => ['id' => $viewer->id, 'name' => 'Old snapshot name'],
            'next_review_date' => '2026-09-27']);
        $item = (new WorkplaceInjuryProvider)->authorizedTasks($viewer)[0];
        $this->assertSame(['id' => $viewer->id, 'name' => $viewer->name], $item->assignee);
        $this->assertSame('2026-09-27T23:59:59+13:00', $item->dueAt);
        $this->assertTrue($item->isOverdue());
        $this->assertStringNotContainsString('Old snapshot name', json_encode($item->toArray()));
        $this->injury->updateQuietly(['next_review_date' => null]);
        $this->assertNull((new WorkplaceInjuryProvider)->authorizedTasks($viewer)[0]->dueAt);
    }

    public function test_coordinator_eligibility_revoke_and_restore_does_not_hide_authorized_factual_work(): void
    {
        $coordinator = $this->staff(['hazards.view']);
        $viewer = $this->staff(['hazards.view']);
        $this->injury->updateQuietly(['coordinator_user_id' => $coordinator->id,
            'coordinator_snapshot' => ['id' => $coordinator->id, 'name' => 'Private stale coordinator name']]);
        $provider = new WorkplaceInjuryProvider;
        $this->assertSame($coordinator->id, $provider->authorizedTasks($viewer)[0]->assignee['id']);
        $coordinator->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertNull($provider->authorizedTasks($viewer)[0]->assignee);
        $coordinator->hrEmployeeProfile()->update(['is_active' => true]);
        $this->revoke($coordinator, 'hazards.view');
        $this->assertNull($provider->authorizedTasks($viewer)[0]->assignee);
        $this->grant($coordinator, 'hazards.view');
        $this->assertSame($coordinator->id, $provider->authorizedTasks($viewer)[0]->assignee['id']);
    }

    public function test_include_done_and_exact_lookup_preserve_task_identity_and_top300_source_cutoff(): void
    {
        $this->injury->updateQuietly(['status' => 'recovered', 'injury_date' => now()->subYear()]);
        $viewer = $this->staff(['hazards.view']);
        WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->count(301)->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->viewer->id,
            'status' => 'reported', 'injury_date' => now()->subDay(), 'source_schema_version' => 0, 'source_revision' => 0,
        ]));
        $provider = new WorkplaceInjuryProvider;
        $this->assertCount(300, $provider->authorizedTasks($viewer));
        $this->assertCount(300, $provider->authorizedTasks($viewer, ['include_done' => true]));
        $this->assertSame([], $provider->authorizedTasks($viewer, ['id' => $this->injury->id]));
        $items = $provider->authorizedTasks($viewer, ['id' => $this->injury->id, 'include_done' => true]);
        $this->assertSame('injury-'.$this->injury->id, $items[0]->id);
        $this->assertSame(TaskItem::BUCKET_DONE, $items[0]->bucket);
    }

    public function test_actual_tasks_search_counts_and_csv_do_not_use_private_claim_or_notes(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view']);
        $this->app->instance(TaskAggregator::class, new TaskAggregator([new WorkplaceInjuryProvider]));
        $this->actingAs($viewer)->get('/tasks?sources=injury')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tasks/index')->has('items', 1)->where('items.0.id', 'injury-'.$this->injury->id)
            ->where('items.0.link', '/health-safety/injuries/'.$this->injury->id)->where('pagination.total', 1));
        foreach (['PRIVATE-ACC-123', 'Private recovery note', 'Private clinical treatment'] as $term) {
            $this->get('/tasks?'.http_build_query(['sources' => 'injury', 'q' => $term]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('pagination.total', 0));
        }
        $matched = $this->get('/tasks?'.http_build_query(['sources' => 'injury', 'q' => $this->injury->reference_number]))->assertOk();
        $matched->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('pagination.total', 1));
        $csv = $this->get('/tasks?sources=injury&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('/health-safety/injuries/'.$this->injury->id, $csv);
        $this->assertNoPrivateFacts($csv);
    }

    public function test_actual_task_drawer_withholds_private_source_audits_and_keeps_permanent_href(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view']);
        AuditLog::create(['user_id' => $this->viewer->id, 'action' => 'injury.record_capacity',
            'auditable_type' => WorkplaceInjury::class, 'auditable_id' => $this->injury->id,
            'meta' => ['assessment_summary' => 'Private medical audit sentinel']]);
        $otherWatcher = $this->staff(['hazards.view']);
        TaskWatcher::create(['source' => 'injury', 'item_id' => $this->injury->id, 'user_id' => $otherWatcher->id]);
        $this->app->instance(TaskAggregator::class, new TaskAggregator([new WorkplaceInjuryProvider]));
        $this->actingAs($viewer)->post('/tasks/injury/'.$this->injury->id.'/watch',
            ['watching' => true, 'return_to' => '/tasks?sources=injury'])->assertRedirect();
        [$response, $queries] = $this->queries(fn () => $this->actingAs($viewer)
            ->getJson('/tasks/detail?source=injury&id='.$this->injury->id)->assertOk());
        $response->assertJsonPath('item.link', '/health-safety/injuries/'.$this->injury->id)
            ->assertJsonPath('item.restricted', true)->assertJsonPath('canOpen', true)
            ->assertJsonPath('canWatch', true)->assertJsonPath('isWatching', true)
            ->assertJsonPath('watchersHidden', true)->assertJsonPath('watchers', [])
            ->assertJsonPath('timeline', []);
        $this->assertStringNotContainsString($otherWatcher->name, $response->getContent());
        $this->assertStringNotContainsString('Private medical audit sentinel', $response->getContent());
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('audit_logs', strtolower($query['query']));
        }
    }

    public function test_active_shared_context_returns_only_detached_scalars_and_cannot_be_consumed_after_escape(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $escaped = null;
        $projection = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            function ($context) use ($viewer, &$escaped): array {
                $escaped = $context;
                return $this->projection()->taskRows($viewer, [], $context);
            });
        $this->assertSame($this->injury->id, $projection[0]['id']);
        $this->assertDetached($projection);
        $this->expectException(LogicException::class);
        $this->projection()->forInjury($this->injury->id, $viewer, $escaped);
    }

    public function test_actual_hs_get_list_and_task_source_observe_canonical_source_site_correction(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view']);
        $event = $this->hs();
        $url = '/health-safety/events/'.$event->id;
        $list = '/health-safety/events?'.http_build_query(['q' => $event->reference_number]);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.id', $event->id));
        $this->get($list)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('tabCounts.all', 1));
        $otherSite = Site::factory()->create(['is_active' => true]);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $this->injury->updateQuietly(['site_id' => $otherSite->id]);
        $this->assertSame($otherSite->id, $this->projection()->forInjury($this->injury->id, $viewer)['site']['id']);
        $this->get($url)->assertNotFound();
        $this->get($list)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 0)->where('tabCounts.all', 0));
        $event->updateQuietly(['site_id' => $otherSite->id]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.id', $event->id));
        $this->get($list)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('tabCounts.all', 1));
    }

    #[DataProvider('wrongHsTuples')]
    public function test_independent_hs_candidates_require_actual_source_site_worker_category_and_null_client_asset(string $field): void
    {
        $event = $this->hs();
        $viewer = $this->staff(['hazards.view']);
        $value = match ($field) {
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'staff_id' => $this->staff([])->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'client_id' => \App\Models\Client::factory()->create(['site_id' => $this->site->id])->id,
            'asset_id' => \App\Models\Asset::factory()->create(['site_id' => $this->site->id])->id,
            'source_id' => 2147483647,
        };
        $event->updateQuietly([$field => $value]);
        $ids = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => $this->projection()->readableHsEventIdsInContext($viewer, HsEvent::query()->whereKey($event->id), $context));
        $this->assertSame([], $ids);
    }

    public static function wrongHsTuples(): array
    {
        return [['site_id'], ['staff_id'], ['event_category'], ['client_id'], ['asset_id'], ['source_id']];
    }

    public function test_independent_candidate_materialization_does_not_hydrate_unrelated_injury_backlog(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $event = $this->hs();
        $unrelated = $this->injuryAt($this->site, ['description' => 'Unrelated source sentinel']);
        $this->hs($unrelated);
        [$ids, $queries] = $this->queries(fn () => HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => $this->projection()->readableHsEventIdsInContext($viewer, HsEvent::query()->whereKey($event->id), $context)));
        $this->assertSame([$event->id], $ids);
        foreach ($queries as $entry) {
            if (str_contains(strtolower($entry['query']), 'from '.DB::connection()->getQueryGrammar()->wrapTable('workplace_injuries'))) {
                $this->assertStringContainsString('where', strtolower($entry['query']));
                $this->assertNotContains($unrelated->id, $entry['bindings']);
            }
        }
        $this->assertNoPrivateQueries($queries);
    }

    public function test_archived_source_is_never_revived_by_historical_hs_candidate_or_done_task_option(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $event = $this->hs();
        $alert = $this->alert();
        $this->injury->deleteQuietly();
        $this->assertSame([], (new WorkplaceInjuryProvider)->authorizedTasks($viewer, ['include_done' => true]));
        $this->assertNull($this->projection()->forInjury($this->injury->id, $viewer));
        $this->assertSame(['access' => 'unavailable', 'source' => null], $this->projection()->forAlert($alert, $viewer));
        $ids = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => $this->projection()->readableHsEventIdsInContext($viewer, HsEvent::withTrashed()->whereKey($event->id), $context));
        $this->assertSame([], $ids);
    }

    public function test_alert_projection_reloads_actual_tuple_and_current_facts_instead_of_preloaded_copies(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $alert = $this->alert();
        $alert->context = [...$alert->context, 'description' => 'Injected stale private description', 'user_id' => 2147483647];
        $this->injury->updateQuietly(['description' => 'Current factual injury correction', 'body_part_affected' => 'Current shoulder']);
        $result = $this->projection()->forAlert($alert, $viewer);
        $this->assertSame('readable', $result['access']);
        $this->assertSame('Current factual injury correction', $result['source']['description']);
        $this->assertSame('Current shoulder', $result['source']['body_part_affected']);
        $this->assertStringNotContainsString('Injected stale', json_encode($result));
        $this->assertNoPrivateFacts($result);
        $this->assertDetached($result);
    }

    #[DataProvider('wrongAlertTuples')]
    public function test_actual_alert_source_type_identity_site_worker_and_client_mismatch_conceal_all_source_facts(string $field): void
    {
        $viewer = $this->staff(['hazards.view']);
        $alert = $this->alert();
        $other = $this->staff([]);
        $context = $alert->context;
        match ($field) {
            'source' => $alert->source = 'incident',
            'type' => $alert->alert_type = 'operations.client_incident',
            'alert_site' => $alert->site_id = Site::factory()->create(['is_active' => true])->id,
            'client' => $alert->client_id = \App\Models\Client::factory()->create(['site_id' => $this->site->id])->id,
            'context_site' => $context['site_id'] = 2147483647,
            'context_worker' => $context['user_id'] = $other->id,
            'boolean_id' => $context['workplace_injury_id'] = true,
            'missing_source' => $context['workplace_injury_id'] = 2147483647,
        };
        $alert->context = $context;
        $alert->saveQuietly();
        $result = $this->projection()->forAlert($alert, $viewer);
        $this->assertSame(['access' => ($field === 'missing_source' ? 'unavailable' : 'restricted'), 'source' => null], $result);
        $this->assertStringNotContainsString($this->worker->name, json_encode($result));
        $this->assertStringNotContainsString('Factual workplace occurrence', json_encode($result));
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
        $this->assertSame('Independent operational response remains', $alert->fresh()->notes);
    }

    public static function wrongAlertTuples(): array
    {
        return [['source'], ['type'], ['alert_site'], ['client'], ['context_site'], ['context_worker'], ['boolean_id'], ['missing_source']];
    }

    public function test_alert_source_revoke_preserves_independent_operational_response_and_restore_rebuilds_facts(): void
    {
        $viewer = $this->staff(['hazards.view', 'controlRoom.alerts.view']);
        $alert = $this->alert();
        $before = $alert->fresh()->attributesToArray();
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertSame('readable', $this->projection()->forAlert($alert, $viewer)['access']);
        $this->revoke($viewer, 'hazards.view');
        $this->assertSame(['access' => 'restricted', 'source' => null], $this->projection()->forAlert($alert, $viewer));
        $this->assertSame($before, $alert->fresh()->attributesToArray());
        $this->grant($viewer, 'hazards.view');
        $this->assertSame('readable', $this->projection()->forAlert($alert, $viewer)['access']);
        $this->assertSame('Independent operational response remains', $alert->fresh()->notes);
    }

    public function test_actual_prospective_capture_proof_is_factual_for_both_reader_types_and_current_hs_source(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->capturedInjury();
        $version = WorkplaceInjurySourceVersion::where('workplace_injury_id', $this->injury->id)->sole();
        foreach ([$this->staff(['hazards.view']), $this->staff(['hr.wellbeing.view'])] as $viewer) {
            [$source, $queries] = $this->queries(fn () => $this->projection()->forInjury($this->injury->id, $viewer));
            $this->assertSame('recorded', $source['attribution_state']);
            $this->assertSame($version->id, $source['source_version_id']);
            $this->assertSame(1, $source['source_revision']);
            $this->assertSame('excluded', $source['medical_access']);
            $this->assertNoPrivateFacts($source);
            $this->assertNoPrivateQueries($queries);
            foreach ($queries as $query) {
                if (str_contains(strtolower($query['query']), 'workplace_injury_source_versions')) {
                    $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(content|content_digest|source_type|source_id|command|recorded_by|recorder_snapshot)(?![a-z_])/i', $query['query']);
                    $this->assertStringNotContainsString('*', $query['query']);
                }
            }
            $item = (new WorkplaceInjuryProvider)->authorizedTasks($viewer, ['id' => $this->injury->id])[0];
            $this->assertSame('/health-safety/injuries/'.$this->injury->id, $item->link);
            $this->assertTrue($item->restricted);
        }
        $event = HsEvent::where('source_type', WorkplaceInjury::class)->where('source_id', $this->injury->id)->sole();
        $viewer = $this->staff(['hazards.view']);
        [$response, $queries] = $this->queries(fn () => $this->actingAs($viewer)->get('/health-safety/events/'.$event->id)->assertOk());
        $this->assertNoPrivateFacts($response->getContent());
        $this->assertNoPrivateQueries($queries);
        [$response, $queries] = $this->queries(fn () => $this->get('/health-safety/events?'.http_build_query(['q' => $event->reference_number]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('tabCounts.all', 1)));
        $this->assertNoPrivateFacts($response->getContent());
        $this->assertNoPrivateQueries($queries);
    }

    #[DataProvider('damagedFactualSources')]
    public function test_damaged_typed_source_is_unavailable_without_names_links_or_false_zero_certification(string $damage): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->capturedInjury();
        $viewer = $this->staff(['hazards.view']);
        $alert = $this->alert();
        $version = WorkplaceInjurySourceVersion::where('workplace_injury_id', $this->injury->id)->sole();
        $event = HsEvent::where('source_type', WorkplaceInjury::class)->where('source_id', $this->injury->id)->sole();
        $snapshot = $version->factual_content;
        switch ($damage) {
            case 'changed_fact':
                DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['description' => 'Externally altered factual source']);
                break;
            case 'digest':
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['factual_digest' => str_repeat('0', 64)]);
                break;
            case 'missing_factual':
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['factual_content' => null, 'factual_digest' => null]);
                break;
            case 'wrong_factual_tuple':
                $snapshot['worker_id'] = 2147483647;
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->update([
                    'factual_content' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'factual_digest' => RestraintCommandValues::digest($snapshot)]);
                break;
            case 'metadata_worker':
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['worker_id' => $this->staff([])->id]);
                break;
            case 'missing_version':
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->delete();
                break;
            case 'aggregate_revision':
                DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['source_revision' => 2]);
                break;
            case 'downgraded_markers':
                DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
                break;
            case 'downgraded_history_and_markers':
                DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['source_schema_version' => 0]);
                break;
        }
        [$source, $queries] = $this->queries(fn () => $this->projection()->forInjury($this->injury->id, $viewer));
        $this->assertNull($source);
        $this->assertNoPrivateQueries($queries);
        $this->assertSame([], (new WorkplaceInjuryProvider)->authorizedTasks($viewer, ['id' => $this->injury->id, 'include_done' => true]));
        $this->assertSame(['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'],
            $this->projection()->forAlert($alert, $viewer));
        $this->actingAs($viewer)->get('/health-safety/events/'.$event->id)->assertNotFound();
        $this->get('/health-safety/events?'.http_build_query(['q' => $event->reference_number]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 0)->where('tabCounts.all', 0));
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
        $this->assertSame('Independent operational response remains', $alert->fresh()->notes);
        $this->assertDatabaseHas('workplace_injury_command_receipts', ['workplace_injury_id' => $this->injury->id, 'command' => 'create']);
    }

    public static function damagedFactualSources(): array
    {
        return [['changed_fact'], ['digest'], ['missing_factual'], ['wrong_factual_tuple'], ['metadata_worker'],
            ['missing_version'], ['aggregate_revision'], ['downgraded_markers'], ['downgraded_history_and_markers']];
    }

    public function test_real_accepted_correction_refreshes_source_tasks_hs_and_copied_alert_without_private_fallback(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $writer = $this->capturedInjury();
        $viewer = $this->staff(['hazards.view']);
        $alert = $this->alert();
        $alert->context = [...$alert->context, 'description' => 'Caller-only stale source prose'];
        $receipt = app(WorkplaceInjurySourceService::class)->update($this->injury, $writer,
            ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'description' => 'Accepted factual correction']);
        $source = $this->projection()->forInjury($this->injury->id, $viewer);
        $this->assertSame('recorded', $source['attribution_state']);
        $this->assertSame(2, $source['source_revision']);
        $this->assertSame($receipt['source_version_id'], $source['source_version_id']);
        $this->assertSame('Accepted factual correction', $source['description']);
        $this->assertSame('Accepted factual correction', (new WorkplaceInjuryProvider)->authorizedTasks($viewer, ['id' => $this->injury->id])[0]->description);
        $copy = $this->projection()->forAlert($alert, $viewer);
        $this->assertSame('readable', $copy['access']);
        $this->assertSame('Accepted factual correction', $copy['source']['description']);
        $this->assertStringNotContainsString('Caller-only stale', json_encode($copy));
        $event = HsEvent::where('source_type', WorkplaceInjury::class)->where('source_id', $this->injury->id)->sole();
        $this->actingAs($viewer)->get('/health-safety/events/'.$event->id)->assertOk();
        $this->assertNoPrivateFacts($source);
    }

    public function test_private_child_aggregate_advancement_preserves_factual_task_without_child_identity_or_sql(): void
    {
        $writer = $this->capturedInjury();
        $receipt = app(WorkplaceInjuryRecoveryService::class)->recordCapacity($this->injury, $writer,
            ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'assessment_date' => '2026-10-11',
                'assessor_name' => 'Private named external assessor', 'assessor_type' => 'gp',
                'capacity_status' => 'fit_for_modified_duties', 'assessment_summary' => 'Private capacity prose',
                'restrictions' => 'Private suggested duties']);
        $viewer = $this->staff(['hazards.view']);
        [$rows, $queries] = $this->queries(fn () => $this->projection()->taskRows($viewer, ['id' => $this->injury->id]));
        $this->assertCount(1, $rows);
        $this->assertSame('recorded', $rows[0]['attribution_state']);
        $this->assertSame(2, $rows[0]['source_revision']);
        $this->assertSame($receipt['source_version_id'], $rows[0]['source_version_id']);
        $this->assertStringNotContainsString('Private', json_encode($rows));
        $this->assertArrayNotHasKey('source_type', $rows[0]);
        $this->assertArrayNotHasKey('source_id', $rows[0]);
        $this->assertNoPrivateQueries($queries);
    }

    public function test_unrelated_alert_family_is_explicitly_ignored_without_injury_or_private_source_queries(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'source' => 'sensor', 'alert_type' => 'sensor.temperature', 'client_id' => null, 'site_id' => $this->site->id,
            'context' => ['description' => 'Independent sensor facts']]));
        [$result, $queries] = $this->queries(fn () => $this->projection()->forAlert($alert, $viewer));
        $this->assertSame(['access' => 'absent', 'source' => null, 'ignored' => true], $result);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('workplace_injuries', strtolower($query['query']));
        }
        $this->assertNoPrivateQueries($queries);
    }

    public function test_provider_cannot_consume_expired_context_for_full_or_navigation_work(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $context = null;
        HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            function ($active) use (&$context): bool { $context = $active; return true; });
        $provider = new WorkplaceInjuryProvider;
        foreach (['full', 'navigation'] as $entry) {
            $rejected = false;
            try {
                $entry === 'full' ? $provider->authorizedTasksInContext($viewer, [], $context)
                    : $provider->authorizedNavigationTasksInContext($viewer, $context);
            } catch (LogicException $error) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
    }

    public function test_contextual_full_and_navigation_tasks_match_public_facts_assignment_and_recorded_due_state(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $this->injury->updateQuietly(['coordinator_user_id' => $viewer->id, 'next_review_date' => '2026-09-27']);
        $provider = new WorkplaceInjuryProvider;
        $public = array_map(fn (TaskItem $item): array => $item->toArray(), $provider->authorizedTasks($viewer));
        $result = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => ['full' => $provider->authorizedTasksInContext($viewer, [], $context),
                'navigation' => $provider->authorizedNavigationTasksInContext($viewer, $context)]);
        foreach ($result as $items) {
            $this->assertSame($public, array_map(fn (TaskItem $item): array => $item->toArray(), $items));
        }
        $this->assertSame('2026-09-27T23:59:59+13:00', $public[0]['dueAt']);
        $this->assertTrue($public[0]['overdue']);
        $this->assertSame($viewer->id, $public[0]['assignee']['id']);
        $this->injury->updateQuietly(['status' => 'closed']);
        $completed = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => $provider->authorizedTasksInContext($viewer, ['id' => $this->injury->id, 'include_done' => true], $context));
        $this->assertSame(TaskItem::BUCKET_DONE, $completed[0]->bucket);
        $this->assertSame('injury-'.$this->injury->id, $completed[0]->id);
        $this->assertSame([], HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
            fn ($context): array => $provider->authorizedNavigationTasksInContext($viewer, $context)));
    }

    public function test_wrong_actor_and_unprepared_context_are_rejected_before_injury_query(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $other = $this->staff(['hazards.view']);
        $provider = new WorkplaceInjuryProvider;
        foreach (['actor', 'permissions'] as $mismatch) {
            [$result, $queries] = $this->queries(fn () => HealthSafetyReadContext::within($viewer,
                $mismatch === 'permissions' ? ['hazards.view'] : InjuryAccessService::PERMISSIONS,
                function ($context) use ($viewer, $other, $provider, $mismatch): bool {
                    try {
                        $provider->authorizedTasksInContext($mismatch === 'actor' ? $other : $viewer, [], $context);
                    } catch (LogicException $error) {
                        return true;
                    }
                    return false;
                }));
            $this->assertTrue($result);
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('workplace_injuries', strtolower($query['query']));
            }
        }
    }

    public function test_loaded_denial_then_current_regrant_restores_public_context_list_drawer_and_navigation(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view']);
        $this->injury->updateQuietly(['next_review_date' => '2026-09-27']);
        $this->revoke($viewer, 'hazards.view');
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertFalse($viewer->canDo('hazards.view'));
        $provider = new WorkplaceInjuryProvider;
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $this->grant($viewer, 'hazards.view');
        $this->assertFalse($viewer->canDo('hazards.view'));
        $this->assertCount(1, $provider->authorizedTasks($viewer));
        $aggregator = new TaskAggregator([$provider]);
        $this->app->instance(TaskAggregator::class, $aggregator);
        $this->assertCount(1, $aggregator->itemsFor($viewer));
        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => false], $aggregator->navigationBadgeFor($viewer));
        $this->actingAs($viewer)->get('/tasks?sources=injury')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('pagination.total', 1));
        $url = '/tasks/detail?source=injury&id='.$this->injury->id;
        $this->getJson($url)->assertOk()->assertJsonPath('canOpen', true);
        $this->post('/tasks/injury/'.$this->injury->id.'/watch',
            ['watching' => true, 'return_to' => '/tasks?sources=injury'])->assertRedirect();
        $this->assertDatabaseHas('task_watchers', ['source' => 'injury', 'item_id' => $this->injury->id, 'user_id' => $viewer->id]);
        $this->revoke($viewer, 'hazards.view');
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $this->assertSame(['view' => false, 'badge' => 0, 'degraded' => false], $aggregator->navigationBadgeFor($viewer));
        $this->getJson($url)->assertNotFound();
        $this->post('/tasks/injury/'.$this->injury->id.'/watch',
            ['watching' => true, 'return_to' => '/tasks?sources=injury'])->assertNotFound();
        $this->post('/tasks/injury/'.$this->injury->id.'/watch',
            ['watching' => false, 'return_to' => '/tasks?sources=injury'])->assertRedirect();
        $this->assertDatabaseMissing('task_watchers', ['source' => 'injury', 'item_id' => $this->injury->id, 'user_id' => $viewer->id]);
        $this->grant($viewer, 'hazards.view');
        $this->getJson($url)->assertOk()->assertJsonPath('item.link', '/health-safety/injuries/'.$this->injury->id);
    }

    public function test_source_chooser_rechecks_preloaded_grants_without_loading_task_or_child_rows(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $aggregator = new TaskAggregator([new WorkplaceInjuryProvider]);
        [$sources, $queries] = $this->queries(fn () => $aggregator->sourcesFor($viewer));
        $this->assertSame([['key' => 'injury', 'label' => 'Workplace Injuries']], $sources);
        foreach ($queries as $query) {
            foreach (['workplace_injuries', 'hs_events', 'first_aid_records', 'workplace_injury_source_versions', 'return_to_work_plans'] as $sourceTable) {
                $this->assertStringNotContainsString($sourceTable, strtolower($query['query']));
            }
        }
        $this->revoke($viewer, 'hazards.view');
        $this->assertTrue($viewer->canDo('hazards.view'));
        $this->assertSame([], $aggregator->sourcesFor($viewer));
        $this->grant($viewer, 'hazards.view');
        $this->assertSame([['key' => 'injury', 'label' => 'Workplace Injuries']], $aggregator->sourcesFor($viewer));
    }

    public function test_mixed_real_noncontextual_provider_and_exact_contextual_lookup_keep_independent_scope(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $hazard = SiteHazard::withoutEvents(fn () => SiteHazard::query()->create([
            'site_id' => $this->site->id, 'reference_number' => 'HAZ-PROJECTION-1',
            'reported_by_user_id' => $viewer->id, 'hazard_type' => 'other', 'custom_hazard_type' => 'Actual mixed source hazard',
            'severity' => 'low', 'likelihood' => 'rare', 'risk_rating' => 'low',
            'description' => 'Independent authorised hazard', 'status' => 'open', 'due_date' => today()->addDay()]));
        $injuryProvider = new WorkplaceInjuryProvider;
        $hazardProvider = new SiteHazardProvider;
        $aggregator = new TaskAggregator([$injuryProvider, $hazardProvider]);
        $this->assertSame([['key' => 'injury', 'label' => $injuryProvider->label()],
            ['key' => 'hazard', 'label' => $hazardProvider->label()]], $aggregator->sourcesFor($viewer));
        $hazardItem = $hazardProvider->authorizedTasks($viewer, ['id' => $hazard->id])[0];
        $this->assertSame($hazardItem->toArray(), $aggregator->findItemFor($viewer, 'hazard', $hazard->id)->toArray());
        [$item, $queries] = $this->queries(fn () => $aggregator->findItemFor($viewer, 'injury', $this->injury->id,
            ['return_to' => '/tasks?sources=injury']));
        $this->assertSame('injury-'.$this->injury->id, $item->id);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('site_hazards', strtolower($query['query']));
        }
        $ids = array_map(fn (TaskItem $task): string => $task->id, $aggregator->itemsFor($viewer));
        $this->assertContains('injury-'.$this->injury->id, $ids);
        $this->assertContains($hazardItem->id, $ids);
    }

    public function test_foreign_and_missing_exact_task_identity_cannot_open_or_follow_and_site_restore_is_current(): void
    {
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $viewer = $this->staff(['hazards.view']);
        $foreign = $this->injuryAt(Site::factory()->create(['is_active' => true]));
        $this->app->instance(TaskAggregator::class, new TaskAggregator([new WorkplaceInjuryProvider]));
        foreach ([$foreign->id, 2147483647] as $id) {
            $this->actingAs($viewer)->getJson('/tasks/detail?source=injury&id='.$id)->assertNotFound();
            $this->post('/tasks/injury/'.$id.'/watch', ['watching' => true])->assertNotFound();
            $this->assertDatabaseMissing('task_watchers', ['source' => 'injury', 'item_id' => $id, 'user_id' => $viewer->id]);
        }
        $this->site->update(['is_active' => false]);
        $this->getJson('/tasks/detail?source=injury&id='.$this->injury->id)->assertNotFound();
        $this->site->update(['is_active' => true]);
        $this->getJson('/tasks/detail?source=injury&id='.$this->injury->id)->assertOk()
            ->assertJsonPath('item.link', '/health-safety/injuries/'.$this->injury->id);
        $this->injury->updateQuietly(['status' => 'closed']);
        $this->getJson('/tasks/detail?source=injury&id='.$this->injury->id)->assertOk()
            ->assertJsonPath('item.bucket', TaskItem::BUCKET_DONE);
    }

    private function capturedInjury(): User
    {
        $writer = $this->staff(['hazards.create', 'hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $receipt = app(WorkplaceInjurySourceService::class)->create($writer,
            ['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'injury_date' => '2026-10-10T03:00:00+13:00',
                'injury_type' => 'strain', 'body_part_affected' => 'Shoulder', 'severity' => 'minor',
                'description' => 'Prospectively recorded factual injury', 'medical_treatment_type' => 'gp_visit',
                'immediate_treatment' => 'Private clinical treatment', 'notes' => 'Private recovery note',
                'acc_claim_number' => 'PRIVATE-ACC-123', 'idempotency_key' => (string) Str::uuid()]);
        $this->injury = WorkplaceInjury::findOrFail($receipt['injury_id']);
        return $writer;
    }

    private function projection(): InjurySourceProjectionService
    {
        return app(InjurySourceProjectionService::class);
    }

    private function injuryAt(Site $site, array $values = []): WorkplaceInjury
    {
        return WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $site->id, 'created_by' => $this->viewer->id,
            'status' => 'reported', 'source_schema_version' => 0, 'source_revision' => 0, ...$values,
        ]));
    }

    private function hs(?WorkplaceInjury $injury = null): HsEvent
    {
        $injury ??= $this->injury;
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => WorkplaceInjury::class, 'source_id' => $injury->id, 'event_category' => HsEvent::CATEGORY_INJURY,
            'site_id' => $injury->site_id, 'staff_id' => $injury->user_id, 'client_id' => null, 'asset_id' => null,
            'created_by' => $this->viewer->id, 'severity' => 'medium', 'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'idempotency_key' => HsEvent::buildIdempotencyKey(WorkplaceInjury::class, $injury->id, HsEvent::CATEGORY_INJURY),
        ]));
    }

    private function alert(): ControlRoomAlert
    {
        return ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'source' => 'operations', 'alert_type' => 'operations.workplace_injury', 'client_id' => null,
            'site_id' => $this->site->id, 'status' => ControlRoomAlert::STATUS_OPEN,
            'notes' => 'Independent operational response remains',
            'context' => ['workplace_injury_id' => $this->injury->id, 'site_id' => $this->site->id, 'user_id' => $this->worker->id,
                'description' => 'Copied old injury description', 'body_part_affected' => 'Copied old body part',
                'acc_claim_number' => 'Private copied claim sentinel'],
        ]));
    }

    private function queries(callable $operation): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $operation();
            return [$result, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function assertNoPrivateQueries(array $queries): void
    {
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            foreach (['work_capacity_assessments', 'return_to_work_plans', 'modified_duties', 'workplace_injury_attachments',
                'acc_claim_number', 'medical_treatment_type', 'immediate_treatment'] as $private) {
                $this->assertStringNotContainsString($private, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])content(?![a-z_])/', $sql);
            $this->assertDoesNotMatchRegularExpression('/select\s+(?:[\x60"]?workplace_injuries[\x60"]?\.)?\*\s+from\s+[\x60"]?workplace_injuries/i', $sql);
        }
    }

    private function assertNoPrivateFacts(mixed $value): void
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-ACC-123', 'Private clinical treatment', 'Private recovery note', 'Private copied claim sentinel',
            'capacity_assessments', 'return_to_work_plans', 'fitness_approval', 'assessment_summary'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
    }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertDetached($nested);
            }
        } else {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }
}
