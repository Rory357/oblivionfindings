<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\HealthSafety\InjuryWorkspaceService;
use App\Services\HealthSafety\InjuryAccessService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Complete factual/medical record boundary, including exact current evidence withdrawal. */
class InjuryWorkspacePrivacyTest extends InjuryWorkspaceReadTestCase
{
    public function test_factual_reader_gets_bounded_medical_shape_without_private_names_counts_or_sql(): void
    {
        $viewer = $this->staff(['hazards.view']);
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create(['workplace_injury_id' => $this->injury->id,
            'worker_id' => $this->worker->id, 'medical_clearance_notes' => 'Secret medical certification']));
        WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'assessment_summary' => 'Secret assessment narrative']));
        WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id, 'original_name' => 'private-certificate.pdf']);
        $this->version($this->injury, 'injury');
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $dto = $this->detail($viewer);
            $this->assertSame('restricted', $dto['medical_access']);
            $this->assertSame($this->worker->id, $dto['worker']['id']);
            $this->assertSame('Factual workplace occurrence', $dto['description']);
            foreach (['rtw_plans', 'capacity_assessments', 'attachments', 'history', 'audits', 'notes', 'immediate_treatment', 'acc_claim_number'] as $key) {
                $this->assertNull($dto[$key], $key);
            }
            foreach (['return_to_work_plans', 'modified_duties', 'work_capacity_assessments', 'workplace_injury_attachments', 'audit_logs'] as $privateTable) {
                foreach (DB::getQueryLog() as $entry) {
                    $this->assertStringNotContainsString($privateTable, strtolower($entry['query']));
                }
            }
            $this->assertSafeFactualProofSql(DB::getQueryLog());
            $this->assertStringNotContainsString('private-certificate', json_encode($dto));
            $this->assertStringNotContainsString('Secret', json_encode($dto));
            $this->assertFalse($dto['can']['recovery']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_downgraded_typed_child_never_invites_private_integrity_sql_for_a_factual_reader(): void
    {
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'medical_clearance_notes' => 'Retained typed medical note',
        ]));
        $this->version($plan, 'rtw_plan');
        DB::table('return_to_work_plans')->where('id', $plan->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $viewer = $this->staff(['hazards.view']);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $dto = $this->detail($viewer);
            $this->assertSame('restricted', $dto['medical_access']);
            $this->assertSame('unknown', $dto['recovery_integrity']);
            foreach (['rtw_plans', 'capacity_assessments', 'attachments', 'history', 'audits'] as $key) {
                $this->assertNull($dto[$key]);
            }
            foreach (DB::getQueryLog() as $query) {
                foreach (['return_to_work_plans', 'modified_duties', 'work_capacity_assessments',
                    'workplace_injury_attachments', 'audit_logs'] as $table) {
                    $this->assertStringNotContainsString($table, strtolower($query['query']));
                }
            }
            $this->assertSafeFactualProofSql(DB::getQueryLog());
            $this->assertStringNotContainsString('Retained typed medical note', json_encode($dto));
        } finally {
            DB::disableQueryLog();
        }
    }
    public function test_read_only_wellbeing_reader_retains_full_private_detail_without_mutation_flags(): void
    {
        $viewer = $this->staff(['hr.wellbeing.view']);
        $dto = $this->detail($viewer);
        $this->assertSame('readable', $dto['medical_access']);
        $this->assertSame('Private clinical treatment', $dto['immediate_treatment']);
        $this->assertSame('Specialist', $dto['medical_treatment_label']);
        $this->assertSame('PRIVATE-ACC-123', $dto['acc_claim_number']);
        $this->assertSame('Private recovery note', $dto['notes']);
        $this->assertSame([], $dto['rtw_plans']);
        $this->assertFalse($dto['can']['manage']);
        $this->assertFalse($dto['can']['recovery']);
        $this->assertTrue($dto['can']['read_evidence']);
        $this->assertSame([], $dto['commands']['transitions']);
    }

    public function test_hs_manager_does_not_receive_medical_authority_from_management_grant(): void
    {
        $viewer = $this->staff(['hazards.view', 'hazards.manage']);
        $dto = $this->detail($viewer);
        $this->assertTrue($dto['can']['manage']);
        $this->assertTrue($dto['can']['transition']);
        $this->assertFalse($dto['can']['recovery']);
        $this->assertFalse($dto['can']['upload_evidence']);
        $this->assertFalse($dto['can']['record_medical_clearance']);
        $this->assertNull($dto['capacity_assessments']);
    }

    public function test_reused_viewer_and_workspace_recheck_medical_revocation_without_loaded_relation_fallback(): void
    {
        $this->viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $service = app(InjuryWorkspaceService::class);
        $this->assertSame('Private recovery note', $service->detail($this->viewer, $this->injury->id)['notes']);
        $this->revoke($this->viewer, 'hr.wellbeing.view');
        $this->assertTrue($this->viewer->canDo('hr.wellbeing.view'));
        $dto = $service->detail($this->viewer, $this->injury->id);
        $this->assertSame('restricted', $dto['medical_access']);
        $this->assertNull($dto['notes']);
        $this->assertNull($dto['history']);
        $this->grant($this->viewer, 'hr.wellbeing.view');
        $this->assertSame('Private recovery note', $service->detail($this->viewer, $this->injury->id)['notes']);
    }

    public function test_fresh_management_revocation_preserves_read_and_removes_command_capabilities(): void
    {
        $this->grant($this->viewer, 'hazards.manage');
        $this->viewer->load('permissionOverrides', 'roles.permissions');
        $this->assertTrue($this->detail()['can']['recovery']);
        $this->revoke($this->viewer, 'hazards.manage');
        $dto = $this->detail();
        $this->assertSame('readable', $dto['medical_access']);
        $this->assertFalse($dto['can']['manage']);
        $this->assertFalse($dto['can']['assign']);
        $this->assertFalse($dto['can']['recovery']);
        $this->assertSame([], $dto['commands']['transitions']);
    }

    public function test_current_role_revoke_cannot_be_replaced_by_historical_recorder_or_worker_identity(): void
    {
        $this->revoke($this->viewer, 'hazards.view');
        $this->revoke($this->viewer, 'hr.wellbeing.view');
        $this->denied(fn () => $this->detail(), 403);
        $this->denied(fn () => $this->detail($this->worker), 403);
    }

    public function test_foreign_recorded_site_is_concealed_even_with_wellbeing_permission(): void
    {
        $this->injury->updateQuietly(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->denied(fn () => $this->detail(), 404);
    }

    public function test_current_hr_withdrawal_denies_previously_readable_source(): void
    {
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides', 'roles.permissions');
        $this->assertSame('readable', $this->detail()['medical_access']);
        $this->viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->denied(fn () => $this->detail(), 403);
    }

    public function test_current_site_withdrawal_denies_reused_source_read(): void
    {
        $this->viewer->load('hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->denied(fn () => $this->detail(), 404);
    }

    public function test_historical_injured_worker_offboarding_does_not_delete_authorised_recovery_facts(): void
    {
        $this->worker->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => now()->subDay()->toDateString()]);
        $dto = $this->detail();
        $this->assertSame($this->worker->id, $dto['worker']['id']);
        $this->assertSame('Private recovery note', $dto['notes']);
        $this->assertFalse($dto['can']['declare_fit']);
    }

    public function test_evidence_projects_actual_availability_without_exposing_storage_paths(): void
    {
        $attachment = WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'disk' => 'private', 'path' => 'workplace_injury_attachments/'.$this->injury->id.'/certificate.pdf',
            'original_name' => 'actual-certificate.pdf', 'uploaded_by' => $this->viewer->id]);
        $dto = $this->detail();
        $this->assertSame('unavailable', $dto['attachments'][0]['availability']);
        $this->assertNull($dto['attachments'][0]['url']);
        Storage::disk('private')->put($attachment->path, 'actual evidence');
        $dto = $this->detail();
        $this->assertSame('available', $dto['attachments'][0]['availability']);
        $this->assertSame('/health-safety/injuries/'.$this->injury->id.'/attachments/'.$attachment->id.'/download', $dto['attachments'][0]['url']);
        $this->assertArrayNotHasKey('path', $dto['attachments'][0]);
        $this->assertArrayNotHasKey('disk', $dto['attachments'][0]);
    }

    public function test_private_audit_projection_never_returns_raw_receipt_or_copied_payload(): void
    {
        AuditLog::create(['user_id' => $this->viewer->id, 'auditable_type' => $this->injury->getMorphClass(),
            'auditable_id' => $this->injury->id, 'action' => 'updated', 'meta' => ['fields' => ['notes', 'source_creation_key'],
                'idempotency_key' => 'SECRET-UUID', 'result' => ['raw_medical_blob' => 'Unshaped private payload']]]);
        $dto = $this->detail();
        $this->assertSame(['notes'], $dto['audits'][0]['fields']);
        $this->assertStringNotContainsString('SECRET-UUID', json_encode($dto));
        $this->assertStringNotContainsString('Unshaped private payload', json_encode($dto));
    }
    public function test_typed_evidence_names_exact_version_and_download_requires_the_same_bytes(): void
    {
        [$attachment, $version] = $this->typedEvidence();
        $dto = $this->detail();
        $this->assertSame('valid', $dto['evidence_integrity']);
        $this->assertSame('recorded', $dto['attachments'][0]['attribution_state']);
        $this->assertSame($version->id, $dto['attachments'][0]['source_version_id']);
        $this->assertSame(1, $dto['attachments'][0]['source_revision']);
        $this->assertSame('available', $dto['attachments'][0]['availability']);
        $download = app(InjuryAccessService::class)->attachmentForDownload($this->injury, $attachment, $this->viewer);
        $this->assertSame($attachment->path, $download['path']);
        Storage::disk('private')->put($attachment->path, 'different bytes');
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['evidence_integrity']);
        $this->assertSame([], $dto['attachments']);
        $this->denied(fn () => app(InjuryAccessService::class)->attachmentForDownload($this->injury, $attachment, $this->viewer), 404);
        $this->assertSame($version->content_digest, $version->fresh()->content_digest);
    }

    public function test_typed_evidence_external_record_mutation_is_not_projected_as_current_private_filename(): void
    {
        [$attachment] = $this->typedEvidence();
        $attachment->updateQuietly(['original_name' => 'Unaccepted external medical filename.pdf']);
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['evidence_integrity']);
        $this->assertSame([], $dto['attachments']);
        $this->assertStringNotContainsString('Unaccepted external medical filename', json_encode($dto));
        $this->denied(fn () => app(InjuryAccessService::class)->attachmentForDownload($this->injury, $attachment, $this->viewer), 404);
    }

    public function test_typed_evidence_missing_acceptance_never_downgrades_to_available_legacy_file(): void
    {
        $attachment = WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'source_schema_version' => 1, 'source_revision' => 4, 'disk' => 'private',
            'path' => 'workplace_injury_attachments/'.$this->injury->id.'/unaccepted.pdf',
            'original_name' => 'Unaccepted typed certificate.pdf']);
        Storage::disk('private')->put($attachment->path, 'existing but unaccepted');
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['evidence_integrity']);
        $this->assertSame([], $dto['attachments']);
        $this->assertStringNotContainsString('Unaccepted typed certificate', json_encode($dto));
        $this->denied(fn () => app(InjuryAccessService::class)->attachmentForDownload($this->injury, $attachment, $this->viewer), 404);
    }

    public function test_site_corrected_audit_requires_original_authority_and_exact_version_association(): void
    {
        $key = (string) Str::uuid();
        $version = $this->version($this->injury, 'injury', 1, ['command' => 'update', 'idempotency_key' => $key]);
        $result = ['accepted' => true, 'injury_id' => $this->injury->id, 'actor_id' => $this->viewer->id,
            'source_type' => 'injury', 'source_id' => $this->injury->id, 'command' => 'update',
            'source_version_id' => $version->id, 'version' => 1, 'idempotency_key' => $key];
        $audit = AuditLog::create(['user_id' => $this->viewer->id, 'auditable_type' => $this->injury->getMorphClass(),
            'auditable_id' => $this->injury->id, 'action' => 'health_safety.injury.update',
            'meta' => ['source_version_id' => $version->id, 'actor_id' => $this->viewer->id,
                'idempotency_key' => $key, 'result' => $result, 'fields' => ['notes']]]);
        $legacy = AuditLog::create(['user_id' => $this->viewer->id, 'auditable_type' => $this->injury->getMorphClass(),
            'auditable_id' => $this->injury->id, 'action' => 'updated', 'meta' => ['fields' => ['notes']]]);
        $old = $this->injury->fresh()->attributesToArray();
        $newSite = Site::factory()->create(['is_active' => true]);
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$newSite->id]]);
        $this->injury->updateQuietly(['site_id' => $newSite->id, 'source_revision' => 2]);
        $this->version($this->injury, 'injury', 2, ['site_id' => $newSite->id, 'content' => ['before' => $old]]);
        $byId = collect($this->detail()['audits'])->keyBy('id');
        $this->assertSame('readable', $byId[$audit->id]['access']);
        $this->assertSame(['notes'], $byId[$audit->id]['fields']);
        $this->assertSame('restricted', $byId[$legacy->id]['access']);
        $this->assertNull($byId[$legacy->id]['actor']);
        $this->assertSame([], $byId[$legacy->id]['fields']);
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $newSite->id, 'secondary_site_ids' => []]);
        $byId = collect($this->detail()['audits'])->keyBy('id');
        $this->assertSame('health_safety.injury.update', $byId[$audit->id]['action']);
        $this->assertNotNull($byId[$audit->id]['at']);
        $this->assertSame('restricted', $byId[$audit->id]['access']);
        $this->assertNull($byId[$audit->id]['actor']);
        $this->assertSame([], $byId[$audit->id]['fields']);
    }

    public function test_audit_cannot_borrow_a_different_command_actor_or_source_result_from_valid_version(): void
    {
        $version = $this->version($this->injury, 'injury', 1, ['command' => 'update', 'idempotency_key' => (string) Str::uuid()]);
        AuditLog::create(['user_id' => $this->viewer->id, 'auditable_type' => $this->injury->getMorphClass(),
            'auditable_id' => $this->injury->id, 'action' => 'health_safety.injury.transition',
            'meta' => ['source_version_id' => $version->id, 'fields' => ['notes'], 'actor_id' => $this->viewer->id,
                'idempotency_key' => $version->idempotency_key, 'result' => ['accepted' => true, 'injury_id' => $this->injury->id + 1]]]);
        $dto = $this->detail();
        $this->assertSame('restricted', $dto['audits'][0]['access']);
        $this->assertSame([], $dto['audits'][0]['fields']);
        $this->assertNull($dto['audits'][0]['actor']);
        $this->assertSame('health_safety.injury.transition', $dto['audits'][0]['action']);
    }

    private function typedEvidence(): array
    {
        $bytes = 'original private evidence';
        $attachment = WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'source_schema_version' => 1, 'source_revision' => 1, 'disk' => 'private',
            'path' => 'workplace_injury_attachments/'.$this->injury->id.'/recorded.pdf',
            'original_name' => 'recorded-certificate.pdf', 'size' => strlen($bytes), 'uploaded_by' => $this->viewer->id]);
        Storage::disk('private')->put($attachment->path, $bytes);
        $version = $this->version($attachment, 'attachment', 1, ['command' => 'upload_evidence',
            'content' => ['command_facts' => ['file_evidence' => ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
                'original_name' => $attachment->original_name, 'mime' => $attachment->mime]]]]);
        return [$attachment, $version];
    }
    public function test_parent_marker_downgrade_preserves_occurrence_but_withholds_every_unconfirmed_private_read_and_command(): void
    {
        $this->grant($this->viewer, 'hazards.manage');
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
        ]));
        $version = $this->version($plan, 'rtw_plan');
        $before = $this->detail();
        $this->assertSame('valid', $before['source_integrity']);
        $this->assertSame($version->id, $before['rtw_plans'][0]['source_version_id']);
        $this->assertTrue($before['can']['update']);
        DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $dto = $this->detail();
            $this->assertSame('repair_required', $dto['source_integrity']);
            $this->assertSame('Factual workplace occurrence', $dto['description']);
            $this->assertSame('readable', $dto['medical_access']);
            foreach (['rtw_plans', 'capacity_assessments', 'attachments', 'history', 'audits', 'notes', 'immediate_treatment', 'acc_claim_number'] as $key) {
                $this->assertNull($dto[$key], $key);
            }
            foreach (['manage', 'update', 'transition', 'assign', 'review', 'recovery', 'upload_evidence', 'remove_evidence', 'read_evidence', 'record_medical_clearance'] as $key) {
                $this->assertFalse($dto['can'][$key], $key);
            }
            $this->assertTrue($dto['can']['create']);
            $this->assertSame([], $dto['commands']['transitions']);
            $this->assertSame('repair_required', $dto['recovery_integrity']);
            foreach (DB::getQueryLog() as $entry) {
                foreach (['return_to_work_plans', 'modified_duties', 'work_capacity_assessments', 'workplace_injury_attachments', 'audit_logs'] as $table) {
                    $this->assertStringNotContainsString($table, strtolower($entry['query']));
                }
            }
            $this->assertSafeFactualProofSql(DB::getQueryLog());
        } finally { DB::disableQueryLog(); }
    }

    public function test_genuine_legacy_parent_stays_unknown_and_keeps_authorised_private_facts_and_current_commands(): void
    {
        $this->grant($this->viewer, 'hazards.manage');
        $dto = $this->detail();
        $this->assertSame('unknown', $dto['source_integrity']);
        $this->assertSame('Private recovery note', $dto['notes']);
        $this->assertTrue($dto['can']['update']);
        $this->assertTrue($dto['can']['recovery']);
        $this->revoke($this->viewer, 'hr.wellbeing.view');
        $restricted = $this->detail();
        $this->assertSame('unknown', $restricted['source_integrity']);
        $this->assertNull($restricted['notes']);
        $this->assertTrue($restricted['can']['update']);
        $this->assertFalse($restricted['can']['recovery']);
    }

    private function assertSafeFactualProofSql(array $queries): void
    {
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?:content|content_digest)(?![a-z_])/i', $sql);
            if (str_contains($sql, 'workplace_injury_source_versions')) {
                $this->assertDoesNotMatchRegularExpression('/^\s*select\s+(?:[`"]?workplace_injury_source_versions[`"]?\.)?\*/i', $sql);
            }
        }
    }
}
