<?php

namespace Tests\Feature\Incidents;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\IncidentIndependentHandlingDeclaration;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Incidents\IncidentIndependencePolicySettings;
use App\Services\Incidents\IncidentTaskReadService;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use App\Services\Tasks\Providers\IncidentFollowupProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Complete real-source suite. Native execution, performance and related-domain consumer closure remain UNRUN. */
final class IncidentIndependentTaskReadTest extends IncidentIndependenceTestCase
{
    private function keys(): array
    {
        return array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS]));
    }

    private function providers(): array { return [new ClientIncidentProvider, new IncidentFollowupProvider]; }
    private function aggregate(): TaskAggregator { return new TaskAggregator($this->providers()); }
    private function child(int $parent, array $fields = []): IncidentFollowup
    {
        return IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create(['client_incident_id' => $parent,
            'assigned_to_user_id' => $this->manager->id, 'notes' => 'Exact private followup instruction',
            'due_at' => now()->subDay(), 'created_by' => $this->reporter->id, ...$fields]));
    }
    private function taskIds(User $viewer, array $filters = []): array
    {
        $ids = array_map(fn (TaskItem $item) => $item->id, $this->aggregate()->itemsFor($viewer, $filters)); sort($ids); return $ids;
    }
    private function inContext(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, $this->keys(), $operation);
    }

    public function test_named_strong_source_denial_excludes_both_tasks_before_search_and_eager_hydration(): void
    {
        $receipt = $this->accept($this->command()); $child = $this->child($receipt['incident_id']); $healthy = $this->incident();
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(['incident-'.$healthy->id], $this->taskIds($this->lead));
        $this->assertSame([], $this->taskIds($this->lead, ['q' => 'Synthetic private source text']));
        $this->assertNull($this->aggregate()->findItemFor($this->lead, 'followup', $child->id));
        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'from `incident_followups`') && ! str_contains($sql, 'client_incident_id')));
    }

    public function test_assignment_only_retains_ordinary_read_without_turning_capture_into_read(): void
    {
        app(IncidentIndependencePolicySettings::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future assignment-only sources.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1])); $child = $this->child($receipt['incident_id']);
        $this->assertSame(['followup-'.$child->id, 'incident-'.$receipt['incident_id']], $this->taskIds($this->lead));
        $this->assertSame([], $this->taskIds($this->reporter));
    }

    public function test_current_site_withdrawal_and_restore_requalify_incident_and_child(): void
    {
        $root = $this->incident(); $child = $this->child($root->id);
        $expected = ['followup-'.$child->id, 'incident-'.$root->id]; $this->assertSame($expected, $this->taskIds($this->manager));
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $this->assertSame([], $this->taskIds($this->manager));
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame($expected, $this->taskIds($this->manager));
    }

    public function test_hr_confidential_grant_withdrawal_and_restore_are_current(): void
    {
        $root = $this->incident(['is_hr_confidential' => true]); $this->child($root->id);
        $this->assertSame([], $this->taskIds($this->manager)); $this->allow($this->manager, 'hr.cases.view');
        $this->assertCount(2, $this->taskIds($this->manager)); $this->allow($this->manager, 'hr.cases.view', false);
        $this->assertSame([], $this->taskIds($this->manager));
    }

    public function test_assigned_client_membership_remains_required_for_view_assigned(): void
    {
        $root = $this->incident(); $child = $this->child($root->id);
        $this->allow($this->lead, 'incidents.viewAny', false); $this->allow($this->lead, 'incidents.viewAssigned');
        $this->assertSame([], $this->taskIds($this->lead)); $root->client->supportWorkers()->attach($this->lead->id);
        $this->assertSame(['followup-'.$child->id, 'incident-'.$root->id], $this->taskIds($this->lead));
        $root->client->supportWorkers()->detach($this->lead->id); $this->assertSame([], $this->taskIds($this->lead));
    }

    public function test_warmed_true_revocation_is_not_memoised_by_provider_or_aggregator(): void
    {
        $root = $this->incident(); $aggregate = $this->aggregate();
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->assertNotNull($aggregate->findItemFor($warm, 'incident', $root->id));
        $this->allow($warm, 'incidents.viewAny', false); $this->allow($warm, 'incidents.viewAssigned', false);
        $this->assertTrue($warm->canDo('incidents.viewAny'));
        $this->assertNull($aggregate->findItemFor($warm, 'incident', $root->id));
        $this->assertSame([], $aggregate->sourcesFor($warm)); $this->assertSame([], $aggregate->itemsFor($warm));
        $this->assertFalse($aggregate->navigationBadgeFor($warm)['view']);
    }

    public function test_warmed_false_restoration_reaches_public_list_lookup_chooser_and_navigation(): void
    {
        $root = $this->incident(); $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']); $this->allow($warm, 'incidents.viewAny');
        $this->assertFalse($warm->canDo('incidents.viewAny')); $aggregate = $this->aggregate();
        $this->assertCount(1, (new ClientIncidentProvider)->authorizedTasks($warm));
        $this->assertNotNull($aggregate->findItemFor($warm, 'incident', $root->id));
        $this->assertCount(2, $aggregate->sourcesFor($warm)); $this->assertCount(1, $aggregate->itemsFor($warm));
        $this->assertTrue($aggregate->navigationBadgeFor($warm)['view']);
    }

    public function test_damaged_declaration_conceals_only_its_tasks_and_restored_evidence_restores_them(): void
    {
        $receipt = $this->accept($this->command()); $this->child($receipt['incident_id']); $healthy = $this->incident();
        $row = IncidentIndependentHandlingDeclaration::findOrFail($receipt['declaration_id']); $original = $row->getRawOriginal('handling');
        DB::table($row->getTable())->where('id', $row->id)->update(['handling' => 'false']);
        $this->assertSame(['incident-'.$healthy->id], $this->taskIds($this->manager));
        DB::table($row->getTable())->where('id', $row->id)->update(['handling' => $original]);
        $this->assertCount(3, $this->taskIds($this->manager));
    }

    public function test_foreign_parent_and_site_filter_never_project_child_instructions(): void
    {
        $local = $this->incident(); $foreign = $this->incident(['site_id' => Site::factory()->create()->id]); $child = $this->child($foreign->id);
        $this->assertSame(['incident-'.$local->id], $this->taskIds($this->manager));
        $this->assertSame([], (new IncidentFollowupProvider)->authorizedTasks($this->manager, ['id' => $child->id]));
        $this->assertSame([], $this->taskIds($this->manager, ['site_id' => $foreign->site_id]));
    }

    public function test_closed_incident_and_completed_followup_exact_lookup_preserve_terminal_history(): void
    {
        $root = $this->incident(['status' => 'closed']); $child = $this->child($root->id, ['completed_at' => now()]);
        $this->assertSame([], $this->taskIds($this->manager));
        $items = $this->aggregate()->itemsFor($this->manager, ['include_done' => true]); $this->assertCount(2, $items);
        foreach ($items as $item) { $this->assertSame(TaskItem::BUCKET_DONE, $item->bucket); }
        $this->assertNotNull($this->aggregate()->findItemFor($this->manager, 'followup', $child->id));
    }

    public function test_recorded_strong_policy_still_excludes_named_reader_after_closure(): void
    {
        $receipt = $this->accept($this->command()); $this->child($receipt['incident_id']);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['status' => 'closed']);
        $this->assertSame([], $this->taskIds($this->lead, ['include_done' => true]));
        $this->assertCount(2, $this->taskIds($this->manager, ['include_done' => true]));
    }

    public function test_navigation_identity_due_and_bucket_match_full_feed_without_body_or_journey(): void
    {
        $root = $this->incident(); $this->child($root->id);
        $this->inContext($this->manager, function (HealthSafetyReadContext $context): array {
            foreach ($this->providers() as $provider) {
                $full = $provider->authorizedTasksInContext($this->manager, [], $context);
                $nav = $provider->authorizedNavigationTasksInContext($this->manager, $context);
                $this->assertSame(array_column($full, 'id'), array_column($nav, 'id'));
                foreach ($nav as $index => $item) {
                    $this->assertSame($full[$index]->bucket, $item->bucket); $this->assertSame($full[$index]->dueAt, $item->dueAt);
                    $this->assertNull($item->journey); $this->assertNull($item->description); $this->assertSame('', $item->title);
                }
            }
            return [];
        });
    }

    public function test_supplied_context_uses_same_live_reads_for_parent_counts_rows_and_child_lookup(): void
    {
        $receipt = $this->accept($this->command()); $child = $this->child($receipt['incident_id']);
        $this->inContext($this->manager, function (HealthSafetyReadContext $context) use ($child): array {
            return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS, function ($current, CurrentAuthorizationReads $reads) use ($context, $child): array {
                $parents = app(IncidentTaskReadService::class)->incidentParents($current, $reads);
                $this->assertSame(1, (clone $parents)->count());
                $this->assertSame(1, (clone $parents)->paginate(50)->total());
                $this->assertNotNull($this->aggregate()->findItemFor($this->manager, 'followup', $child->id, [], $context));
                return [];
            });
        });
    }

    public function test_missing_preparation_and_wrong_actor_are_errors_not_fresh_fallbacks(): void
    {
        $this->incident();
        try {
            HealthSafetyReadContext::within($this->manager, ['incidents.viewAny'], fn ($context) => (new ClientIncidentProvider)->authorizedTasksInContext($this->manager, [], $context));
            $this->fail('Incomplete preparation must remain visible.');
        } catch (LogicException $error) { $this->assertStringContainsString('prepare', $error->getMessage()); }
        try {
            $this->inContext($this->manager, fn ($context) => (new IncidentFollowupProvider)->authorizedTasksInContext($this->cover, [], $context));
            $this->fail('Another actor cannot consume this callback.');
        } catch (LogicException $error) { $this->assertStringContainsString('viewer', $error->getMessage()); }
    }

    public function test_escaped_context_and_parent_builder_cannot_execute_after_callback(): void
    {
        $context = null; $builder = null;
        $this->inContext($this->manager, function ($active) use (&$context, &$builder): array {
            $context = $active;
            $active->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS, function ($current, $reads) use (&$builder): void {
                $builder = app(IncidentTaskReadService::class)->incidentParents($current, $reads);
            });
            return [];
        });
        foreach ([fn () => $context->assertActive(), fn () => $builder->count()] as $operation) {
            try { $operation(); $this->fail('Escaped authority must refuse execution.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
    }

    public function test_late_parent_reassociation_invalidates_final_graph_before_rows_and_counts(): void
    {
        $receipt = $this->accept($this->command());
        $this->inContext($this->manager, function ($context) use ($receipt): array {
            $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS, function ($current, $reads) use ($receipt): void {
                $parents = app(IncidentTaskReadService::class)->incidentParents($current, $reads);
                $this->assertSame(1, (clone $parents)->count());
                DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['reported_by' => $this->cover->id]);
                $this->assertSame(0, (clone $parents)->count()); $this->assertSame([], (clone $parents)->get()->all());
            }); return [];
        });
    }

    public function test_late_followup_reparent_between_materialisation_and_projection_is_concealed(): void
    {
        $local = $this->incident(); $foreign = $this->incident(['site_id' => Site::factory()->create()->id]); $child = $this->child($local->id);
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $child, $foreign): void {
            if (! $armed || ! str_contains($sql, 'BINARY `incident_followups`.`notes`')) { return; }
            $armed = false; $fired = true;
            DB::table('incident_followups')->where('id', $child->id)->update(['client_incident_id' => $foreign->id]);
        });
        try { $this->assertSame([], (new IncidentFollowupProvider)->authorizedTasks($this->manager, ['id' => $child->id])); $this->assertTrue($fired); }
        finally { $armed = false; }
    }

    public function test_case_only_and_trailing_space_instruction_changes_do_not_pass_final_raw_fact_comparison(): void
    {
        foreach (['exact private followup instruction', 'Exact private followup instruction '] as $replacement) {
            $root = $this->incident(); $child = $this->child($root->id); $state = (object) ['armed' => true, 'fired' => false];
            DB::connection()->beforeExecuting(function ($sql) use ($state, $child, $replacement): void {
                if (! $state->armed || ! str_contains($sql, 'BINARY `incident_followups`.`notes`')) { return; }
                $state->armed = false; $state->fired = true; DB::table('incident_followups')->where('id', $child->id)->update(['notes' => $replacement]);
            });
            try { $this->assertSame([], (new IncidentFollowupProvider)->authorizedTasks($this->manager, ['id' => $child->id])); $this->assertTrue($state->fired); }
            finally { $state->armed = false; }
            DB::table('incident_followups')->where('id', $child->id)->update(['notes' => 'Exact private followup instruction']);
            $this->assertCount(1, (new IncidentFollowupProvider)->authorizedTasks($this->manager, ['id' => $child->id]));
        }
    }

    public function test_followup_permalink_and_recorded_deadline_are_not_invented_from_parent_lifecycle(): void
    {
        $root = $this->incident(); $child = $this->child($root->id, ['due_at' => null]);
        $item = $this->aggregate()->findItemFor($this->manager, 'followup', $child->id);
        $this->assertSame('/incidents/'.$root->id.'?section=followups', $item->link); $this->assertNull($item->dueAt);
    }

    public function test_owner_summary_reuses_protected_parent_before_its_source_reload_and_conceals_only_changed_source(): void
    {
        $receipt = $this->accept($this->command()); $healthy = $this->incident();
        $this->inContext($this->manager, function ($context) use ($receipt, $healthy): array {
            return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS, function ($current, $reads) use ($receipt, $healthy): array {
                $leaf = app(IncidentTaskReadService::class); $parents = $leaf->incidentParents($current, $reads);
                $source = (clone $parents)->whereKey($receipt['incident_id'])->firstOrFail(); $armed = true; $fired = false;
                DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $receipt): void {
                    if (! $armed || ! str_contains($sql, 'from `client_incidents`') || ! str_starts_with($sql, 'select `id`, `client_id`')) { return; }
                    $armed = false; $fired = true;
                    DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['reported_by' => $this->cover->id]);
                });
                try {
                    $this->assertNull(app(IncidentOwnershipService::class)->summaryFromProtectedSourceInCurrentReads($current, $source, $reads, $parents));
                    $this->assertTrue($fired);
                } finally { $armed = false; }
                $safe = (clone $parents)->whereKey($healthy->id)->firstOrFail();
                $this->assertSame('unassigned', app(IncidentOwnershipService::class)->summaryFromProtectedSourceInCurrentReads($current, $safe, $reads, $parents)['state']);
                return [];
            });
        });
    }

    public function test_actual_tasks_http_detail_denies_named_source_and_restores_current_grant_without_middleware_suppression(): void
    {
        $receipt = $this->accept($this->command()); $child = $this->child($receipt['incident_id']);
        $this->actingAs($this->lead)->getJson('/tasks/detail?source=followup&id='.$child->id)->assertNotFound();
        $this->actingAs($this->manager)->getJson('/tasks/detail?source=followup&id='.$child->id)->assertOk()
            ->assertJsonPath('item.link', '/incidents/'.$receipt['incident_id'].'?section=followups');
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']); $this->actingAs($warm);
        $this->getJson('/tasks/detail?source=followup&id='.$child->id)->assertNotFound(); $this->allow($warm, 'incidents.viewAny');
        $this->assertFalse($warm->canDo('incidents.viewAny'));
        $this->getJson('/tasks/detail?source=followup&id='.$child->id)->assertOk();
    }
}
