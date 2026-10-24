<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SafetyDataSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Physical commits are intentional; execution requires the sole runner's admitted MySQL test schema. */
final class ChemicalExposureEvidenceTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function file(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('provided-evidence.pdf', "%PDF-1.4\nActual provided private bytes".$suffix."\n%%EOF");
    }
    private function upload($root, ?string $key = null, array $facts = []): array
    {
        return $this->evidenceService()->upload($root, $this->manager, $this->file(),
            $this->privateCommand($root, ['description' => 'Actual provided evidence', ...$facts], $key));
    }
    private function failed($root): ChemicalExposureEvidenceStaging
    {
        $key = (string) Str::uuid(); AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.upload_evidence' ? false : null);
        try { $this->integrity(fn () => $this->upload($root, $key)); }
        finally { AuditLog::flushEventListeners(); }
        $intent = ChemicalCommandIntent::where('idempotency_key', $key)->sole();
        return ChemicalExposureEvidenceStaging::where('intent_id', $intent->id)->sole();
    }
    private function withDiskFailure(string $method, mixed $response, callable $operation): mixed
    {
        $manager = Storage::getFacadeRoot(); $disk = \Mockery::mock($manager->disk('private'))->makePartial();
        $disk->shouldReceive($method)->andReturn($response);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        try { return $operation(); } finally { Storage::swap($manager); }
    }

    public function test_reservation_physically_commits_before_bytes_and_acceptance_binds_original_intent_and_digest(): void
    {
        $root = $this->exposure(); $beforeBytes = false; $committed = false;
        config(['database.connections.exposure_durability_test' => DB::connection()->getConfig()]); DB::purge('exposure_durability_test');
        ChemicalExposureEvidenceStaging::created(function ($row) use (&$beforeBytes) {
            $beforeBytes = DB::transactionLevel() > 0 && ! Storage::disk('private')->exists($row->path);
        });
        ChemicalExposureEvidenceStaging::updating(function ($row) use (&$committed) {
            if ($row->state === 'stored') {
                $committed = DB::connection('exposure_durability_test')->table('chemical_exposure_evidence_staging')
                    ->where('id', $row->id)->where('state', 'staged')->exists();
            }
        });
        try { $r = $this->upload($root); }
        finally { ChemicalExposureEvidenceStaging::flushEventListeners(); DB::purge('exposure_durability_test'); }
        $file = ChemicalExposureEvidence::findOrFail($r['source_id']); $stage = ChemicalExposureEvidenceStaging::where('evidence_id', $file->id)->sole();
        $this->assertTrue($beforeBytes); $this->assertTrue($committed); $this->assertSame('accepted', $stage->state);
        $this->assertSame(2, $r['version']); $this->assertSame(hash('sha256', Storage::disk('private')->get($file->path)), $file->sha256);
        $this->assertSame(strlen(Storage::disk('private')->get($file->path)), $file->size);
        $this->assertSame($r, $this->exposureService()->confirmationForKey($this->manager, strtoupper($r['idempotency_key']), $root->id));
        foreach (['path', 'description', 'original_name', 'sha256', 'size'] as $key) { $this->assertArrayNotHasKey($key, $r); }
    }

    public function test_upload_outer_transaction_refuses_before_intent_stage_or_file(): void
    {
        $root = $this->exposure(); $intents = ChemicalCommandIntent::count(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->upload($root)); }
        finally { DB::rollBack(); }
        $this->assertSame($intents, ChemicalCommandIntent::count()); $this->assertSame(0, ChemicalExposureEvidenceStaging::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_rejected_audit_keeps_durable_unaccepted_bytes_and_original_uuid_for_exact_retry(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $input = $stage->command_values;
        $this->assertSame('cleanup_pending', $stage->state); $this->assertNull($stage->receipt_id); Storage::disk('private')->assertExists($stage->path);
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureEvidence::count());
        $this->assertNull($this->exposureService()->confirmationForKey($this->manager, $input['idempotency_key'], $root->id));
        unset($input['file_evidence']); $r = $this->evidenceService()->upload($root, $this->manager, $this->file(), $input);
        $this->assertSame($stage->path, ChemicalExposureEvidence::findOrFail($r['source_id'])->path);
        $this->assertSame('accepted', $stage->fresh()->state); $this->assertSame(1, ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_false_store_retains_discoverable_reservation_without_source_acceptance(): void
    {
        $root = $this->exposure();
        $this->withDiskFailure('putFileAs', false, fn () => $this->integrity(fn () => $this->upload($root)));
        $stage = ChemicalExposureEvidenceStaging::sole(); $this->assertNull($stage->receipt_id);
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureEvidence::count());
        $this->assertFalse($this->evidenceService()->inspectStaging($this->manager, $stage->id)['accepted']);
    }

    public function test_false_read_preserves_unaccepted_attempt_and_does_not_certify_unreadable_bytes(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        $this->withDiskFailure('readStream', false, function () use ($root, $stage) {
            $input = $stage->command_values; unset($input['file_evidence']);
            $this->integrity(fn () => $this->evidenceService()->upload($root, $this->manager, $this->file(), $input));
        });
        $this->assertNull($stage->fresh()->receipt_id); Storage::disk('private')->assertExists($stage->path);
    }

    public function test_acceptance_binding_veto_rolls_back_revision_file_row_receipt_and_keeps_committed_stage(): void
    {
        $root = $this->exposure(); ChemicalExposureEvidenceStaging::updating(fn ($row) => $row->state === 'accepted' ? false : null);
        try { $this->integrity(fn () => $this->upload($root)); }
        finally { ChemicalExposureEvidenceStaging::flushEventListeners(); }
        $stage = ChemicalExposureEvidenceStaging::sole(); $this->assertSame('cleanup_pending', $stage->state);
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureEvidence::count());
        $this->assertNull($stage->receipt_id); Storage::disk('private')->assertExists($stage->path);
        $this->assertSame(1, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_crash_style_stored_attempt_is_inspectable_but_never_accepts_without_exact_command_receipt(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        DB::table('chemical_exposure_evidence_staging')->where('id', $stage->id)->update(['state' => 'stored']);
        $dto = $this->evidenceService()->inspectStaging($this->manager, $stage->id);
        $this->assertSame('stored', $dto['state']); $this->assertFalse($dto['accepted']); $this->assertTrue($dto['can_retry']);
        foreach (['path', 'command_values', 'idempotency_key', 'original_name', 'file_evidence'] as $key) { $this->assertArrayNotHasKey($key, $dto); }
        $this->assertSame('cleaned', $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id)['state']);
        Storage::disk('private')->assertMissing($stage->path);
    }

    public function test_staging_cleanup_does_not_delete_accepted_evidence_or_arbitrary_changed_bytes(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        $accepted = ChemicalExposureEvidenceStaging::where('evidence_id', $file->id)->sole();
        $this->integrity(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $accepted->id));
        Storage::disk('private')->assertExists($file->path);
        $stage = $this->failed($root); Storage::disk('private')->put($stage->path, 'Other bytes');
        $this->assertSame('cleanup_pending', $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id)['state']);
        $this->assertSame('Other bytes', Storage::disk('private')->get($stage->path));
    }

    public function test_original_stage_identity_digest_claim_or_path_tampering_refuses_cleanup_and_acceptance(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $original = $stage->path;
        DB::table('chemical_exposure_evidence_staging')->where('id', $stage->id)->update(['reservation_digest' => str_repeat('0', 64)]);
        $this->integrity(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id));
        $this->integrity(fn () => $this->evidenceService()->bindAcceptedStage($this->manager, $stage->id));
        Storage::disk('private')->assertExists($original); $this->assertSame(1, $root->fresh()->source_revision);
    }

    public function test_retained_hr_private_and_softdeleted_ppe_pointers_block_staging_purge_with_identity_only_sql(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        $doc = HrDocument::factory()->create(['employee_profile_id' => $this->manager->hrEmployeeProfile->id,
            'storage_path' => $stage->path, 'storage_disk' => 'local']);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id));
        $this->assertSame('cleanup_pending', $dto['state']); Storage::disk('private')->assertExists($stage->path);
        $hr = array_filter($queries, fn ($q) => str_contains($q['query'], 'from `hr_documents`'));
        // An EXISTS probe's "select *" returns no columns; any real wide select of HR documents still fails.
        $this->assertNotEmpty($hr); foreach ($hr as $q) { $this->assertStringNotContainsString('select *', (string) preg_replace('/exists\s*\(\s*select \*/', 'exists (select 1', strtolower($q['query']))); }
        $this->assertNotNull($doc->id);
        $another = $this->failed($root); $item = \App\Models\PpeInventory::factory()->create(['site_id' => $this->site->id]);
        \App\Models\PpeAttachment::create(['ppe_inventory_id' => $item->id, 'uploaded_by' => $this->manager->id,
            'disk' => 'local', 'path' => $another->path, 'original_name' => 'Retained pointer.pdf', 'mime' => 'application/pdf',
            'size' => $another->file_evidence['size'], 'deleted_at' => now()]);
        $this->assertSame('cleanup_pending', $this->evidenceService()->retryStagingCleanup($this->manager, $another->id)['state']);
        Storage::disk('private')->assertExists($another->path);
    }

    public function test_retained_chemical_history_and_current_sds_path_prevents_private_staging_purge(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->hazardous_substance_id, 'document_path' => $stage->path]);
        $this->assertSame('cleanup_pending', $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id)['state']);
        Storage::disk('private')->assertExists($stage->path); $this->assertNotNull($sheet->id);
    }

    public function test_current_actor_private_site_and_staff_authority_is_rechecked_for_inspect_retry_and_discovery(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $this->manager->load('permissionOverrides');
        foreach (['hazards.view', 'hazards.manage', 'hr.wellbeing.view'] as $key) {
            $this->allow($this->manager, $key, false);
            $this->denial(fn () => $this->evidenceService()->inspectStaging($this->manager, $stage->id));
            $this->denial(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id));
            $this->denial(fn () => $this->evidenceService()->stagingAttemptsForViewer($this->manager));
            $this->allow($this->manager, $key);
        }
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->denial(fn () => $this->evidenceService()->inspectStaging($other, $stage->id), 404);
        $this->assertSame([], $this->evidenceService()->stagingAttemptsForViewer($other)['attempts']);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id), 404);
        Storage::disk('private')->assertExists($stage->path);
    }

    public function test_stale_failed_upload_preserves_original_intent_and_refuses_changed_payload_or_other_verb(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $input = $stage->command_values; unset($input['file_evidence']);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'More actual source facts', 'circumstances' => 'Updated source']));
        $this->validation(fn () => $this->evidenceService()->upload($root, $this->manager, $this->file(), $input), 'expected_version');
        $input['description'] = 'Changed intended evidence';
        $this->validation(fn () => $this->evidenceService()->upload($root, $this->manager, $this->file(), $input), 'idempotency_key');
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Cannot reuse file intent'], $stage->command_values['idempotency_key'])), 'idempotency_key');
        $this->assertNull($stage->fresh()->receipt_id); Storage::disk('private')->assertExists($stage->path);
    }

    public function test_download_descriptor_comes_from_same_verified_bytes_and_rejects_wrong_parent_tamper_and_missing_bytes(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        $proof = $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id);
        $this->assertSame(['sha256' => $file->sha256, 'size' => $file->size], $proof['byte_proof']);
        $foreign = $this->exposure(); $hydrated = false;
        ChemicalExposureEvidence::retrieved(function ($row) use ($file, &$hydrated) { if ($row->id === $file->id) { $hydrated = true; } });
        try { $this->denial(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $foreign->id, $file->id), 404); }
        finally { ChemicalExposureEvidence::flushEventListeners(); }
        $this->assertFalse($hydrated);
        Storage::disk('private')->put($file->path, 'Tampered evidence');
        $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
        Storage::disk('private')->delete($file->path);
        $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
    }

    public function test_removal_purges_after_actual_root_commit_and_confirms_record_removal_only(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        DB::beginTransaction();
        try {
            $removed = $this->evidenceService()->remove($root, $file->id, $this->manager,
                $this->privateCommand($root, ['reason' => 'Duplicate attachment removal']));
            $this->assertSame(3, $removed['version']); Storage::disk('private')->assertExists($file->path);
            $this->assertSame('pending', ChemicalExposureEvidenceCleanup::sole()->state); DB::commit();
        } catch (\Throwable $e) { if (DB::transactionLevel() > 0) { DB::rollBack(); } throw $e; }
        Storage::disk('private')->assertMissing($file->path); $this->assertSame('complete', ChemicalExposureEvidenceCleanup::sole()->state);
        $this->assertNull(ChemicalExposureEvidence::find($file->id)); $this->assertTrue(ChemicalExposureEvidence::withTrashed()->findOrFail($file->id)->trashed());
        $this->assertSame($removed, $this->exposureService()->confirmationForKey($this->manager, $removed['idempotency_key'], $root->id));
        $this->assertArrayNotHasKey('purged', $removed); $this->assertArrayNotHasKey('cleanup_id', $removed);
    }

    public function test_removal_rollback_retains_active_evidence_and_bytes_with_no_cleanup_acceptance(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        DB::beginTransaction();
        try { $this->evidenceService()->remove($root, $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'Rolled back intent'])); }
        finally { DB::rollBack(); }
        $this->assertNotNull(ChemicalExposureEvidence::find($file->id)); $this->assertSame(0, ChemicalExposureEvidenceCleanup::count());
        $this->assertSame(2, $root->fresh()->source_revision); Storage::disk('private')->assertExists($file->path);
    }

    public function test_false_purge_retains_pending_cleanup_and_exact_retry_does_not_advance_source_again(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        $removed = $this->withDiskFailure('delete', false, fn () => $this->evidenceService()->remove($root, $file->id, $this->manager,
            $this->privateCommand($root, ['reason' => 'Actual removed record'])));
        $cleanup = ChemicalExposureEvidenceCleanup::sole(); $this->assertSame('pending', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        $dto = $this->evidenceService()->cleanupAttemptsForViewer($this->manager)['attempts'][0];
        $this->assertSame($cleanup->id, $dto['id']); $this->assertTrue($dto['removed_from_record']);
        foreach (['path', 'sha256', 'original_name', 'receipt_id', 'idempotency_key'] as $key) { $this->assertArrayNotHasKey($key, $dto); }
        $this->assertSame('complete', $this->evidenceService()->retryRemovalCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame(3, $root->fresh()->source_revision); Storage::disk('private')->assertMissing($file->path);
        $this->assertSame($removed, $this->exposureService()->confirmationForKey($this->manager, $removed['idempotency_key'], $root->id));
    }

    public function test_retained_other_source_path_blocks_removal_cleanup_without_deleting_shared_bytes(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        HrDocument::factory()->create(['employee_profile_id' => $this->manager->hrEmployeeProfile->id,
            'storage_path' => $file->path, 'storage_disk' => 'private']);
        $this->evidenceService()->remove($root, $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'Remove active pointer only']));
        $cleanup = ChemicalExposureEvidenceCleanup::sole();
        $this->assertSame('pending', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        $this->assertSame('pending', $this->evidenceService()->retryRemovalCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame(3, $root->fresh()->source_revision);
    }

    public function test_foreign_evidence_removal_is_concealed_before_any_private_metadata_hydration(): void
    {
        $root = $this->exposure(); $other = $this->exposure(); $r = $this->upload($root); $hydrated = false;
        ChemicalExposureEvidence::retrieved(function ($row) use ($r, &$hydrated) { if ($row->id === $r['source_id']) { $hydrated = true; } });
        try { $this->denial(fn () => $this->evidenceService()->remove($other, $r['source_id'], $this->manager,
            $this->privateCommand($other, ['reason' => 'Wrong parent'])), 404); }
        finally { ChemicalExposureEvidence::flushEventListeners(); }
        $this->assertFalse($hydrated); $this->assertSame(1, $other->fresh()->source_revision);
    }

    public function test_missing_source_version_downgrade_or_audit_corruption_never_allows_evidence_download(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['source_schema_version' => 1, 'source_revision' => 2]);
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['action' => 'chemical.exposure.unknown']);
        $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
        Storage::disk('private')->assertExists($file->path);
    }

    public function test_blank_removal_reason_and_cleanup_save_veto_preserve_original_active_record_and_bytes(): void
    {
        $root = $this->exposure(); $r = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        $this->validation(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager,
            $this->privateCommand($root, ['reason' => ' '])), 'reason');
        ChemicalExposureEvidenceCleanup::creating(fn () => false);
        try { $this->integrity(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager,
            $this->privateCommand($root, ['reason' => 'Vetoed cleanup ledger']))); }
        finally { ChemicalExposureEvidenceCleanup::flushEventListeners(); }
        $this->assertNotNull(ChemicalExposureEvidence::find($file->id)); $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame(0, ChemicalExposureEvidenceCleanup::count()); Storage::disk('private')->assertExists($file->path);
    }

    public function test_unresolved_stage_or_removed_governed_evidence_prevents_worker_site_rebinding(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $other = $this->staff([]);
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Change worker', 'user_id' => $other->id])), 'site_id');
        $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual worker correction', 'user_id' => $other->id]));
        $r = $this->upload($root); $this->evidenceService()->remove($root, $r['source_id'], $this->manager,
            $this->privateCommand($root, ['reason' => 'Retained removal']));
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Cannot erase retained history', 'user_id' => $this->worker->id])), 'site_id');
    }
    public function test_actor_recovery_discovery_does_not_hydrate_private_attempt_bodies_from_currently_foreign_root(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->foreignSite->id]);
        $loaded = false;
        ChemicalExposureEvidenceStaging::retrieved(function ($row) use (&$loaded) { if ($row->getRawOriginal('path') !== null) { $loaded = true; } });
        try {
            $this->assertSame(['attempts' => [], 'next_cursor' => null], $this->evidenceService()->stagingAttemptsForViewer($this->manager));
            $this->denial(fn () => $this->evidenceService()->inspectStaging($this->manager, $stage->id), 404);
        } finally { ChemicalExposureEvidenceStaging::flushEventListeners(); }
        $this->assertFalse($loaded); Storage::disk('private')->assertExists($stage->path);
    }

    public function test_factual_removal_retains_actual_microsecond_timestamp_in_immutable_tombstone_before_physical_cleanup(): void
    {
        $root = $this->exposure(); $upload = $this->upload($root);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T01:00:00.123456Z')->utc());
        $remove = $this->evidenceService()->remove($root, $upload['source_id'], $this->manager,
            $this->privateCommand($root, ['reason' => 'Actual removal at recorded time']));
        $file = ChemicalExposureEvidence::withTrashed()->findOrFail($upload['source_id']);
        $version = ChemicalExposureSourceVersion::findOrFail($remove['source_version_id']);
        $this->assertSame('2026-10-12 01:00:00.123456', $file->deleted_at->copy()->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame($file->attributesToArray()['deleted_at'], $version->content['source_after']['deleted_at']);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }
    public function test_staged_original_client_access_is_required_before_private_attempt_or_uuid_body_after_unlink(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]);
        $root = $this->exposure(['related_incident_id' => $incident->id]); $stage = $this->failed($root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual association unlink', 'related_incident_id' => null]));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Current independent fact', 'circumstances' => 'Later current fact']));
        $this->allow($this->manager, 'clients.viewAny', false); $loaded = false;
        ChemicalExposureEvidenceStaging::retrieved(function ($row) use (&$loaded) { if ($row->getRawOriginal('path') !== null) { $loaded = true; } });
        try {
            $this->denial(fn () => $this->evidenceService()->inspectStaging($this->manager, $stage->id), 403);
            $this->denial(fn () => $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id), 403);
        } finally { ChemicalExposureEvidenceStaging::flushEventListeners(); }
        $this->assertFalse($loaded); Storage::disk('private')->assertExists($stage->path);
        $this->assertNull($stage->fresh()->receipt_id); $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }

    public function test_cleaned_stage_cannot_hydrate_historical_file_body_after_genuine_worker_site_rebinding(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        $this->assertSame('cleaned', $this->evidenceService()->retryStagingCleanup($this->manager, $stage->id)['state']);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $newWorker = $this->staff([], $this->foreignSite);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual canonical context correction', 'site_id' => $this->foreignSite->id, 'user_id' => $newWorker->id]));
        $loaded = false;
        ChemicalExposureEvidenceStaging::retrieved(function ($row) use (&$loaded) { if ($row->getRawOriginal('path') !== null) { $loaded = true; } });
        try { $this->integrity(fn () => $this->evidenceService()->inspectStaging($this->manager, $stage->id)); }
        finally { ChemicalExposureEvidenceStaging::flushEventListeners(); }
        $this->assertFalse($loaded); $this->assertNull($stage->fresh()->receipt_id);
        $this->assertSame('cleaned', $stage->fresh()->state); Storage::disk('private')->assertMissing($stage->path);
    }
}
