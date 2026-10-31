<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentIndependentHandlingDeclaration as Declaration;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentOwnerDelivery;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\IncidentTriageDelivery;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use App\Services\Incidents\IncidentOwnerEligibility;
use App\Services\Incidents\IncidentOwnerNotifications;
use App\Services\Incidents\IncidentOwnershipService as Owners;
use App\Services\Incidents\IncidentRoutingService as Routing;
use App\Services\Incidents\IncidentRoutingSettings as Settings;
use App\Services\Incidents\IncidentTriageDeliveryService as Triage;
use App\Services\Incidents\IncidentTypedNotificationReadService as Leaf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Real retained intake/ownership/routing and database notices. Native MySQL behaviour remains UNRUN. */
final class IncidentTypedNotificationPrivacyTest extends IncidentIndependenceTestCase
{
    private function owned(): array
    {
        $root = $this->incident(['client_id' => $this->client->id, 'shift_id' => null, 'source' => 'manual',
            'report_request_uuid' => (string) Str::uuid()]);
        $change = app(Owners::class)->assign($this->manager, $root, $this->lead->id, 0, (string) Str::uuid(), null);
        $delivery = IncidentOwnerDelivery::where('change_id', $change->id)->where('recipient_role', 'incoming')->sole();
        return [$root->fresh(), $change, $delivery];
    }

    private function pendingTriage(): array
    {
        $this->settings($this->config(['routes' => []]));
        $root = $this->incident(['client_id' => $this->client->id, 'shift_id' => null, 'source' => 'manual',
            'report_request_uuid' => (string) Str::uuid()]);
        $result = $this->routed($root);
        $decision = IncidentRoutingDecision::findOrFail($result['routing_decision_id']);
        $delivery = IncidentTriageDelivery::where('decision_id', $decision->id)->where('recipient_role', 'triage')->sole();
        return [$root->fresh(), $decision, $delivery];
    }

    private function declare(ClientIncident $root, User $involved, int $policyRevision = 0): array
    {
        return $this->acceptForExisting($root, $this->command(['report_request_uuid' => $root->report_request_uuid,
            'expected_policy_revision' => $policyRevision, 'independent_handling' => ['requested' => true,
                'reason_code' => 'other_staff_involved', 'identification' => 'named', 'involved_user_ids' => [(int) $involved->id]]]));
    }

