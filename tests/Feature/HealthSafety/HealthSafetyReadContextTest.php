<?php

namespace Tests\Feature\HealthSafety;

use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\ProcedureReviewRequest;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\Providers\EmergencyDrillFindingProvider;
use App\Services\Tasks\Providers\EmergencyDrillProvider;
use App\Services\Tasks\Providers\FirstAidFollowupProvider;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\Providers\HsEventProvider;
use App\Services\Tasks\Providers\HsInvestigationProvider;
use App\Services\Tasks\Providers\ProcedureReviewRequestProvider;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

class HealthSafetyReadContextTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
        foreach (['procedures.view', 'procedures.manage'] as $key) {
            $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
    }

    private function keys(): array
    {
        return [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS];
    }

    private function event(FirstAidRecord $source): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $source->site_id, 'source_type' => FirstAidRecord::class, 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $source->client_id, 'staff_id' => $source->treated_person_id,
            'owner_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
    }

    public function test_populated_sources_use_bounded_queries_and_keep_their_canonical_items(): void
    {
        $treatment = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $event = $this->event($treatment);
        $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'lead_investigator_id' => $this->manager->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'hs_investigation_id' => $investigation->id, 'assigned_to_user_id' => $this->manager->id]);
        $followup = FirstAidFollowup::create(['first_aid_record_id' => $treatment->id, 'assigned_to_user_id' => $this->manager->id, 'created_by' => $this->manager->id, 'due_at' => now()->addDay()]);
        $procedure = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => [], 'applicable_sites' => [$this->sourceSite->id], 'created_by' => $this->manager->id, 'approved_by' => $this->manager->id])->refresh();
        $review = ProcedureReviewRequest::create(['safe_work_procedure_id' => $procedure->id, 'hs_event_id' => $event->id, 'source_site_id' => $event->site_id, 'requested_version_id' => $procedure->published_version_id,
            'requested_by_user_id' => $this->manager->id, 'assigned_to_user_id' => $this->manager->id, 'note' => 'Synthetic bounded source review', 'idempotency_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', (string) $event->id), 'requested_at' => now(), 'status' => 'requested', 'lock_version' => 1]);
        $drill = EmergencyDrill::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id, 'conducted_by' => $this->manager->id]);
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id, 'assigned_to' => $this->manager->id]);
        $aggregate = new TaskAggregator(array_map(fn ($class) => app($class), [HsEventProvider::class, HsInvestigationProvider::class, HsCorrectiveActionProvider::class,
            FirstAidFollowupProvider::class, ProcedureReviewRequestProvider::class, EmergencyDrillProvider::class, EmergencyDrillFindingProvider::class]));

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $items = $aggregate->itemsFor($this->manager);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $expected = ['hs_event-'.$event->id, 'hs_investigation-'.$investigation->id, 'corrective_action-'.$action->id,
            'first_aid_followup-'.$followup->id, 'procedure_review_request-'.$review->id, 'emergency_drill-'.$drill->id, 'emergency_drill_finding-'.$finding->id];
        $this->assertEqualsCanonicalizing($expected, array_column($items, 'id'));
        $this->assertLessThan(100, $queries, "Seven populated source projections used {$queries} queries.");
    }

    public function test_escaped_context_and_captured_source_builder_cannot_read_after_callback(): void
    {
        $captured = $query = null;
        HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use (&$captured, &$query): array {
            $captured = $context;
            $query = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query(), $this->manager, $context);

            return [];
        });
        foreach ([fn () => $captured->assertActive(), fn () => $query->get()] as $escaped) {
            try {
                $escaped();
                $this->fail('Escaped evidence must not execute.');
            } catch (LogicException $error) {
                $this->assertMatchesRegularExpression('/scope|callback/', $error->getMessage());
            }
        }
    }

    public function test_returning_a_builder_or_actor_or_context_is_rejected_and_invalidates_context(): void
    {
        foreach (['builder', 'actor', 'context'] as $kind) {
            $captured = null;
            try {
                HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($kind, &$captured) {
                    $captured = $context;

                    return match ($kind) {
                        'builder' => $context->query(HsEvent::query()),
                        'actor' => $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS, fn ($actor) => $actor),
                        default => $context,
                    };
                });
                $this->fail('An authority object escaped its callback.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('materialized', $error->getMessage());
            }
            try {
                $captured->assertActive();
                $this->fail('Rejected callbacks must invalidate evidence.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('callback', $error->getMessage());
            }
        }
    }

    public function test_separate_callbacks_observe_permission_hr_site_and_exact_source_revocation(): void
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $event = $this->event($record);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile');
        $visible = fn () => HealthSafetyReadContext::within($this->manager, $this->keys(), fn ($context) => app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query()->whereKey($event->id), $this->manager, $context)->exists());
        $this->assertTrue($visible());
        $permission = Permission::where('key', 'hazards.view')->sole();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertFalse($visible());
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertFalse($visible());
        $this->manager->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => null]);
        $this->assertFalse($visible());
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        $event->forceFill(['staff_id' => $this->firstAider->id])->saveQuietly();
        $this->assertFalse($visible());
    }

    public function test_empty_whitelist_cannot_widen_for_a_source_inserted_inside_the_callback(): void
    {
        HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context): array {
            $access = app(HsEventAccessService::class);
            $this->assertFalse($access->applyFirstAidSourceScope(HsEvent::query()->where('source_type', FirstAidRecord::class), $this->manager, $context)->exists());
            $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
            $event = $this->event($record);
            $this->assertFalse($access->applyFirstAidSourceScope(HsEvent::query()->whereKey($event->id), $this->manager, $context)->exists());

            return [];
        });
        $this->assertTrue(app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query(), $this->manager)->where('source_type', FirstAidRecord::class)->exists());
    }

    public function test_bounded_treatment_sets_remain_disjoint_and_do_not_seed_or_reuse_global_memo(): void
    {
        $first = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        $second = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        $ordinary = HsEvent::factory()->create(['site_id' => $this->sourceSite->id]);
        HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($first, $second, $ordinary): array {
            $access = app(HsEventAccessService::class);
            $final = HsEvent::whereKey([$first->id, $second->id, $ordinary->id]);
            $empty = HsEvent::whereRaw('1 = 0');
            $this->assertSame([$ordinary->id], $access->applyFirstAidSourceScope(clone $final, $this->manager, $context, $empty)->pluck('id')->all());
            foreach ([$first, $second] as $event) {
                $candidate = HsEvent::whereKey($event->id)->where('site_id', $event->site_id)->where('source_id', $event->source_id);
                $sql = $candidate->toSql();
                $bindings = $candidate->getBindings();
                $this->assertEqualsCanonicalizing([$event->id, $ordinary->id], $access->applyFirstAidSourceScope(clone $final, $this->manager, $context, $candidate)->pluck('id')->all());
                $this->assertSame($sql, $candidate->toSql());
                $this->assertSame($bindings, $candidate->getBindings());
            }
            $this->assertEqualsCanonicalizing([$first->id, $second->id, $ordinary->id], $access->applyFirstAidSourceScope(clone $final, $this->manager, $context)->pluck('id')->all());
            $this->assertSame([$ordinary->id], $access->applyFirstAidSourceScope(clone $final, $this->manager, $context, $empty)->pluck('id')->all());

            return [];
        });
    }

    public function test_bounded_treatment_proof_rejects_missing_corrupt_and_foreign_source_tuples(): void
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $good = $this->event($record);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreign = FirstAidRecord::factory()->create(['site_id' => $foreignSite->id, 'first_aider_id' => $this->firstAider->id]);
        $invalid = collect([
            ['source_id' => 999999999],
            ['staff_id' => $this->firstAider->id],
            ['event_category' => HsEvent::CATEGORY_INCIDENT],
            ['site_id' => $foreignSite->id],
            ['source_id' => $foreign->id],
        ])->map(fn ($fields) => HsEvent::factory()->createQuietly([
            'site_id' => $record->site_id, 'source_type' => $record->getMorphClass(), 'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => null, 'staff_id' => null, ...$fields,
        ]));
        $ids = [$good->id, ...$invalid->pluck('id')->all()];
        $actual = HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($ids): array {
            $candidate = HsEvent::whereKey($ids);

            return app(HsEventAccessService::class)->applyFirstAidSourceScope(clone $candidate, $this->manager, $context, $candidate)->pluck('id')->all();
        });
        $this->assertSame([$good->id], $actual);
        $this->assertDatabaseCount('first_aid_records', 2);
    }

    public function test_bounded_treatment_sql_intersects_requested_ids_and_never_hydrates_private_rows(): void
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $event = $this->event($record);
        $other = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($event, $other): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $candidate = HsEvent::whereKey($event->id)->where('site_id', $event->site_id)
                    ->where('source_type', $event->source_type)->where('source_id', $event->source_id);
                $this->assertSame([$event->id], app(HsEventAccessService::class)->applyFirstAidSourceScope(clone $candidate, $this->manager, $context, $candidate)->pluck('id')->all());
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $proofs = array_values(array_filter($queries, fn ($row) => str_contains(strtolower($row['query']), 'first_aid_records')));
            $this->assertCount(1, $proofs);
            $sql = strtolower(str_replace('`', '', $proofs[0]['query']));
            $this->assertStringContainsString('hs_events.id in (select hs_events.id from hs_events', $sql);
            $this->assertStringContainsString('exists (select 1 from first_aid_records', $sql);
            $this->assertContains($event->id, $proofs[0]['bindings']);
            $this->assertStringNotContainsString('treatment_given', $sql);
            $this->assertStringNotContainsString('injury_illness_description', $sql);
            foreach ($queries as $row) {
                $from = explode(' from ', strtolower(str_replace('`', '', $row['query'])), 2)[1] ?? '';
                $this->assertFalse(str_starts_with($from, 'first_aid_records'), 'Treatment proof must not hydrate a private source row.');
            }
            $this->assertDatabaseHas('hs_events', ['id' => $other->id]);

            return [];
        });
    }

    public function test_bounded_treatment_callbacks_observe_loaded_actor_revoke_restore_and_site_move(): void
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $event = $this->event($record);
        $this->manager->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
        $read = fn () => HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($event): bool {
            $candidate = HsEvent::whereKey($event->id);

            return app(HsEventAccessService::class)->applyReadableScope(clone $candidate, $this->manager, $context, $candidate)->exists();
        });
        $this->assertTrue($read());
        $permission = Permission::where('key', 'hazards.view')->sole();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertFalse($read());
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->assertTrue($read());
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->assertFalse($read());
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        $this->assertTrue($read());
    }

    public function test_bounded_treatment_candidates_retain_independent_outer_relation_and_omitted_binding_denial(): void
    {
        $first = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        $second = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        $included = HsCorrectiveAction::factory()->create(['hs_event_id' => $first->id, 'assigned_to_user_id' => $this->manager->id]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $second->id, 'assigned_to_user_id' => $this->manager->id]);
        $actual = HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($first): array {
            $candidates = HsEvent::whereKey($first->id);

            return HsCorrectiveAction::query()->whereHas('hsEvent', fn ($related) => app(HsEventAccessService::class)
                ->applyReadableScope($related, $this->manager, $context, $candidates))->pluck('id')->all();
        });
        $this->assertSame([$included->id], $actual);
    }

    public function test_bounded_treatment_standalone_wrapper_preserves_unrelated_sources_and_archived_read(): void
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]);
        $first = $this->event($record);
        $second = $this->event(FirstAidRecord::factory()->create(['site_id' => $this->sourceSite->id, 'first_aider_id' => $this->firstAider->id]));
        $record->deleteQuietly();
        $ordinary = HsEvent::factory()->create(['site_id' => $this->sourceSite->id]);
        $actual = DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidSourceScope(
            HsEvent::whereKey([$first->id, $second->id, $ordinary->id]), $this->manager, null, HsEvent::whereKey($first->id),
        )->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$first->id, $ordinary->id], $actual);
    }

    public function test_strict_treatment_denial_preserves_ordinary_source_read_and_module_capabilities(): void
    {
        $ordinary = HsEvent::factory()->create(['site_id' => $this->sourceSite->id]);
        $this->manager->forceFill(['approved_at' => null])->saveQuietly();
        $this->assertTrue(HealthSafetyReadContext::within($this->manager, $this->keys(), fn ($context) => app(HsEventAccessService::class)->canView($ordinary, $this->manager, $context)));
        $this->manager->forceFill(['approved_at' => now()])->saveQuietly();
        $permission = Permission::where('key', 'procedures.view')->sole();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertSame(['view_procedure_requests' => false, 'request_procedure_review' => false], HealthSafetyReadContext::within($this->manager, $this->keys(), fn ($context) => app(SafeWorkProcedureAccessService::class)->sourceCan($ordinary, $this->manager, $context)));
    }

    public function test_unprepared_permissions_foreign_viewer_and_different_connection_are_rejected(): void
    {
        HealthSafetyReadContext::within($this->manager, FirstAidAccessService::PERMISSIONS, function ($context): array {
            foreach ([fn () => $context->consumeViewer(SafeWorkProcedureAccessService::PERMISSIONS, fn () => true), fn () => $context->assertViewer($this->worker)] as $invalid) {
                try {
                    $invalid();
                    $this->fail('Unprepared or foreign authority was consumed.');
                } catch (LogicException $error) {
                    $this->assertNotEmpty($error->getMessage());
                }
            }
            $original = DB::getDefaultConnection();
            config(['database.connections.hs_context_other' => config('database.connections.'.$original)]);
            try {
                DB::setDefaultConnection('hs_context_other');
                $context->assertActive();
                $this->fail('A different connection consumed authority.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('transaction scope', $error->getMessage());
            } finally {
                DB::setDefaultConnection($original);
                DB::purge('hs_context_other');
            }

            return [];
        });
    }
}
