<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\IncidentTriageDelivery;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Incidents\IncidentRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentRoutingCommandTest extends IncidentRoutingTestCase
{
    public function test_create_only_reporter_gets_configured_owner_without_manual_assignment_grant(): void
    {
        $this->settings(); $root = $this->incident(); $reported = $root->reported_by;
        $result = $this->routed($root);
        $this->assertSame('assigned', $result['state']); $this->assertTrue($result['owner_assigned']); $this->assertSame('unconfirmed', $result['delivery']);
        $this->assertFalse($this->reporter->fresh()->canDo('incidents.owner.assign'));
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id); $this->assertSame($reported, $root->fresh()->reported_by);
        $change = IncidentOwnerChange::where('incident_id', $root->id)->sole();
        $this->assertSame('configured_site_lead', $change->source); $this->assertSame('1', $change->routing_version);
        $this->assertSame($this->reporter->id, $change->actor_user_id); $this->assertNotNull($change->audit_log_id);
        $this->assertArrayNotHasKey('owner', $result); $this->assertStringNotContainsString('Private synthetic', json_encode($result));
    }

    public function test_effective_cover_then_lead_uses_half_open_actual_assignment_intervals(): void
    {
        $config = $this->config(); $config['routes'][0] += [];
        $config['routes'][0]['cover_user_id'] = $this->cover->id; $config['routes'][0]['cover_from'] = '2026-10-12T03:00:00Z'; $config['routes'][0]['cover_until'] = '2026-10-12T04:00:00Z';
        $this->settings($config); $covered = $this->incident(); $this->routed($covered); $this->assertSame($this->cover->id, $covered->fresh()->incident_owner_user_id);
        $this->travelTo(now()->setTime(4, 0)); $normal = $this->incident(); $this->routed($normal); $this->assertSame($this->lead->id, $normal->fresh()->incident_owner_user_id);
    }

    public function test_future_expired_and_no_ordinary_route_for_restricted_incident_remain_needs_owner(): void
    {
        $config = $this->config(); $config['routes'][0]['effective_from'] = '2027-01-01T00:00:00Z'; $this->settings($config);
        $root = $this->incident(); $this->assertSame('needs_owner', $this->routed($root)['state']); $this->assertNull($root->fresh()->incident_owner_user_id);
        $restricted = $this->incident(['type' => 'safeguarding']); $this->assertSame('needs_owner', $this->routed($restricted)['state']);
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_restricted_route_requires_independent_current_specialist_and_conflict_excludes_default(): void
    {
        $this->allow($this->cover, 'incidents.owner.holdRestricted'); $this->allow($this->cover, 'hr.cases.view');
        $config = $this->config(); $config['routes'][] = [...$config['routes'][0], 'scope' => 'restricted', 'lead_user_id' => $this->cover->id]; $this->settings($config);
        $root = $this->incident(['is_hr_confidential' => true]);
        DB::table('incident_owner_exclusions')->insert(['incident_id' => $root->id, 'user_id' => $this->cover->id, 'recorded_by' => $this->manager->id, 'reason_code' => 'declared_conflict', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('needs_owner', $this->routed($root)['state']); $this->assertNull($root->fresh()->incident_owner_user_id);
        $this->assertSame(2, IncidentTriageDelivery::count());
    }

    public function test_soft_deleted_safeguarding_concern_keeps_specialist_rule_and_alleged_perpetrator_exclusion(): void
    {
        $this->allow($this->lead, 'incidents.owner.holdRestricted'); $config = $this->config(); $config['routes'][0]['scope'] = 'restricted'; $this->settings($config);
        $root = $this->incident(); $concern = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'related_incident_id' => $root->id, 'site_id' => $this->site->id, 'alleged_perpetrator_type' => User::class, 'alleged_perpetrator_id' => $this->lead->id,
        ])); $concern->delete();
        $this->assertSame('needs_owner', $this->routed($root)['state']); $this->assertNull($root->fresh()->incident_owner_user_id);
    }

    public function test_missing_or_invalid_configuration_never_discards_saved_report_or_invents_owner(): void
    {
        $root = $this->incident(); $result = $this->routed($root); $this->assertSame('setup_required', $result['state']);
        $this->assertSame('configuration_missing', $result['reason_code']); $this->assertSame('submitted', $root->fresh()->status);
        $this->assertNull($root->fresh()->incident_owner_user_id); $this->assertSame(0, IncidentTriageDelivery::count());
    }

    public function test_warmed_lead_site_role_staff_or_account_withdrawal_yields_monitored_exception(): void
    {
        $this->settings(); $this->lead->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->allow($this->lead, 'incidents.owner.hold', false); $first = $this->incident(); $this->assertSame('needs_owner', $this->routed($first)['state']);
        $this->allow($this->lead, 'incidents.owner.hold'); $this->lead->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $second = $this->incident(); $this->assertSame('needs_owner', $this->routed($second)['state']);
        $this->lead->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'is_active' => false]); $third = $this->incident(); $this->assertSame('needs_owner', $this->routed($third)['state']);
        $this->lead->hrEmployeeProfile()->update(['is_active' => true]); $this->lead->update(['approved_at' => null]); $fourth = $this->incident(); $this->assertSame('needs_owner', $this->routed($fourth)['state']);
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_duplicate_stage_consume_and_lost_response_replay_have_one_assignment_audit_and_decision(): void
    {
        $this->settings(); $root = $this->incident(); $service = app(IncidentRoutingService::class);
        $id = $service->stageForSubmitted($root->id, $this->reporter); $this->assertSame($id, $service->stageForSubmitted($root->id, $this->reporter));
        $first = $service->consume($id); $this->assertSame($first, $service->consume($id));
        $this->assertSame(1, IncidentOwnerChange::count()); $this->assertSame(1, IncidentRoutingDecision::count());
        $this->assertSame(1, AuditLog::where('action', 'incidents.routing.evaluated')->count());
    }

    public function test_manual_owner_wins_and_new_configuration_never_automatically_overrides_it(): void
    {
        $this->settings(); $root = $this->incident(); $service = app(IncidentRoutingService::class); $id = $service->stageForSubmitted($root->id, $this->reporter);
        $change = app(IncidentOwnershipService::class)->assign($this->manager, $root, $this->cover->id, 0, (string) Str::uuid(), null);
        $this->assertSame('superseded', $service->consume($id)['state']);
        $this->assertSame($this->cover->id, $root->fresh()->incident_owner_user_id); $this->assertSame(1, IncidentOwnerChange::count());
        $config = $this->config(); $config['routes'][0]['lead_user_id'] = $this->triage->id; $this->settings($config, 1); $service->sweep();
        $this->assertSame($this->cover->id, $root->fresh()->incident_owner_user_id); $this->assertSame($change->id, IncidentOwnerChange::sole()->id);
    }

    public function test_ineligible_existing_owner_is_retained_with_replacement_queue_and_no_automatic_transfer(): void
    {
        $this->settings(); $root = $this->incident(); app(IncidentOwnershipService::class)->assign($this->manager, $root, $this->cover->id, 0, (string) Str::uuid(), null);
        $this->cover->update(['approved_at' => null]); $service = app(IncidentRoutingService::class);
        $this->assertNull($service->stageForSubmitted($root->id)); $id = $service->stageForReplacement($root->id); $result = $service->consume($id);
        $this->assertSame('replacement_needed', $result['state']); $this->assertSame($this->cover->id, $root->fresh()->incident_owner_user_id);
        $this->assertSame(1, IncidentOwnerChange::count()); $this->assertCount(1, $service->queueForViewer($this->manager)['items']);
    }

    public function test_draft_closed_and_unowned_legacy_sweep_are_not_routed_or_retroactively_assigned(): void
    {
        $this->settings(); $service = app(IncidentRoutingService::class);
        foreach (['draft', 'closed'] as $status) { $root = $this->incident(['status' => $status]); $this->assertNull($service->stageForSubmitted($root->id, $this->reporter)); }
        $legacy = $this->incident(['submitted_at' => null]); $service->sweep();
        $this->assertNull($legacy->fresh()->incident_owner_user_id); $this->assertSame(0, IncidentRoutingIntent::count());
    }

    public function test_source_rebind_after_staging_requires_review_and_never_routes_to_current_persons_house(): void
    {
        $this->settings(); $root = $this->incident(); $service = app(IncidentRoutingService::class); $id = $service->stageForSubmitted($root->id, $this->reporter);
        DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => Site::factory()->create()->id]);
        $this->assertSame('source_context_changed', $service->consume($id)['reason_code']); $this->assertNull($root->fresh()->incident_owner_user_id);
    }

    public function test_routing_decision_or_owner_save_veto_retains_report_and_retryable_original_intent(): void
    {
        $this->settings();
        foreach ([ClientIncident::class, IncidentOwnerChange::class, IncidentRoutingDecision::class] as $class) {
            $root = $this->incident(); $service = app(IncidentRoutingService::class); $id = $service->stageForSubmitted($root->id, $this->reporter);
            $this->veto($class, fn () => $service->consume($id)); $this->assertSame('error', IncidentRoutingIntent::findOrFail($id)->state);
            $this->assertNull($root->fresh()->incident_owner_user_id); $this->assertSame('submitted', $root->fresh()->status);
            $this->assertSame('assigned', $service->consume($id)['state']);
        }
    }

    public function test_missing_or_tampered_decision_acceptance_is_not_an_exact_replay_receipt(): void
    {
        $this->settings(); $root = $this->incident(); $first = $this->routed($root); $decision = IncidentRoutingDecision::findOrFail($first['routing_decision_id']);
        DB::table('audit_logs')->where('id', $decision->audit_log_id)->update(['meta' => json_encode(['intent_id' => $decision->intent_id, 'attempt' => 1, 'decision_digest' => str_repeat('0', 64)])]);
        $this->validation(fn () => app(IncidentRoutingService::class)->consume($first['routing_intent_id']), 'routing');
    }

    public function test_routing_intent_and_decision_are_not_mutable_history(): void
    {
        $this->settings(); $result = $this->routed($this->incident());
        $decision = IncidentRoutingDecision::findOrFail($result['routing_decision_id']); $this->expectException(\LogicException::class); $decision->update(['state' => 'needs_owner']);
    }
    public function test_eligible_lead_without_current_monitored_triage_setup_does_not_enable_autoassignment(): void
    {
        $this->settings($this->config(['triage' => null])); $root = $this->incident();
        $result = $this->routed($root); $this->assertSame('setup_required', $result['state']);
        $this->assertNull($root->fresh()->incident_owner_user_id); $this->assertSame(0, IncidentOwnerChange::count());
        $this->assertSame('submitted', $root->fresh()->status);
    }
    public function test_submission_staging_reads_no_configuration_or_recipient_authorization_and_preserves_named_owner(): void
    {
        $this->settings(); $root = $this->incident(); $service = app(IncidentRoutingService::class);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $id = $service->stageForSubmitted($root->id, $this->reporter);
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        $this->assertNotNull($id);
        foreach ($queries as $query) {
            foreach (['app_settings', 'health_safety_setting_changes', 'permissions', 'hr_employee_profiles', 'roles', 'incident_owner_changes', 'incident_owner_exclusions'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($query['query']));
            }
        }
        foreach ([['incident_owner_user_id' => $this->lead->id], ['owner_revision' => 1], ['incident_owner_user_id' => $this->cover->id, 'owner_revision' => 1]] as $identity) {
            $named = $this->incident($identity); $this->cover->update(['approved_at' => null]);
            DB::enableQueryLog(); DB::flushQueryLog();
            try { $this->assertNull($service->stageForSubmitted($named->id, $this->reporter)); $queries = DB::getQueryLog(); }
            finally { DB::disableQueryLog(); }
            foreach ($queries as $query) { $this->assertStringNotContainsString('users', strtolower($query['query'])); }
            $this->assertSame($identity['incident_owner_user_id'] ?? null, $named->fresh()->incident_owner_user_id);
        }
        $this->assertSame(1, IncidentRoutingIntent::count());
    }

    public function test_replacement_sweep_retains_unknown_owner_or_missing_owner_with_prior_revision(): void
    {
        $this->settings(); $service = app(IncidentRoutingService::class);
        $unknown = $this->incident(['incident_owner_user_id' => $this->lead->id]);
        $missing = $this->incident(['owner_revision' => 1]); $legacyUnowned = $this->incident(['submitted_at' => null]);
        $sweep = $service->sweep(); $this->assertSame(2, $sweep['attempted']); $this->assertSame(0, $sweep['failed']);
        foreach ([$unknown, $missing] as $root) {
            $intent = IncidentRoutingIntent::where('incident_id', $root->id)->sole();
            $this->assertSame('replacement', $intent->kind); $this->assertSame('replacement_needed', $intent->state);
        }
        $this->assertSame($this->lead->id, $unknown->fresh()->incident_owner_user_id); $this->assertNull($missing->fresh()->incident_owner_user_id);
        $this->assertSame(0, IncidentOwnerChange::count()); $this->assertFalse(IncidentRoutingIntent::where('incident_id', $legacyUnowned->id)->exists());
        $this->assertCount(2, $service->queueForViewer($this->manager)['items']);
    }

    public function test_damaged_assignment_acceptance_stages_replacement_even_when_owner_remains_eligible(): void
    {
        $this->settings(); $root = $this->incident(); $owners = app(IncidentOwnershipService::class);
        $change = $owners->assign($this->manager, $root, $this->lead->id, 0, (string) Str::uuid(), null);
        $originalMeta = AuditLog::findOrFail($change->audit_log_id)->meta;
        DB::table('audit_logs')->where('id', $change->audit_log_id)->update(['meta' => json_encode(['damaged' => true])]);
        $service = app(IncidentRoutingService::class); $this->assertNull($service->stageForSubmitted($root->id));
        $id = $service->stageForReplacement($root->id);
        $this->assertSame('replacement_needed', $service->consume($id)['state']);
        $this->assertCount(1, $service->queueForViewer($this->manager)['items']);
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id); $this->assertSame(1, IncidentOwnerChange::count());
        DB::table('audit_logs')->where('id', $change->audit_log_id)->update(['meta' => json_encode($originalMeta)]);
        $this->assertNull($service->stageForReplacement($root->id)); $this->assertCount(0, $service->queueForViewer($this->manager)['items']);
    }
}
