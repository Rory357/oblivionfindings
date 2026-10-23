<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\Site;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Incidents\IncidentRegisterReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Whole suite. Controller successor is an explicit HTTP prerequisite; all native cases UNRUN. */
final class IncidentRegisterOwnerFilterTest extends IncidentRoutingTestCase
{
    protected bool $physicalCommits = true;

    private function read(array $filters = [], $actor = null, ?callable $consumer = null): array
    {
        $request = Request::create('/incidents', 'GET', $filters);
        $request->setUserResolver(fn () => $actor ?? $this->manager);
        return app(IncidentRegisterReadService::class)->index($request, $consumer);
    }
    private function owned(array $fields = []): ClientIncident
    {
        $root = $this->incident(['occurred_at' => now(), 'title' => 'Visible ownership work', ...$fields]);
        app(IncidentOwnershipService::class)->assign($this->manager, $root, $this->lead->id, 0, (string) Str::uuid(), null);
        return $root->fresh();
    }
    private function followup(ClientIncident $root, string $notes = 'Visible followup'): IncidentFollowup
    {
        return IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create([
            'client_incident_id' => $root->id, 'assigned_to_user_id' => $this->lead->id,
            'notes' => $notes, 'due_at' => now()->subHour(), 'created_by' => $this->manager->id]));
    }
    private function ids(array $dto): array { return array_column($dto['rows']['data'], 'id'); }
    private function partial(array $filters = []): \Illuminate\Testing\TestResponse
    {
        return $this->get('/incidents?'.http_build_query($filters), [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'incidents/index', 'X-Inertia-Partial-Data' => 'auth']);
    }

    public function test_all_and_needs_owner_share_site_privacy_counts_and_followup_parent_membership(): void
    {
        $owned = $this->owned(); $needs = $this->incident(['occurred_at' => now()]);
        $this->incident(['status' => 'draft']); $this->incident(['status' => 'closed', 'closed_at' => now()]);
        $foreign = $this->incident(['site_id' => Site::factory()->create()->id, 'description' => 'FOREIGN-PARENT-BODY']);
        $private = $this->incident(['is_hr_confidential' => true, 'description' => 'PRIVATE-HR-PARENT-BODY']);
        $this->followup($owned); $needsFollowup = $this->followup($needs);
        $this->followup($foreign, 'FOREIGN-FOLLOWUP'); $this->followup($private, 'PRIVATE-HR-FOLLOWUP');
        $all = $this->read();
        $this->assertSame(4, $all['tabCounts']['all']); $this->assertSame(2, $all['tabCounts']['followups']);
        $needsDto = $this->read(['owner' => 'needs_owner']);
        $this->assertSame([$needs->id], $this->ids($needsDto)); $this->assertSame(1, $needsDto['tabCounts']['all']);
        $followups = $this->read(['owner' => 'needs_owner', 'tab' => 'followups']);
        $this->assertSame([$needsFollowup->id], $this->ids($followups));
        $this->assertSame(1, $followups['hero']['attention']['followups']['overdue']);
        foreach (['FOREIGN-', 'PRIVATE-HR-'] as $text) { $this->assertStringNotContainsString($text, json_encode($followups, JSON_THROW_ON_ERROR)); }
    }

    public function test_owner_qualification_receives_all_search_site_kind_source_date_and_severity_filters(): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $match = $this->owned(['type' => 'near_miss', 'severity' => 'high', 'source' => 'manual',
            'occurred_at' => '2026-10-11T11:01:00Z', 'description' => 'Exact admitted needle', 'title' => 'Needle']);
        $this->owned(['severity' => 'low', 'description' => 'Exact admitted needle']);
        $this->owned(['occurred_at' => '2026-10-10T10:00:00Z']);
        $dto = $this->read(['owner' => 'me', 'q' => 'needle', 'site_id' => $this->site->id,
            'client_id' => $match->client_id, 'severity' => 'high', 'source' => 'manual', 'kind' => 'near_misses',
            'from' => '2026-10-12', 'to' => '2026-10-12'], $this->lead);
        $this->assertSame([$match->id], $this->ids($dto));
        $this->assertSame(1, $dto['tabCounts']['all']); $this->assertSame(1, $dto['tabCounts']['near_misses']);
        $this->assertSame('me', $dto['filters']['owner']); $this->assertSame($this->lead->id, $dto['viewer_id']);
    }

    public function test_owner_scan_is_once_for_independent_base_not_repeated_per_tab_or_hero(): void
    {
        $this->owned(); $this->incident();
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $dto = $this->read(['owner' => 'needs_owner']); $queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        $scan = array_filter($queries, fn ($row) => str_starts_with(str_replace(chr(96), '', $row['query']),
            'select client_incidents.id, client_incidents.client_id, client_incidents.site_id'));
        $this->assertCount(1, $scan); $this->assertSame(1, $dto['tabCounts']['all']);
    }

    public function test_inactive_revoked_and_damaged_owner_become_replacement_work_and_restore(): void
    {
        $root = $this->owned();
        $this->assertSame([], $this->ids($this->read(['owner' => 'needs_owner'])));
        HrEmployeeProfile::where('user_id', $this->lead->id)->update(['is_active' => false]);
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'needs_owner'])));
        HrEmployeeProfile::where('user_id', $this->lead->id)->update(['is_active' => true]);
        $this->allow($this->lead, 'incidents.owner.hold', false);
        $this->assertSame('replacement_needed', $this->read(['owner' => 'needs_owner'])['rows']['data'][0]['ownership']['state']);
        $this->allow($this->lead, 'incidents.owner.hold');
        $change = $root->ownerChanges()->first(); $audit = DB::table('audit_logs')->where('id', $change->audit_log_id)->first();
        DB::table('audit_logs')->where('id', $audit->id)->update(['meta' => '{}']);
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'needs_owner'])));
        $this->assertSame([], $this->ids($this->read(['owner' => 'me'], $this->lead)));
        DB::table('audit_logs')->where('id', $audit->id)->update(['meta' => $audit->meta]);
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'me'], $this->lead)));
    }

    public function test_me_uses_accepted_owner_not_reporter_investigator_or_unaccepted_pointer_for_open_work(): void
    {
        $root = $this->owned(); $raw = $this->incident(['incident_owner_user_id' => $this->lead->id, 'owner_revision' => 1]);
        $reported = $this->incident(['reported_by' => $this->lead->id, 'investigation_assigned_to' => $this->lead->id]);
        $dto = $this->read(['owner' => 'me'], $this->lead);
        $this->assertSame([$root->id], $this->ids($dto)); $this->assertNotContains($raw->id, $this->ids($dto));
        $this->assertNotContains($reported->id, $this->ids($dto));
    }

    public function test_drafts_are_excluded_from_needs_owner_and_closed_accepted_history_remains_historical(): void
    {
        $this->incident(['status' => 'draft']); $closed = $this->owned();
        $closed->updateQuietly(['status' => 'closed', 'closed_at' => now()]);
        $this->allow($this->lead, 'incidents.owner.hold', false);
        $this->assertSame([], $this->ids($this->read(['owner' => 'needs_owner'])));
        $dto = $this->read(['owner' => 'me', 'tab' => 'closed'], $this->lead);
        $this->assertSame([$closed->id], $this->ids($dto)); $this->assertSame('historical', $dto['rows']['data'][0]['ownership']['state']);
        // Losing current owner eligibility does not rewrite this accepted historical association.
    }

    public function test_closed_me_requires_matching_retained_history_and_rejects_damaged_audit_until_restored(): void
    {
        $closed = $this->owned(); $closed->updateQuietly(['status' => 'closed', 'closed_at' => now()]);
        $raw = $this->incident(['status' => 'closed', 'closed_at' => now(), 'incident_owner_user_id' => $this->lead->id, 'owner_revision' => 1]);
        $this->allow($this->lead, 'incidents.owner.hold', false);
        $this->assertSame([$closed->id], $this->ids($this->read(['owner' => 'me', 'tab' => 'closed'], $this->lead)));
        $change = $closed->ownerChanges()->sole(); $audit = DB::table('audit_logs')->where('id', $change->audit_log_id)->first();
        DB::table('audit_logs')->where('id', $audit->id)->update(['meta' => '{}']);
        $this->assertSame([], $this->ids($this->read(['owner' => 'me', 'tab' => 'closed'], $this->lead)));
        DB::table('audit_logs')->where('id', $audit->id)->update(['meta' => $audit->meta]);
        $this->assertSame([$closed->id], $this->ids($this->read(['owner' => 'me', 'tab' => 'closed'], $this->lead)));
        $this->assertNotContains($raw->id, $this->ids($this->read(['owner' => 'me', 'tab' => 'closed'], $this->lead)));
    }

    public function test_current_viewer_grants_restore_when_caller_retains_loaded_false_permissions(): void
    {
        $root = $this->incident();
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $denied = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->denial(fn () => $this->read([], $denied));
        $this->allow($denied, 'incidents.viewAny'); $this->allow($denied, 'incidents.templates.manage');
        $this->assertFalse($denied->canDo('incidents.viewAny'));
        $dto = $this->read([], $denied);
        $this->assertSame([$root->id], $this->ids($dto)); $this->assertTrue($dto['can']['templatesManage']);
    }

    public function test_actual_register_route_restores_loaded_false_read_grant_without_suppressing_middleware(): void
    {
        $root = $this->incident();
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $denied = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->actingAs($denied)->get('/incidents', ['X-Inertia' => 'true'])->assertForbidden();
        $this->allow($denied, 'incidents.viewAny');
        $this->assertFalse($denied->canDo('incidents.viewAny'));
        $this->get('/incidents', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.viewer_id', $denied->id)->assertJsonPath('props.rows.data.0.id', $root->id);
        $this->allow($denied, 'incidents.viewAny', false);
        $this->get('/incidents', ['X-Inertia' => 'true'])->assertForbidden();
    }

    public function test_source_rebind_after_qualification_is_denied_at_final_count_and_body(): void
    {
        $root = $this->incident(); $foreign = Site::factory()->create(); $race = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($root, $foreign, &$race): void {
            if (! $race && str_contains($sql, 'count(*)') && str_contains($sql, 'client_incidents') && str_contains($sql, 'owner_revision')) {
                $race = true; DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $foreign->id]);
            }
        });
        $dto = $this->read(['owner' => 'needs_owner']);
        $this->assertTrue($race); $this->assertSame(0, $dto['tabCounts']['all']); $this->assertSame([], $this->ids($dto));
        DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $this->site->id]);
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'needs_owner'])));
    }

    public function test_legacy_near_miss_report_entry_and_all_previous_envelopes_remain(): void
    {
        $this->allow($this->manager, 'incidents.create'); $root = $this->incident(['type' => 'near_miss']);
        $dto = $this->read(['type' => 'near_miss', 'report' => 'near_miss', 'report_client_id' => $root->client_id]);
        $this->assertSame('near_misses', $dto['tab']); $this->assertSame('near_miss', $dto['report']);
        $this->assertSame($root->client_id, $dto['reportPrefill']['client_id']);
        foreach (['rows', 'rowsKind', 'tabCounts', 'hero', 'nearMissInsights', 'sites', 'clients', 'reportClients', 'reportStaff', 'can', 'detail'] as $key) {
            $this->assertArrayHasKey($key, $dto);
        }
    }

    public function test_detail_consumer_has_current_actor_and_reads_and_request_resolver_is_restored(): void
    {
        $root = $this->incident(); $request = Request::create('/incidents', 'GET', ['incident' => $root->id]);
        $original = fn () => $this->manager; $request->setUserResolver($original); $retainedReads = null;
        $dto = app(IncidentRegisterReadService::class)->index($request, function ($working, $id, $reads) use (&$retainedReads): array {
            $retainedReads = $reads; $reads->assertActive();
            $source = app(\App\Services\Incidents\IncidentAccessService::class)->apply(ClientIncident::query(), $working->user(), $reads)->whereKey($id)->firstOrFail();
            return ['id' => (int) $source->id, 'viewer_id' => (int) $working->user()->id];
        });
        $this->assertSame($original, $request->getUserResolver()); $this->assertSame($root->id, $dto['detail']['id']);
        $this->expectException(\LogicException::class); $retainedReads->assertActive();
    }

    public function test_existing_detail_consumer_cannot_escape_a_raw_model_or_builder(): void
    {
        $root = $this->incident();
        $this->expectException(\LogicException::class);
        $this->read(['incident' => $root->id], consumer: fn () => ['source' => $root]);
    }
    public function test_real_http_owner_counts_followups_and_eager_partial_props_require_controller_successor(): void
    {
        $root = $this->incident(); $followup = $this->followup($root); $this->actingAs($this->manager);
        $response = $this->partial(['owner' => 'needs_owner', 'tab' => 'followups'])->assertOk();
        $response->assertJsonPath('props.viewer_id', $this->manager->id)->assertJsonPath('props.filters.owner', 'needs_owner')
            ->assertJsonPath('props.rows.data.0.id', $followup->id)->assertJsonPath('props.tabCounts.all', 1);
        foreach (['rows', 'tabCounts', 'hero', 'nearMissInsights', 'sites', 'clients', 'reportClients', 'reportStaff', 'can', 'detail'] as $key) {
            $response->assertJsonStructure(['props' => [$key]]);
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_real_http_warmed_true_withdrawal_restore_and_site_move_replaces_old_partial_rows(): void
    {
        $root = $this->incident(); $this->actingAs($this->manager); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $this->partial()->assertForbidden(); $this->allow($this->manager, 'incidents.viewAny');
        $this->partial()->assertOk()->assertJsonPath('props.rows.data.0.id', $root->id);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->partial()->assertOk()->assertJsonPath('props.rows.data', [])->assertJsonPath('props.tabCounts.all', 0);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->partial()->assertOk()->assertJsonPath('props.rows.data.0.id', $root->id);
    }

    public function test_real_http_private_foreign_parents_never_enter_followup_counts_or_names(): void
    {
        $visible = $this->incident(); $this->followup($visible);
        $foreign = $this->incident(['site_id' => Site::factory()->create()->id]); $private = $this->incident(['is_hr_confidential' => true]);
        $this->followup($foreign, 'FOREIGN-PRIVATE-NOTES'); $this->followup($private, 'HR-PRIVATE-NOTES');
        $response = $this->actingAs($this->manager)->get('/incidents?owner=needs_owner&tab=followups', ['X-Inertia' => 'true'])->assertOk();
        $response->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.hero.attention.followups.value', 1);
        $this->assertStringNotContainsString('FOREIGN-PRIVATE-NOTES', $response->getContent()); $this->assertStringNotContainsString('HR-PRIVATE-NOTES', $response->getContent());
    }

    public function test_current_assigned_client_withdrawal_removes_me_and_creates_replacement_work_then_restores(): void
    {
        $root = $this->owned(); $client = $root->client;
        $client->supportWorkers()->syncWithoutDetaching([$this->lead->id]);
        $this->allow($this->lead, 'incidents.viewAny', false); $this->allow($this->lead, 'incidents.viewAssigned');
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'me'], $this->lead)));
        $client->supportWorkers()->detach($this->lead->id);
        $this->assertSame([], $this->ids($this->read(['owner' => 'me'], $this->lead)));
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'needs_owner'])));
        $client->supportWorkers()->attach($this->lead->id);
        $this->assertSame([$root->id], $this->ids($this->read(['owner' => 'me'], $this->lead)));
        $this->assertSame([], $this->ids($this->read(['owner' => 'needs_owner'])));
    }

    public function test_real_http_partial_actor_switch_binds_new_viewer_and_replaces_the_old_me_rows(): void
    {
        $first = $this->owned(); $second = $this->incident();
        app(IncidentOwnershipService::class)->assign($this->manager, $second, $this->cover->id, 0, (string) Str::uuid(), null);
        $this->actingAs($this->lead);
        $this->partial(['owner' => 'me'])->assertOk()->assertJsonPath('props.viewer_id', $this->lead->id)
            ->assertJsonPath('props.rows.data.0.id', $first->id);
        $this->actingAs($this->cover);
        $response = $this->partial(['owner' => 'me'])->assertOk()->assertJsonPath('props.viewer_id', $this->cover->id)
            ->assertJsonPath('props.rows.data.0.id', $second->id)->assertJsonPath('props.rows.total', 1);
        $this->assertNotContains($first->id, array_column($response->json('props.rows.data'), 'id'));
    }
    public function test_real_http_unknown_owner_is_validation_error_not_unscoped_lens(): void
    {
        $this->actingAs($this->manager)->getJson('/incidents?owner=unknown')->assertUnprocessable()->assertJsonValidationErrors('owner');
        $this->getJson('/incidents?owner%5B%5D=me')->assertUnprocessable()->assertJsonValidationErrors('owner');
    }
}
