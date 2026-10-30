<?php

namespace Tests\Feature\Incidents;

use App\Models\Site;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Incidents\IncidentRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentRoutingPrivacyTest extends IncidentRoutingTestCase
{
    public function test_settings_permission_never_admits_incident_preview_queue_or_body(): void
    {
        $this->settings(); $root = $this->incident(); $settingOnly = $this->staff(['healthSafety.settings.manage']);
        $this->denial(fn () => app(IncidentRoutingService::class)->preview($settingOnly, $root->id), 404);
        $this->denial(fn () => app(IncidentRoutingService::class)->queueForViewer($settingOnly));
    }

    public function test_owner_read_and_picker_recheck_warmed_current_permission_and_site(): void
    {
        $root = $this->incident(); $service = app(IncidentOwnershipService::class); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->assertNotEmpty($service->candidates($this->manager, $root)); $this->allow($this->manager, 'incidents.viewAny', false);
        $this->denial(fn () => $service->candidates($this->manager, $root), 404); $this->denial(fn () => $service->summary($this->manager, $root), 404);
        $this->allow($this->manager, 'incidents.viewAny'); $this->manager->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->denial(fn () => $service->present($this->manager, $root), 404);
    }

    public function test_foreign_incident_preview_and_retry_deny_without_private_root_body_sql(): void
    {
        $this->settings(); $root = $this->incident(['site_id' => Site::factory()->create()->id]); $id = app(IncidentRoutingService::class)->stageForSubmitted($root->id, $this->reporter);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->denial(fn () => app(IncidentRoutingService::class)->retryForViewer($this->manager, $id), 404); $queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        $this->assertNull($root->fresh()->incident_owner_user_id);
        foreach ($queries as $query) { $this->assertStringNotContainsString('description', strtolower($query['query'])); }
    }

    public function test_assignment_receipt_fields_are_retained_and_old_payload_cannot_reuse_uuid(): void
    {
        $root = $this->incident(); $uuid = (string) Str::uuid(); $owners = app(IncidentOwnershipService::class);
        $change = $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null);
        $dto = $owners->present($this->manager, $root); $this->assertSame($uuid, $dto['history'][0]['request_uuid']); $this->assertSame($this->lead->id, $dto['history'][0]['owner_user_id']);
        $this->validation(fn () => $owners->assign($this->manager, $root, $this->cover->id, 0, $uuid, null), 'request_uuid');
        $this->assertSame($change->id, $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null)->id);
    }

    public function test_manual_request_replay_requires_current_actor_and_rejects_missing_governed_audit(): void
    {
        $root = $this->incident(); $uuid = (string) Str::uuid(); $owners = app(IncidentOwnershipService::class);
        $change = $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null);
        DB::table('incident_owner_changes')->where('id', $change->id)->update(['audit_log_id' => null]);
        $this->validation(fn () => $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null), 'ownership');
        $this->allow($this->manager, 'incidents.owner.assign', false); $this->denial(fn () => $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null));
    }

    public function test_private_confidential_route_is_not_available_to_ordinary_incident_reader(): void
    {
        $this->settings(); $root = $this->incident(['is_hr_confidential' => true]); $this->routed($root);
        $this->denial(fn () => app(IncidentOwnershipService::class)->present($this->manager, $root), 404);
        $this->assertSame([], app(IncidentRoutingService::class)->queueForViewer($this->manager)['items']);
        $this->allow($this->manager, 'hr.cases.view'); $this->assertCount(1, app(IncidentRoutingService::class)->queueForViewer($this->manager)['items']);
    }

    public function test_deadline_and_no_recipient_names_are_available_only_in_current_source_queue(): void
    {
        $this->settings($this->config(['routes' => []])); $result = $this->routed($this->incident());
        $this->assertArrayNotHasKey('escalate_at', $result); $this->assertArrayNotHasKey('triage_user_id', $result);
        $item = app(IncidentRoutingService::class)->queueForViewer($this->manager)['items'][0];
        $this->assertSame(now()->addMinutes(47)->toIso8601String(), $item['escalate_at']); $this->assertArrayNotHasKey('description', $item);
        $this->assertArrayNotHasKey('triage_user_id', $item);
    }
    public function test_owner_filter_applies_all_parent_source_predicates_before_counts_and_keeps_replacement_distinct(): void
    {
        $owners = app(IncidentOwnershipService::class); $mine = $this->incident(); $this->allow($this->manager, 'incidents.owner.hold');
        $owners->assign($this->manager, $mine, $this->manager->id, 0, (string) Str::uuid(), null);
        $replacement = $this->incident(); $owners->assign($this->manager, $replacement, $this->lead->id, 0, (string) Str::uuid(), null);
        $this->lead->hrEmployeeProfile()->update(['is_active' => false]); $neverAssigned = $this->incident(['type' => 'behaviour']);
        $foreign = $this->incident(['site_id' => Site::factory()->create()->id]);
        $actual = $owners->withViewer($this->manager, function ($current, $reads) use ($owners): array {
            $base = \App\Models\ClientIncident::query()->where('type', 'injury');
            return ['needs_owner' => $owners->applyOwnerFilter(clone $base, $current, 'needs_owner', $reads)->pluck('id')->all(),
                'me_count' => $owners->applyOwnerFilter(clone $base, $current, 'me', $reads)->count()];
        });
        $this->assertSame([$replacement->id], $actual['needs_owner']); $this->assertSame(1, $actual['me_count']);
        $this->assertSame('replacement_needed', $owners->summary($this->manager, $replacement)['state']);
        $this->assertSame('unassigned', $owners->summary($this->manager, $neverAssigned)['state']);
        $this->assertNotContains($foreign->id, $actual['needs_owner']);
    }

    public function test_null_owner_with_prior_revision_and_unknown_nonnull_owner_remain_replacement_work(): void
    {
        $root = $this->incident(['owner_revision' => 1]); $unknown = $this->incident(['incident_owner_user_id' => $this->lead->id]);
        foreach ([$root, $unknown] as $row) { $this->assertSame('replacement_needed', app(IncidentOwnershipService::class)->summary($this->manager, $row)['state']); }
    }

    public function test_current_conflict_withdraws_manual_replay_without_overriding_retained_history(): void
    {
        $root = $this->incident(); $uuid = (string) Str::uuid(); $owners = app(IncidentOwnershipService::class);
        $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null);
        DB::table('incident_owner_exclusions')->insert(['incident_id' => $root->id, 'user_id' => $this->manager->id, 'recorded_by' => $this->manager->id,
            'reason_code' => 'declared_conflict', 'created_at' => now(), 'updated_at' => now()]);
        $this->denial(fn () => $owners->assign($this->manager, $root, $this->lead->id, 0, $uuid, null));
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
    }
    public function test_same_session_owner_revision_status_or_source_rebind_before_final_count_denies_stale_filter_row_and_restores(): void
    {
        $owners = app(IncidentOwnershipService::class); $root = $this->incident(); $healthy = $this->incident();
        $original = $root->getRawOriginal();
        $this->allow($this->manager, 'incidents.owner.hold');
        foreach ([['incident_owner_user_id' => $this->manager->id, 'owner_revision' => 1], ['status' => 'closed'],
            ['site_id' => Site::factory()->create()->id], ['type' => 'INJURY']] as $change) {
            $actual = $owners->withViewer($this->manager, function ($current, $reads) use ($owners, $root, $change): array {
                $base = \App\Models\ClientIncident::query()->where('type', 'injury');
                $qualified = $owners->applyOwnerFilter($base, $current, 'needs_owner', $reads);
                DB::table('client_incidents')->where('id', $root->id)->update($change);
                return ['ids' => $qualified->pluck('id')->all(), 'count' => (clone $qualified)->count()];
            });
            $this->assertSame([$healthy->id], $actual['ids']); $this->assertSame(1, $actual['count']);
            DB::table('client_incidents')->where('id', $root->id)->update(array_intersect_key($original, $change));
            $restored = $owners->withViewer($this->manager, fn ($current, $reads) => $owners->applyOwnerFilter(\App\Models\ClientIncident::query()->where('type', 'injury'), $current, 'needs_owner', $reads)->count());
            $this->assertSame(2, $restored);
        }
    }
    public function test_retry_current_write_withdrawal_after_preflight_cannot_commit_or_mutate_the_report(): void
    {
        $this->settings(); $root = $this->incident(); $service = app(IncidentRoutingService::class); $id = $service->stageForSubmitted($root->id, $this->reporter);
        $connection = DB::connection(); $dispatcher = $connection->getEventDispatcher(); $connection->setEventDispatcher(clone $dispatcher); $changed = false;
        DB::listen(function ($event) use (&$changed): void {
            if (! $changed && in_array(\App\Services\Incidents\IncidentRoutingSettings::KEY, $event->bindings, true) && str_starts_with(strtolower($event->sql), 'select')) {
                $changed = true; $this->allow($this->manager, 'incidents.owner.assign', false);
            }
        });
        try { $this->denial(fn () => $service->consume($id, $this->manager)); }
        finally { $connection->setEventDispatcher($dispatcher); }
        $this->assertTrue($changed); $this->assertNull($root->fresh()->incident_owner_user_id);
        $this->assertSame('pending', \App\Models\IncidentRoutingIntent::findOrFail($id)->state);
        $this->allow($this->manager, 'incidents.owner.assign'); $this->assertSame('assigned', $service->consume($id, $this->manager)['state']);
    }
    public function test_exact_me_relation_survives_healthy_neighbor_and_rejects_same_session_owner_transfer_before_count(): void
    {
        $owners = app(IncidentOwnershipService::class); $this->allow($this->manager, 'incidents.owner.hold');
        $root = $this->incident(); $healthy = $this->incident();
        foreach ([$root, $healthy] as $row) { $owners->assign($this->manager, $row, $this->manager->id, 0, (string) Str::uuid(), null); }
        $ids = $owners->withViewer($this->manager, function ($current, $reads) use ($owners, $root): array {
            $qualified = $owners->applyOwnerFilter(\App\Models\ClientIncident::query()->select('client_incidents.*'), $current, 'me', $reads);
            DB::table('client_incidents')->where('id', $root->id)->update(['incident_owner_user_id' => $this->lead->id, 'owner_revision' => 2]);
            return $qualified->pluck('id')->all();
        });
        $this->assertSame([$healthy->id], $ids);
        DB::table('client_incidents')->where('id', $root->id)->update(['incident_owner_user_id' => $this->manager->id, 'owner_revision' => 1]);
        $count = $owners->withViewer($this->manager, fn ($current, $reads) => $owners->applyOwnerFilter(\App\Models\ClientIncident::query(), $current, 'me', $reads)->count());
        $this->assertSame(2, $count);
    }
}
