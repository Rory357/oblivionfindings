<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentIndependentHandlingDeclaration as Declaration;
use App\Models\IncidentIndependentHandlingExclusion as Exclusion;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentIndependenceCaptureAccess;
use App\Services\Incidents\IncidentIndependenceIntakeService as Intake;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentIndependenceIntakeTest extends IncidentIndependenceTestCase
{
    private function protection(int $id): array
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($id): array {
            $actor = app(IncidentIndependenceCaptureAccess::class)->current($this->reporter, $reads);
            return app(Intake::class)->protectionInReads($id, $actor, $reads);
        }));
    }
    public function test_reporter_without_read_or_assignment_can_capture_and_recover_minimal_receipt(): void
    {
        $first = $this->accept($this->command());
        $this->assertCount(10, $first); $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']));
        $this->assertStringNotContainsString('involved_user_ids', json_encode($first)); $this->assertStringNotContainsString('Synthetic private', json_encode($first));
        $this->assertFalse($this->reporter->fresh()->canDo('incidents.owner.assign'));
        $this->assertDatabaseHas('incident_owner_exclusions', ['incident_id' => $first['incident_id'], 'user_id' => $this->lead->id, 'recorded_by' => $this->reporter->id]);
    }
    public function test_unknown_records_no_fake_user_but_requires_restricted_routing(): void
    {
        $first = $this->accept($this->command(['independent_handling' => ['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'unknown', 'involved_user_ids' => []]]));
        $this->assertSame(0, Exclusion::count()); $proof = $this->protection($first['incident_id']);
        $this->assertTrue($proof['restricted_routing']); $this->assertSame('unknown', $proof['identification']); $this->assertSame([], $proof['block_user_ids']);
    }
    public function test_exact_retry_does_not_run_report_callback_or_add_history(): void
    {
        $command = $this->command(); $first = $this->accept($command); $audits = AuditLog::count();
        $this->assertSame($first, app(Intake::class)->intake($this->reporter, $command, fn () => throw new \LogicException('Replay must not write report again.')));
        $this->assertSame(1, Declaration::count()); $this->assertSame($audits, AuditLog::count());
    }
    public function test_changed_payload_and_cross_settings_uuid_are_refused(): void
    {
        $command = $this->command(); $this->accept($command); $command['independent_handling']['reason_code'] = 'other_staff_involved';
        $this->validation(fn () => $this->accept($command), 'request_uuid');
        $routing = $this->settings(); $this->validation(fn () => $this->accept($this->command(['request_uuid' => $routing['request_uuid']])), 'request_uuid');
    }
    public function test_draft_to_submit_has_new_command_identity_and_kept_declaration_version(): void
    {
        $draft = $this->command(['intent' => 'draft']); $first = $this->accept($draft);
        $submit = [...$draft, 'request_uuid' => (string) Str::uuid(), 'expected_revision' => 1, 'intent' => 'submit'];
        $second = $this->accept($submit); $this->assertSame('kept', $second['action']);
        $this->assertSame($first['declaration_id'], $second['declaration_id']); $this->assertSame(1, $second['declaration_revision']);
        $this->assertSame(2, HealthSafetySettingChange::where('origin', 'incident_independent_handling')->count());
        $this->assertSame('submitted', ClientIncident::findOrFail($first['incident_id'])->status);
        $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $draft['request_uuid']));
        $this->assertSame($first, app(Intake::class)->intake($this->reporter, $draft, fn () => throw new \LogicException('Old draft replay cannot revert submitted state.')));
    }
    public function test_open_policy_change_requires_first_capture_review(): void
    {
        $command = $this->command(); app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Changed explicit policy.']);
        $this->validation(fn () => $this->accept($command), 'expected_policy_revision'); $this->assertSame(0, Declaration::count());
    }
    public function test_accepted_strong_snapshot_does_not_loosen_after_policy_flip_or_closure(): void
    {
        $draft = $this->command(['intent' => 'draft']); $first = $this->accept($draft);
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'New policy for future reports.']);
        $options = app(Intake::class)->captureOptions($this->reporter, $this->context(), $draft['report_request_uuid']);
        $this->assertSame('block_involved_access', $options['policy']['mode']); $this->assertSame(0, $options['policy']['policy_revision']);
        $this->accept([...$draft, 'request_uuid' => (string) Str::uuid(), 'expected_revision' => 1, 'intent' => 'submit']);
        DB::table('client_incidents')->where('id', $first['incident_id'])->update(['status' => 'closed']);
        $this->assertSame([(int) $this->lead->id], $this->protection($first['incident_id'])['block_user_ids']);
    }
    public function test_assignment_only_snapshot_does_not_create_read_denial(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit optional policy.']);
        $first = $this->accept($this->command(['expected_policy_revision' => 1])); $proof = $this->protection($first['incident_id']);
        $this->assertSame([], $proof['block_user_ids']); $this->assertSame([(int) $this->lead->id], $proof['excluded_user_ids']);
    }
    public function test_draft_correction_cannot_erase_already_accepted_exclusions_or_unknown(): void
    {
        $draft = $this->command(['intent' => 'draft']); $first = $this->accept($draft);
        $next = [...$draft, 'request_uuid' => (string) Str::uuid(), 'expected_revision' => 1, 'independent_handling' => ['requested' => true,
            'reason_code' => 'other_staff_involved', 'identification' => 'unknown', 'involved_user_ids' => []]];
        $this->accept($next); $proof = $this->protection($first['incident_id']);
        $this->assertSame([(int) $this->lead->id], $proof['block_user_ids']); $this->assertSame('unknown', $proof['identification']);
    }
    public function test_existing_owner_and_legacy_exclusion_attribution_are_preserved(): void
    {
        $draft = $this->command(['intent' => 'draft']); $first = $this->accept($draft);
        DB::table('client_incidents')->where('id', $first['incident_id'])->update(['incident_owner_user_id' => $this->lead->id, 'owner_revision' => 1]);
        $this->accept([...$draft, 'request_uuid' => (string) Str::uuid(), 'expected_revision' => 1, 'intent' => 'submit']);
        $this->assertDatabaseHas('client_incidents', ['id' => $first['incident_id'], 'incident_owner_user_id' => $this->lead->id, 'owner_revision' => 1]);
    }
    public function test_stale_declaration_precedes_report_callback(): void
    {
        $draft = $this->command(['intent' => 'draft']); $this->accept($draft);
        $this->validation(fn () => app(Intake::class)->intake($this->reporter, [...$draft, 'request_uuid' => (string) Str::uuid()], fn () => throw new \LogicException('Stale callback must not run.')), 'expected_revision');
    }
    public function test_capture_grant_and_site_withdrawal_precede_receipt_and_names(): void
    {
        $first = $this->accept($this->command()); $this->allow($this->reporter, 'incidents.create', false);
        $this->denial(fn () => app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']));
        $this->denial(fn () => app(Intake::class)->captureCandidates($this->reporter, $this->context()));
        $this->allow($this->reporter, 'incidents.create'); $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']));
        DB::table('hr_employee_profiles')->where('user_id', $this->reporter->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $this->denial(fn () => app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']), 404);
    }
    public function test_other_actor_cannot_recover_or_reuse_original_report(): void
    {
        $command = $this->command(); $first = $this->accept($command); $other = $this->staff(['incidents.create', 'clients.viewAny']);
        $this->assertNull(app(Intake::class)->confirmationForKey($other, $first['request_uuid']));
        $this->denial(fn () => app(Intake::class)->captureOptions($other, $this->context(), $command['report_request_uuid']), 404);
    }
    public function test_named_picker_admits_site_and_client_before_names_and_filters_exact_selection(): void
    {
        $first = app(Intake::class)->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id);
        $this->assertSame([['id' => (int) $this->lead->id, 'name' => $this->lead->name]], $first['candidates']);
        $this->allow($this->reporter, 'clients.viewAny', false); $queries = []; DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });
        $this->denial(fn () => app(Intake::class)->captureCandidates($this->reporter, $this->context()));
        // No query may PROJECT a name before the denial. A role-name filter in a WHERE (roles.name in (...)) selects nobody's name.
        $this->assertDoesNotMatchRegularExpression('/select\s+(?:(?!\bfrom\b)[^;])*["`]name["`]/i', implode(';', $queries));
    }
    public function test_foreign_or_inactive_named_person_is_not_accepted(): void
    {
        $foreign = $this->staff([], \App\Models\Site::factory()->create(['is_active' => true]));
        $command = $this->command(); $command['independent_handling']['involved_user_ids'] = [(int) $foreign->id];
        $this->validation(fn () => $this->accept($command), 'independent_handling.involved_user_ids');
        DB::table('hr_employee_profiles')->where('user_id', $this->lead->id)->update(['is_active' => false]);
        $this->validation(fn () => $this->accept($this->command()), 'independent_handling.involved_user_ids');
    }
    public function test_marker_downgrade_missing_acceptance_and_tampered_exclusion_do_not_become_legacy(): void
    {
        $first = $this->accept($this->command()); DB::table('client_incidents')->where('id', $first['incident_id'])->update(['independence_schema_version' => 0, 'independence_revision' => 0, 'independent_handling_requested' => false]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
        DB::table('client_incidents')->where('id', $first['incident_id'])->update(['independence_schema_version' => 1, 'independence_revision' => 1, 'independent_handling_requested' => true]);
        DB::table('incident_independent_handling_exclusions')->update(['prior_existing' => true]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
    }
    public function test_root_rebind_and_polluted_receipt_or_missing_audit_refuse_recovery(): void
    {
        $first = $this->accept($this->command()); $row = HealthSafetySettingChange::where('command_key', $first['request_uuid'])->firstOrFail();
        HealthSafetySettingChange::whereKey($row->id)->update(['accepted_result' => json_encode($first + ['private_body' => 'forged'])]);
        $this->validation(fn () => app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']), 'independent_handling');
        HealthSafetySettingChange::whereKey($row->id)->update(['accepted_result' => json_encode($first)]);
        AuditLog::whereKey($row->audit_id)->update(['action' => 'forged']);
        $this->validation(fn () => app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']), 'independent_handling');
    }
    public function test_acceptance_save_veto_rolls_report_declaration_exclusions_and_audit_back(): void
    {
        $command = $this->command(); $this->veto(HealthSafetySettingChange::class, fn () => $this->accept($command));
        $this->assertDatabaseMissing('client_incidents', ['report_request_uuid' => $command['report_request_uuid']]);
        $this->assertSame(0, Declaration::count()); $this->assertSame(0, Exclusion::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'incidents.independence.declaration.%')->count());
    }
    public function test_declaration_is_immutable_and_callback_cannot_escape_an_authority_handle(): void
    {
        $first = $this->accept($this->command()); $row = Declaration::findOrFail($first['declaration_id']);
        try { $row->handling = ['requested' => false]; $row->save(); $this->fail('Expected immutable refusal.'); } catch (\LogicException) { $this->assertTrue(true); }
        try { app(Intake::class)->intake($this->reporter, $this->command(), fn () => $this->reporter); $this->fail('Expected callback contract refusal.'); }
        catch (\LogicException) { $this->assertSame(1, Declaration::count()); }
    }
    public function test_actual_source_scope_rebind_is_repair_not_a_new_original_tuple(): void
    {
        $first = $this->accept($this->command()); $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('client_incidents')->where('id', $first['incident_id'])->update(['client_id' => $client->id]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
    }
    public function test_genuine_legacy_exclusion_is_not_retrocertified_as_strong_read_protection(): void
    {
        $root = $this->incident(); DB::table('incident_owner_exclusions')->insert(['incident_id' => $root->id, 'user_id' => $this->lead->id, 'recorded_by' => $this->manager->id,
            'reason_code' => 'legacy', 'created_at' => now(), 'updated_at' => now()]);
        $proof = $this->protection((int) $root->id); $this->assertSame('legacy_unknown', $proof['state']); $this->assertSame([], $proof['block_user_ids']);
    }
    public function test_canonical_additional_source_admission_denies_named_reader_and_notification_not_generic_work(): void
    {
        $first = $this->accept($this->command());
        foreach (['read', 'notification', 'assignment'] as $purpose) {
            $this->denial(fn () => DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) =>
                app(Intake::class)->admitSourceInReads($first['incident_id'], app(\App\Services\AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($this->lead, IncidentIndependenceCaptureAccess::PERMISSIONS), $reads, $purpose))), 404);
        }
        $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']));
    }
    public function test_assignment_only_never_adds_read_rights_but_retains_assignment_exclusion(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit weaker future policy.']);
        $first = $this->accept($this->command(['expected_policy_revision' => 1]));
        $result = DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => app(Intake::class)->admitSourceInReads($first['incident_id'],
            app(\App\Services\AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($this->lead, IncidentIndependenceCaptureAccess::PERMISSIONS), $reads)));
        $this->assertSame('recorded', $result['state']);
        $this->denial(fn () => DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => app(Intake::class)->admitSourceInReads($first['incident_id'],
            app(\App\Services\AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($this->lead, IncidentIndependenceCaptureAccess::PERMISSIONS), $reads, 'assignment'))), 404);
        $this->assertArrayNotHasKey('can_read', $result);
    }
    public function test_missing_original_acceptance_and_immutable_exclusion_link_refuse_proof(): void
    {
        $first = $this->accept($this->command()); $row = Declaration::findOrFail($first['declaration_id']);
        Declaration::whereKey($row->id)->update(['acceptance_id' => null]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
        Declaration::whereKey($row->id)->update(['acceptance_id' => $row->acceptance_id]);
        DB::table('incident_owner_exclusions')->where('incident_id', $first['incident_id'])->update(['user_id' => $this->cover->id]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
    }
    public function test_self_named_reporter_can_recover_capture_acceptance_despite_saved_source_denial(): void
    {
        $command = $this->command(); $command['independent_handling']['involved_user_ids'] = [(int) $this->reporter->id];
        $first = $this->accept($command);
        $this->denial(fn () => DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($first): array {
            return app(Intake::class)->admitSourceInReads($first['incident_id'], app(IncidentIndependenceCaptureAccess::class)->current($this->reporter, $reads), $reads);
        })), 404);
        $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $first['request_uuid']));
    }
    public function test_legacy_null_site_preserves_canonical_current_client_fallback_without_a_policy_inference(): void
    {
        $root = $this->incident(['client_id' => $this->client->id, 'site_id' => null, 'shift_id' => null, 'hs_event_id' => null]);
        $proof = $this->protection((int) $root->id); $this->assertSame('legacy_unknown', $proof['state']); $this->assertFalse($proof['requested']);
    }
    public function test_reports_global_grant_never_bypasses_canonical_saved_source_site_scope(): void
    {
        $root = $this->incident(['client_id' => $this->client->id]); $this->allow($this->reporter, 'reports.viewAny');
        DB::table('hr_employee_profiles')->where('user_id', $this->reporter->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $this->denial(fn () => $this->protection((int) $root->id), 404);
    }
    public function test_moved_retained_declaration_cannot_relabel_downgraded_parent_as_legacy(): void
    {
        $first = $this->accept($this->command()); $foreign = $this->incident();
        Declaration::whereKey($first['declaration_id'])->update(['incident_id' => $foreign->id]);
        DB::table('client_incidents')->where('id', $first['incident_id'])->update(['independence_schema_version' => 0, 'independence_revision' => 0, 'independent_handling_requested' => false]);
        $this->validation(fn () => $this->protection($first['incident_id']), 'independent_handling');
    }
    public function test_audit_save_veto_cannot_leave_report_or_protection_half_committed(): void
    {
        $command = $this->command(); $this->veto(AuditLog::class, fn () => $this->accept($command));
        $this->assertDatabaseMissing('client_incidents', ['report_request_uuid' => $command['report_request_uuid']]);
        $this->assertSame(0, Declaration::count()); $this->assertSame(0, Exclusion::count());
        $this->assertSame(0, HealthSafetySettingChange::where('origin', 'incident_independent_handling')->count());
    }
    public function test_prior_legacy_exclusion_is_linked_without_rewriting_its_original_attribution(): void
    {
        $command = $this->command(['intent' => 'draft']);
        $root = $this->incident(['client_id' => $this->client->id, 'status' => 'draft', 'shift_id' => null, 'source' => 'manual', 'report_request_uuid' => $command['report_request_uuid']]);
        $old = DB::table('incident_owner_exclusions')->insertGetId(['incident_id' => $root->id, 'user_id' => $this->lead->id, 'recorded_by' => $this->manager->id,
            'reason_code' => 'legacy', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $first = $this->accept($command);
        $this->assertDatabaseHas('incident_owner_exclusions', ['id' => $old, 'recorded_by' => $this->manager->id, 'reason_code' => 'legacy']);
        $this->assertDatabaseHas('incident_independent_handling_exclusions', ['declaration_id' => $first['declaration_id'], 'exclusion_id' => $old, 'prior_existing' => true]);
    }
    public function test_outer_report_journey_failure_rolls_back_already_accepted_declaration_and_source(): void
    {
        $command = $this->command();
        try {
            DB::transaction(function () use ($command): void {
                $receipt = $this->accept($command);
                $this->assertDatabaseHas('client_incidents', ['id' => $receipt['incident_id'], 'independent_handling_requested' => true]);
                $this->assertDatabaseHas('incident_owner_exclusions', ['incident_id' => $receipt['incident_id'], 'user_id' => $this->lead->id]);
                // Composition protocol: Journey follows declaration inside THIS physical root transaction.
                // Actual Journey integration is separately root-owned; its failure must not leave acceptance behind.
                throw new \LogicException('Synthetic post-declaration Journey persistence refusal.');
            });
            $this->fail('Expected enclosing source transaction refusal.');
        } catch (\LogicException $error) { $this->assertSame('Synthetic post-declaration Journey persistence refusal.', $error->getMessage()); }
        $this->assertDatabaseMissing('client_incidents', ['report_request_uuid' => $command['report_request_uuid']]);
        $this->assertSame(0, Declaration::count()); $this->assertSame(0, Exclusion::count());
        $this->assertSame(0, HealthSafetySettingChange::where('origin', 'incident_independent_handling')->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'incidents.independence.declaration.%')->count());
    }
}