    private function currentSource(User $actor, ClientIncident $root, callable $consume, string $purpose = 'notification'): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $root, $consume, $purpose) {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, Settings::PERMISSIONS);
            $source = app(Leaf::class)->source($current, (int) $root->id, $reads, $purpose);
            return $consume($source, $reads, $current);
        }));
    }

    private function refusal(callable $consume): void
    {
        try { $consume(); $this->fail('Expected exact current source or typed evidence refusal.'); }
        catch (ModelNotFoundException|LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        catch (ValidationException $error) { $this->assertNotSame([], $error->errors()); }
        catch (HttpExceptionInterface $error) { $this->assertContains($error->getStatusCode(), [403, 404]); }
    }

    /** Hook the real first full typed SELECT, never fake a current source/proof provider. */
    private function mutateAtBody(string $table, callable $mutate, callable $consume, string $extra = 'notification_fp'): void
    {
        $state = (object) ['armed' => true, 'fired' => false, 'hydrated_after_mutation' => 0];
        $class = match ($table) {
            'incident_owner_changes' => IncidentOwnerChange::class, 'incident_routing_intents' => IncidentRoutingIntent::class,
            'incident_routing_decisions' => IncidentRoutingDecision::class, 'health_safety_setting_changes' => HealthSafetySettingChange::class,
            'audit_logs' => AuditLog::class,
        };
        $privateColumns = match ($table) {
            'incident_owner_changes' => ['owner_name', 'reason'], 'incident_routing_intents' => ['trigger_actor_name', 'source_tuple'],
            'incident_routing_decisions' => ['configuration'], 'health_safety_setting_changes' => ['after_value'],
            'audit_logs' => ['meta'],
        };
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        $class::retrieved(function ($row) use ($state, $privateColumns): void {
            // Actual canonical SELECT * bodies have no notification_fp alias; their real private columns still count.
            if ($state->fired && array_intersect($privateColumns, array_keys($row->getAttributes())) !== []) { $state->hydrated_after_mutation++; }
        });
        $from = 'from '.DB::connection()->getQueryGrammar()->wrapTable($table);
        DB::connection()->beforeExecuting(function ($sql) use ($state, $from, $extra, $mutate): void {
            if (! $state->armed || ! str_starts_with(strtolower($sql), 'select ') || ! str_contains($sql, $from) || ! str_contains($sql, $extra)) { return; }
            $state->armed = false; $state->fired = true; $mutate();
        });
        try {
            $consume(); $this->assertTrue($state->fired, 'The actual source-to-body interleaving must occur.');
            $this->assertSame(0, $state->hydrated_after_mutation, 'Protected typed body must not hydrate after lost source admission.');
        } finally { $state->armed = false; Model::setEventDispatcher($dispatcher); }
    }

    private function noNotice(string $id): void
    {
        $this->assertSame(0, DB::table('notifications')->where('id', $id)->count());
    }

    public function test_owner_actual_database_notice_is_repeat_safe_and_contains_only_existing_generic_payload(): void
    {
        [$root, , $delivery] = $this->owned();
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        $notice = DB::table('notifications')->where('id', $delivery->id)->sole();
        $data = json_decode($notice->data, true);
        $this->assertSame('/incidents/'.$root->id, $data['url']);
        $this->assertSame((int) $root->id, (int) $data['entity_id']);
        $this->assertStringNotContainsString($root->description, $notice->data);
        $this->assertStringNotContainsString($this->reporter->name, $notice->data);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_triage_actual_database_notice_replays_under_exact_current_typed_evidence(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $first = app(Triage::class)->deliver($delivery->id);
        $this->assertTrue($first['confirmed']);
        $this->assertSame($first, app(Triage::class)->deliver($delivery->id));
        $row = DB::table('notifications')->where('id', $delivery->id)->sole();
        $this->assertStringNotContainsString($root->description, $row->data);
        $this->assertStringNotContainsString($this->reporter->name, $row->data);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_named_owner_notification_is_denied_before_change_name_or_reason_hydration_and_keeps_unresolved_work(): void
    {
        [$root, , $delivery] = $this->owned(); $this->declare($root, $this->lead);
        $count = 0; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentOwnerChange::retrieved(function ($row) use (&$count): void { if (array_key_exists('owner_name', $row->getAttributes())) { $count++; } });
        try { app(IncidentOwnerNotifications::class)->deliver($delivery->id); } finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $count); $this->noNotice($delivery->id);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('source_unavailable', $delivery->fresh()->failure_code);
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
    }

    public function test_named_triage_receiver_is_denied_before_intent_and_configuration_bodies(): void
    {
        [$root, , $delivery] = $this->pendingTriage(); $this->declare($root, $this->triage);
        $count = 0; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentRoutingIntent::retrieved(function ($row) use (&$count): void { if (array_key_exists('trigger_actor_name', $row->getAttributes())) { $count++; } });
        IncidentRoutingDecision::retrieved(function ($row) use (&$count): void { if (array_key_exists('configuration', $row->getAttributes())) { $count++; } });
        try { $result = app(Triage::class)->deliver($delivery->id); } finally { Model::setEventDispatcher($dispatcher); }
        $this->assertFalse($result['confirmed']); $this->assertSame(0, $count); $this->noNotice($delivery->id);
        $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_assignment_only_retains_notification_read_but_does_not_restore_named_owner_eligibility(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future explicit handling policy.']);
        [$root, $change, $delivery] = $this->owned(); $this->declare($root, $this->lead, 1);
        $this->currentSource($this->lead, $root, function ($source, $reads) use ($change): void {
            $this->assertTrue((clone $source)->exists());
            app(Owners::class)->assertAcceptance($change, $source, $reads);
        });
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status); $this->noNotice($delivery->id);
    }

    public function test_strong_original_policy_still_denies_outgoing_notice_after_closure_and_current_policy_change(): void
    {
        [$root, , $incoming] = $this->owned();
        $this->allow($this->cover, 'incidents.owner.holdRestricted');
        $replacement = app(Owners::class)->assign($this->manager, $root, $this->cover->id, 1, (string) Str::uuid(), 'Actual reassignment.');
        $outgoing = IncidentOwnerDelivery::where('change_id', $replacement->id)->where('recipient_role', 'outgoing')->sole();
        $this->declare($root->fresh(), $this->lead);
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Does not rewrite historical policy.']);
        DB::table('client_incidents')->where('id', $root->id)->update(['status' => 'closed']);
        app(IncidentOwnerNotifications::class)->deliver($outgoing->id);
        $this->noNotice($outgoing->id); $this->assertSame('failed', $outgoing->fresh()->status);
        $this->assertSame('pending', $incoming->fresh()->status);
    }

    public function test_damaged_declaration_withholds_only_affected_notice_and_exact_restoration_allows_retry(): void
    {
        [$root, , $delivery] = $this->pendingTriage(); $receipt = $this->declare($root, $this->lead);
        $declaration = Declaration::findOrFail($receipt['declaration_id']); $raw = $declaration->getRawOriginal('handling');
        DB::table($declaration->getTable())->where('id', $declaration->id)->update(['handling' => json_encode(['requested' => false])]);
        $this->assertFalse(app(Triage::class)->deliver($delivery->id)['confirmed']); $this->noNotice($delivery->id);
        DB::table($declaration->getTable())->where('id', $declaration->id)->update(['handling' => $raw]);
        $this->assertTrue(app(Triage::class)->deliver($delivery->id)['confirmed']);
    }

    public function test_current_role_site_hr_and_approval_withdrawals_preserve_existing_ordinary_skipped_semantics(): void
    {
        $cases = [
            fn () => $this->allow($this->lead, 'incidents.viewAny', false),
            fn () => $this->lead->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]),
            fn () => $this->lead->hrEmployeeProfile()->update(['is_active' => false]),
            fn () => DB::table('users')->where('id', $this->lead->id)->update(['approved_at' => null]),
        ];
        foreach ($cases as $withdraw) {
            [$root, , $delivery] = $this->owned(); $this->lead->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            $withdraw(); app(IncidentOwnerNotifications::class)->deliver($delivery->id);
            $this->assertSame('skipped', $delivery->fresh()->status); $this->noNotice($delivery->id);
            $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
            $this->allow($this->lead, 'incidents.viewAny');
            DB::table('users')->where('id', $this->lead->id)->update(['approved_at' => now()]);
            $this->lead->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'is_active' => true]);
        }
    }

    public function test_source_reassociation_before_retry_cannot_certify_old_routing_tuple(): void
    {
        [$root, , $delivery] = $this->pendingTriage(); $old = $root->client_id;
        DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => \App\Models\Client::factory()->create(['site_id' => $this->site->id])->id]);
        $this->assertFalse(app(Triage::class)->deliver($delivery->id)['confirmed']); $this->noNotice($delivery->id);
        $this->assertSame('failed', $delivery->fresh()->status);
        DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $old]);
        $this->assertTrue(app(Triage::class)->deliver($delivery->id)['confirmed']);
    }

    public function test_late_source_change_prevents_actual_first_change_body_select(): void
    {
        [$root, , $delivery] = $this->owned();
        $this->mutateAtBody('incident_owner_changes', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
            fn () => $this->refusal(fn () => app(IncidentOwnerNotifications::class)->deliver($delivery->id)));
        $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_late_source_change_prevents_actual_first_intent_body_select(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $this->mutateAtBody('incident_routing_intents', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
            fn () => $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)));
        $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_late_source_change_prevents_actual_first_decision_body_select(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $this->mutateAtBody('incident_routing_decisions', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
            fn () => $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)));
        $this->noNotice($delivery->id);
    }

    public function test_late_private_setting_read_has_same_protected_parent_before_configuration_hydration(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $this->mutateAtBody('health_safety_setting_changes', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
            fn () => $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)));
        $this->noNotice($delivery->id);
    }

    public function test_source_mutation_at_actual_notice_payload_read_rolls_back_send_and_does_not_confirm(): void
    {
        [$root, , $delivery] = $this->owned();
        $from = 'from '.DB::connection()->getQueryGrammar()->wrapTable('notifications');
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function ($sql) use ($state, $from, $root): void {
            if (! $state->armed || ! str_starts_with($sql, 'select *') || ! str_contains($sql, $from)) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]);
        });
        try {
            $this->refusal(fn () => app(IncidentOwnerNotifications::class)->deliver($delivery->id));
            $this->assertTrue($state->fired); $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
        } finally { $state->armed = false; }
    }

    public function test_source_mutation_at_delivery_save_rolls_back_notice_before_final_acceptance(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentTriageDelivery::saving(function ($row) use ($root): void {
            if ($row->status === 'delivered') { DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]); }
        });
        try { $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_late_change_body_rewrite_is_not_a_new_accepted_fingerprint_baseline(): void
    {
        [$root, $change, $delivery] = $this->owned();
        // Rewrite between the change body read and the audit body read. Every independence-scoped source query
        // embeds `audit_logs as independence_audit`, so the audit table name alone would fire before the change read.
        $this->mutateAtBody('audit_logs', fn () => DB::table('incident_owner_changes')->where('id', $change->id)->update(['owner_name' => $this->lead->name.' ']),
            fn () => $this->refusal(fn () => app(IncidentOwnerNotifications::class)->deliver($delivery->id)),
            'AS notification_fp from '.DB::connection()->getQueryGrammar()->wrapTable('audit_logs'));
        $this->noNotice($delivery->id);
    }

    public function test_late_configuration_audit_rewrite_before_status_save_rolls_back_notice(): void
    {
        [, $decision, $delivery] = $this->pendingTriage();
        $setting = HealthSafetySettingChange::findOrFail($decision->setting_change_id);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentTriageDelivery::saving(function ($row) use ($setting): void {
            if ($row->status === 'delivered') { DB::table('audit_logs')->where('id', $setting->audit_id)->update(['action' => 'incidents.routing.settings.saved ']); }
        });
        try { $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->noNotice($delivery->id);
    }

    public function test_tampered_recipient_identity_is_refused_before_private_change_body(): void
    {
        [, , $delivery] = $this->owned(); $count = 0;
        DB::table('incident_owner_deliveries')->where('id', $delivery->id)->update(['recipient_user_id' => $this->cover->id]);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        IncidentOwnerChange::retrieved(function ($row) use (&$count): void { if (array_key_exists('owner_name', $row->getAttributes())) { $count++; } });
        try { $this->refusal(fn () => app(IncidentOwnerNotifications::class)->deliver($delivery->id)); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $count); $this->noNotice($delivery->id);
    }

    public function test_guarded_acceptance_actual_audit_select_refuses_late_parent_change_before_meta(): void
    {
        [$root, $change] = $this->owned();
        $this->currentSource($this->lead, $root, function ($source, $reads) use ($root, $change): void {
            $this->mutateAtBody('audit_logs', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
                fn () => $this->validation(fn () => app(Owners::class)->assertAcceptance($change, $source, $reads), 'ownership'), 'select *');
        });
    }

    public function test_legacy_negative_audit_query_cannot_turn_lost_parent_into_honest_absence(): void
    {
        $root = $this->incident(); $change = IncidentOwnerChange::create(['incident_id' => $root->id, 'revision' => 1,
            'owner_user_id' => $this->lead->id, 'owner_name' => $this->lead->name, 'actor_user_id' => $this->manager->id,
            'actor_name' => $this->manager->name, 'source' => 'manual', 'source_schema_version' => 0, 'audit_log_id' => null,
            'request_uuid' => (string) Str::uuid(), 'request_hash' => str_repeat('0', 64), 'created_at' => now()]);
        app(Owners::class)->assertAcceptance($change); // Historical unknown compatibility, no certificate.
        $this->currentSource($this->lead, $root, function ($source, $reads) use ($root, $change): void {
            $state = (object) ['armed' => true, 'fired' => false];
            DB::connection()->beforeExecuting(function ($sql) use ($state, $root): void {
                if (! $state->armed || ! str_starts_with(strtolower($sql), 'select exists') || ! str_contains($sql, 'audit_logs')) { return; }
                $state->armed = false; $state->fired = true;
                DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]);
            });
            try {
                $this->validation(fn () => app(Owners::class)->assertAcceptance($change, $source, $reads), 'ownership');
                $this->assertTrue($state->fired);
            } finally { $state->armed = false; }
        });
        app(Owners::class)->assertAcceptance($change); // Default legacy behaviour unchanged.
    }

    public function test_protected_owner_summary_never_loads_name_after_late_source_reassociation(): void
    {
        [$root] = $this->owned();
        $this->currentSource($this->manager, $root, function ($source, $reads, $current) use ($root): void {
            $state = (object) ['armed' => true, 'fired' => false];
            DB::connection()->beforeExecuting(function ($sql) use ($state, $root): void {
                if (! $state->armed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from '.DB::connection()->getQueryGrammar()->wrapTable('users'))
                    || ! str_contains($sql, DB::connection()->getQueryGrammar()->wrap('name')) || str_contains($sql, 'users.*')) { return; }
                $state->armed = false; $state->fired = true;
                DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]);
            });
            try {
                $this->assertNull(app(Owners::class)->summaryFromProtectedSourceInCurrentReads($current, $root, $reads, $source));
                $this->assertTrue($state->fired);
            } finally { $state->armed = false; }
        }, 'read');
    }

    public function test_guarded_verifiers_require_both_arguments_and_reject_expired_reads_including_legacy(): void
    {
        [$root, $change] = $this->owned(); $escaped = null; $parent = null;
        $this->currentSource($this->manager, $root, function ($source, $reads) use ($change, &$escaped, &$parent): void {
            $this->refusal(fn () => app(Owners::class)->assertAcceptance($change, $source));
            $this->refusal(fn () => app(Owners::class)->assertAcceptance($change, null, $reads));
            app(Owners::class)->assertAcceptance($change, $source, $reads);
            $escaped = $reads; $parent = $source;
        });
        $this->refusal(fn () => app(Owners::class)->assertAcceptance($change, $parent, $escaped));
    }

    public function test_healthy_neighbour_is_deliverable_while_named_private_notice_remains_retryable(): void
    {
        [$private, , $privateDelivery] = $this->owned(); $this->declare($private, $this->lead);
        [$healthy, , $healthyDelivery] = $this->owned();
        app(IncidentOwnerNotifications::class)->deliver($privateDelivery->id);
        app(IncidentOwnerNotifications::class)->deliver($healthyDelivery->id);
        $this->noNotice($privateDelivery->id); $this->assertSame('failed', $privateDelivery->fresh()->status);
        $this->assertSame('delivered', $healthyDelivery->fresh()->status);
        $this->assertNotSame($private->id, $healthy->id);
    }

    public function test_routing_verifier_audit_body_has_same_parent_on_actual_select(): void
    {
        [$root, $decision] = $this->pendingTriage(); $intent = IncidentRoutingIntent::findOrFail($decision->intent_id);
        $this->currentSource($this->triage, $root, function ($source, $reads) use ($root, $decision, $intent): void {
            $this->mutateAtBody('audit_logs', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
                fn () => $this->validation(fn () => app(Routing::class)->assertDecision($decision, $intent, $source, $reads), 'routing'), 'select *');
        });
    }

    public function test_configuration_verifier_audit_body_is_not_loaded_after_same_session_source_change(): void
    {
        [$root, $decision] = $this->pendingTriage(); $setting = HealthSafetySettingChange::findOrFail($decision->setting_change_id);
        $this->currentSource($this->triage, $root, function ($source, $reads) use ($root, $setting): void {
            $this->mutateAtBody('audit_logs', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
                fn () => $this->validation(fn () => app(Settings::class)->assertHistory($setting, $source, $reads), 'configuration'), 'select *');
        });
    }

    public function test_protected_summary_latest_owner_change_has_source_on_body_select(): void
    {
        [$root] = $this->owned();
        $this->currentSource($this->manager, $root, function ($source, $reads, $current) use ($root): void {
            $this->mutateAtBody('incident_owner_changes', fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
                fn () => $this->assertNull(app(Owners::class)->summaryFromProtectedSourceInCurrentReads($current, $root, $reads, $source)), 'select *');
        }, 'read');
    }

    public function test_already_delivered_history_is_retained_but_current_named_refusal_never_reconfirms_source(): void
    {
        [$root, , $delivery] = $this->pendingTriage();
        $this->assertTrue(app(Triage::class)->deliver($delivery->id)['confirmed']);
        $this->declare($root, $this->triage);
        $this->assertFalse(app(Triage::class)->deliver($delivery->id)['confirmed']);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_named_triage_refusal_does_not_hydrate_outer_current_configuration_or_history(): void
    {
        [$root, , $delivery] = $this->pendingTriage(); $this->declare($root, $this->triage);
        $state = (object) ['settings' => 0, 'history' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        \App\Models\AppSetting::retrieved(function ($row) use ($state): void {
            if ($row->key === Settings::KEY && array_key_exists('value', $row->getAttributes())) { $state->settings++; }
        });
        HealthSafetySettingChange::retrieved(function ($row) use ($state): void {
            if ($row->setting_key === Settings::KEY && array_key_exists('after_value', $row->getAttributes())) { $state->history++; }
        });
        try { $this->assertFalse(app(Triage::class)->deliver($delivery->id)['confirmed']); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $state->settings); $this->assertSame(0, $state->history);
        $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_owner_final_status_requires_exact_bytes_after_actual_delivery_saved_observer(): void
    {
        [, , $delivery] = $this->owned();
        foreach (['DELIVERED', 'delivered '] as $damaged) {
            $fired = false; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
            IncidentOwnerDelivery::saved(function ($row) use ($delivery, $damaged, &$fired): void {
                if ($row->id === $delivery->id && $row->status === 'delivered') {
                    $fired = true; DB::table('incident_owner_deliveries')->where('id', $row->id)->update(['status' => $damaged]);
                }
            });
            try { $this->refusal(fn () => app(IncidentOwnerNotifications::class)->deliver($delivery->id)); }
            finally { Model::setEventDispatcher($dispatcher); }
            $this->assertTrue($fired); $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
        }
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_triage_final_status_requires_exact_bytes_after_actual_delivery_saved_observer(): void
    {
        [, , $delivery] = $this->pendingTriage();
        foreach (['DELIVERED', 'delivered '] as $damaged) {
            $fired = false; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
            IncidentTriageDelivery::saved(function ($row) use ($delivery, $damaged, &$fired): void {
                if ($row->id === $delivery->id && $row->status === 'delivered') {
                    $fired = true; DB::table('incident_triage_deliveries')->where('id', $row->id)->update(['status' => $damaged]);
                }
            });
            try { $this->refusal(fn () => app(Triage::class)->deliver($delivery->id)); }
            finally { Model::setEventDispatcher($dispatcher); }
            $this->assertTrue($fired); $this->noNotice($delivery->id); $this->assertSame('failed', $delivery->fresh()->status);
        }
        $this->assertTrue(app(Triage::class)->deliver($delivery->id)['confirmed']);
    }
}
