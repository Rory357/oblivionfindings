<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentFollowup;
use App\Models\IncidentIndependentHandlingDeclaration as Declaration;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Incidents\IncidentIndependenceIntakeService as Intake;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use App\Services\Incidents\IncidentRegisterReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Whole source collection suite. Actual consumer mounting, native MySQL/concurrency and query ceilings UNRUN. */
final class IncidentIndependenceCollectionTest extends IncidentIndependenceTestCase
{
    private function scope(User $actor, callable $consume, string $purpose = 'read', ?int $id = null): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $consume, $purpose, $id): mixed {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, IncidentRegisterReadService::PERMISSIONS);
            // The ordinary capability/assignment/Site/HR gate remains separate and is not conferred by independence.
            $base = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads);
            if ($id !== null) { $base->whereKey($id); }
            $query = app(Intake::class)->applySourceScopeInReads($base, $current, $reads, $purpose);
            return $consume($query, $reads, $current);
        }));
    }

    private function ids(User $actor, string $purpose = 'read'): array
    {
        return $this->scope($actor, fn (Builder $query) => $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), $purpose);
    }

    private function acceptance(array $receipt): HealthSafetySettingChange
    {
        return HealthSafetySettingChange::where('command_key', $receipt['request_uuid'])->firstOrFail();
    }

    private function assertLateConcealed(array $receipt, callable $mutate): void
    {
        $this->scope($this->manager, function (Builder $query) use ($mutate): void {
            $this->assertSame(1, (clone $query)->count());
            $mutate();
            $this->assertSame(0, (clone $query)->count());
            $page = (clone $query)->paginate(50);
            $this->assertSame(0, $page->total()); $this->assertSame([], $page->items());
        }, id: $receipt['incident_id']);
    }

    public function test_named_strong_denial_precedes_counts_pagination_and_followup_parent_projection(): void
    {
        $private = $this->accept($this->command()); $healthy = $this->incident();
        foreach ([$private['incident_id'], $healthy->id] as $id) {
            IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create(['client_incident_id' => $id,
                'assigned_to_user_id' => $this->lead->id, 'notes' => 'Private linked instruction '.$id, 'created_by' => $this->reporter->id]));
        }
        $this->scope($this->lead, function (Builder $query, $reads) use ($healthy): void {
            $this->assertSame(1, (clone $query)->count());
            $page = (clone $query)->orderBy('id')->paginate(1);
            $this->assertSame(1, $page->total()); $this->assertSame([$healthy->id], $page->getCollection()->pluck('id')->all());
            $children = $reads->query(IncidentFollowup::query()->whereIn('client_incident_id', (clone $query)->select('client_incidents.id')))->get();
            $this->assertSame([$healthy->id], $children->pluck('client_incident_id')->all());
        });
        $this->assertSame([$healthy->id], $this->ids($this->lead, 'notification'));
        $this->assertSame([$private['incident_id'], $healthy->id], $this->ids($this->manager));
    }

    public function test_assignment_only_preserves_normal_reads_and_notifications_but_denies_named_assignment(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit future policy.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1]));
        $this->assertSame([$receipt['incident_id']], $this->ids($this->lead));
        $this->assertSame([$receipt['incident_id']], $this->ids($this->lead, 'notification'));
        $this->assertSame([], $this->ids($this->lead, 'assignment'));
        $this->assertSame([], $this->ids($this->reporter)); // Capture permission is not saved-record read permission.
    }

    public function test_normal_site_and_hr_privacy_are_not_broadened_by_the_additional_scope(): void
    {
        $receipt = $this->accept($this->command());
        $this->incident(['site_id' => \App\Models\Site::factory()->create()->id]);
        $this->incident(['is_hr_confidential' => true]);
        $this->assertSame([$receipt['incident_id']], $this->ids($this->manager));
        $this->allow($this->manager, 'reports.viewAny');
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $this->assertSame([], $this->ids($this->manager));
    }

    public function test_retained_strong_policy_survives_new_setting_and_closure(): void
    {
        $receipt = $this->accept($this->command());
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Only future reports use this setting.']);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['status' => 'closed']);
        $this->assertSame([], $this->ids($this->lead));
        $this->assertSame([$receipt['incident_id']], $this->ids($this->manager));
    }

    public function test_legacy_exclusion_and_unknown_person_do_not_invent_a_named_read_denial(): void
    {
        $legacy = $this->incident();
        DB::table('incident_owner_exclusions')->insert(['incident_id' => $legacy->id, 'user_id' => $this->lead->id,
            'recorded_by' => $this->manager->id, 'reason_code' => 'legacy', 'created_at' => now(), 'updated_at' => now()]);
        $command = $this->command(); $command['independent_handling'] = ['requested' => true, 'reason_code' => 'other_staff_involved',
            'identification' => 'unknown', 'involved_user_ids' => []];
        $unknown = $this->accept($command);
        $this->assertSame([$legacy->id, $unknown['incident_id']], $this->ids($this->lead));
    }

    public function test_row_local_damaged_evidence_is_concealed_without_losing_unrelated_work_and_restores_exactly(): void
    {
        $receipt = $this->accept($this->command()); $healthy = $this->incident();
        $row = Declaration::findOrFail($receipt['declaration_id']); $old = $row->getRawOriginal('handling');
        DB::table($row->getTable())->where('id', $row->id)->update(['handling' => json_encode(['requested' => false])]);
        $this->assertSame([$healthy->id], $this->ids($this->manager));
        DB::table($row->getTable())->where('id', $row->id)->update(['handling' => $old]);
        $this->assertSame([$receipt['incident_id'], $healthy->id], $this->ids($this->manager));
    }

    public function test_final_sql_rejects_late_root_context_and_privacy_changes(): void
    {
        $receipt = $this->accept($this->command());
        $this->assertLateConcealed($receipt, fn () => DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['is_hr_confidential' => true]));
    }

    public function test_mutation_between_semantic_proof_and_graph_capture_cannot_become_a_new_accepted_baseline(): void
    {
        $receipt = $this->accept($this->command()); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $receipt): void {
            if (! $armed || ! str_contains($sql, 'AS evidence_fingerprint')) { return; }
            $armed = false; $fired = true;
            DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])
                ->update(['handling' => json_encode(['requested' => false])]);
        });
        try {
            $this->assertSame([], $this->scope($this->manager, fn (Builder $query) => $query->pluck('id')->all(), id: $receipt['incident_id']));
            $this->assertTrue($fired, 'The actual proof-to-graph interleaving must occur.');
        } finally { $armed = false; }
    }

    public function test_invalid_nested_named_ids_are_row_local_repair_instead_of_hiding_healthy_work(): void
    {
        $receipt = $this->accept($this->command()); $healthy = $this->incident();
        DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])
            ->update(['handling' => json_encode(['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'named', 'involved_user_ids' => []])]);
        $this->assertSame([$healthy->id], $this->ids($this->manager));
    }

    public function test_scalar_stored_handling_or_policy_is_row_local_repair_before_typed_validation(): void
    {
        $handling = $this->accept($this->command()); $policy = $this->accept($this->command()); $healthy = $this->incident();
        DB::table('incident_independent_handling_declarations')->where('id', $handling['declaration_id'])->update(['handling' => 'false']);
        DB::table('incident_independent_handling_declarations')->where('id', $policy['declaration_id'])->update(['policy_snapshot' => 'false']);
        $this->assertSame([$healthy->id], $this->ids($this->manager));
    }

    public function test_new_declaration_between_proof_and_graph_capture_is_not_accepted_as_proved_membership(): void
    {
        $receipt = $this->accept($this->command());
        $copy = (array) DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])->first();
        unset($copy['id']); $copy['revision'] = 2; $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $copy): void {
            if (! $armed || ! str_contains($sql, 'AS evidence_fingerprint')) { return; }
            $armed = false; $fired = true; DB::table('incident_independent_handling_declarations')->insert($copy);
        });
        try {
            $this->assertSame([], $this->scope($this->manager, fn (Builder $query) => $query->pluck('id')->all(), id: $receipt['incident_id']));
            $this->assertTrue($fired);
        } finally { $armed = false; }
    }

    public function test_legacy_absence_between_proof_and_graph_capture_cannot_admit_a_new_audit_claim(): void
    {
        $receipt = $this->accept($this->command()); $legacy = $this->incident(); $row = $this->acceptance($receipt);
        $copy = (array) DB::table('audit_logs')->where('id', $row->audit_id)->first(); unset($copy['id']);
        $copy['meta'] = json_encode(['incident_id' => (int) $legacy->id, 'retained_claim' => true]); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $copy): void {
            // The claim lands during evidence capture: the per-incident graph read, or (for an incident with no evidence
            // yet) the batched evidence read that precedes it (speed, 30 Oct). Either way it must not be admitted.
            $batched = str_contains($sql, 'from `audit_logs`') && str_contains($sql, '`meta`') && str_contains($sql, ' in (');
            if (! $armed || (! str_contains($sql, 'AS evidence_fingerprint') && ! $batched)) { return; }
            $armed = false; $fired = true; DB::table('audit_logs')->insert($copy);
        });
        try {
            $this->assertSame([], $this->scope($this->manager, fn (Builder $query) => $query->pluck('id')->all(), id: (int) $legacy->id));
            $this->assertTrue($fired);
        } finally { $armed = false; }
    }

    public function test_final_sql_rejects_late_declaration_body_change_with_unchanged_revision_and_digest(): void
    {
        $receipt = $this->accept($this->command());
        $this->assertLateConcealed($receipt, fn () => DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])
            ->update(['handling' => json_encode(['requested' => true, 'reason_code' => 'other_staff_involved', 'identification' => 'unknown', 'involved_user_ids' => []])]));
    }

    public function test_final_sql_rejects_late_accepted_receipt_body_change_without_root_revision_change(): void
    {
        $receipt = $this->accept($this->command()); $row = $this->acceptance($receipt);
        $this->assertLateConcealed($receipt, fn () => DB::table('health_safety_setting_changes')->where('id', $row->id)->update(['actor_snapshot' => json_encode(['id' => $this->manager->id, 'name' => 'Changed private attribution'])]));
    }

    public function test_final_sql_rejects_late_audit_body_change(): void
    {
        $receipt = $this->accept($this->command()); $row = $this->acceptance($receipt);
        $this->assertLateConcealed($receipt, fn () => DB::table('audit_logs')->where('id', $row->audit_id)->update(['meta' => json_encode(['incident_id' => $receipt['incident_id'], 'forged' => true])]));
    }

    public function test_final_sql_rejects_late_original_owner_exclusion_association_change(): void
    {
        $receipt = $this->accept($this->command());
        $this->assertLateConcealed($receipt, fn () => DB::table('incident_owner_exclusions')->where('incident_id', $receipt['incident_id'])->update(['user_id' => $this->cover->id]));
    }

    public function test_final_sql_rejects_late_exclusion_binding_provenance_change(): void
    {
        $receipt = $this->accept($this->command());
        $this->assertLateConcealed($receipt, fn () => DB::table('incident_independent_handling_exclusions')->where('declaration_id', $receipt['declaration_id'])->update(['prior_existing' => true]));
    }

    public function test_final_sql_negative_guard_rejects_a_late_declaration_without_advancing_root_revision(): void
    {
        $receipt = $this->accept($this->command()); $copy = (array) DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])->first();
        unset($copy['id']); $copy['revision'] = 2;
        $this->assertLateConcealed($receipt, fn () => DB::table('incident_independent_handling_declarations')->insert($copy));
    }

    public function test_final_sql_negative_guard_rejects_late_foreign_declaration_moved_under_the_source(): void
    {
        $first = $this->accept($this->command()); $other = $this->accept($this->command());
        // Make the incoming row's revision distinct; its recorded source_tuple still identifies the foreign root.
        DB::table('incident_independent_handling_declarations')->where('id', $other['declaration_id'])->update(['revision' => 2]);
        $this->assertLateConcealed($first, fn () => DB::table('incident_independent_handling_declarations')->where('id', $other['declaration_id'])->update(['incident_id' => $first['incident_id']]));
    }

    public function test_final_sql_negative_guard_rejects_late_duplicate_audit_claim(): void
    {
        $receipt = $this->accept($this->command()); $row = $this->acceptance($receipt);
        $copy = (array) DB::table('audit_logs')->where('id', $row->audit_id)->first(); unset($copy['id']);
        $this->assertLateConcealed($receipt, fn () => DB::table('audit_logs')->insert($copy));
    }

    public function test_original_policy_audit_is_bound_while_today_policy_change_does_not_rewrite_accepted_snapshot(): void
    {
        $policy = app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit configurable policy.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1]));
        $auditId = HealthSafetySettingChange::where('command_key', $policy['request_uuid'])->value('audit_id');
        $this->assertLateConcealed($receipt, fn () => DB::table('audit_logs')->where('id', $auditId)->update(['action' => 'forged']));
    }

    public function test_retained_audit_claim_prevents_falsely_legacy_source_even_when_other_evidence_is_rebound(): void
    {
        $receipt = $this->accept($this->command()); $foreign = $this->incident();
        $tuple = Declaration::findOrFail($receipt['declaration_id'])->source_tuple; $tuple['incident_id'] = (int) $foreign->id;
        DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])->update(['incident_id' => $foreign->id, 'source_tuple' => json_encode($tuple)]);
        $row = $this->acceptance($receipt); $after = $row->after_value; $after['incident_id'] = (int) $foreign->id;
        $result = $receipt; $result['incident_id'] = (int) $foreign->id;
        DB::table('health_safety_setting_changes')->where('id', $row->id)->update(['setting_key' => Policy::DECLARATION_PREFIX.$foreign->id,
            'after_value' => json_encode($after), 'accepted_result' => json_encode($result)]);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['independent_handling_requested' => false, 'independence_schema_version' => 0, 'independence_revision' => 0]);
        $this->assertSame([], $this->scope($this->manager, fn (Builder $query) => $query->pluck('id')->all(), id: $receipt['incident_id']));
    }

    public function test_bound_query_cannot_execute_after_current_read_lifetime_expires(): void
    {
        $this->accept($this->command());
        $query = $this->scope($this->manager, fn (Builder $query) => $query);
        $this->expectException(\LogicException::class); $query->count();
    }

    /** Speed path (30 Oct): an evidence-free incident joins one set-based block; evidence or a fact change that
     * appears after scoping still conceals it, as the per-incident block did. */
    public function test_an_evidence_free_incident_is_concealed_when_evidence_or_its_facts_change_after_scoping(): void
    {
        $healthy = $this->incident();
        $this->scope($this->manager, function (Builder $query) use ($healthy): void {
            $this->assertSame(1, (clone $query)->count());
            AuditLog::query()->forceCreate(['user_id' => $this->manager->id, 'action' => 'incidents.independence.declaration.accepted',
                'meta' => ['incident_id' => $healthy->id]]);
            $this->assertSame(0, (clone $query)->count());
        }, id: $healthy->id);

        $changed = $this->incident();
        $this->scope($this->manager, function (Builder $query) use ($changed): void {
            $this->assertSame(1, (clone $query)->count());
            DB::table('client_incidents')->where('id', $changed->id)->update(['status' => 'closed']);
            $this->assertSame(0, (clone $query)->count());
        }, id: $changed->id);
    }

    public function test_evidence_free_and_declared_incidents_share_one_scope_with_their_own_rules(): void
    {
        $private = $this->accept($this->command());
        $healthy = $this->incident();
        $second = $this->incident();
        // The lead is named on the declared incident and sees only the plain ones; the manager sees all three.
        $this->assertSame([$healthy->id, $second->id], $this->ids($this->lead));
        $this->assertSame([$private['incident_id'], $healthy->id, $second->id], $this->ids($this->manager));
    }
}
