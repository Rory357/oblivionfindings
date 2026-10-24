<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalEvidenceStaging;
use App\Models\ChemicalSourceVersion;
use App\Models\SafetyDataSheet;
use App\Services\HealthSafety\ChemicalAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** These physical-commit cases deliberately do not use the surrounding RefreshDatabase transaction. */
final class ChemicalSdsPhysicalTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function file(string $name = 'recorded-sds.pdf'): UploadedFile { return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat("recorded sheet\n", 280)."%%EOF\n"); }
    private function upload($root, array $facts = [], ?string $key = null): array
    {
        return $this->service()->uploadSds($root, $this->manager, $this->file(),
            $this->command($root, ['version' => 'Provided 1', 'issue_date' => '2026-10-01', 'review_date' => '2027-10-01', ...$facts], $key));
    }
    private function failed($root): ChemicalEvidenceStaging
    {
        $key = (string) Str::uuid(); AuditLog::creating(fn ($row) => $row->action === 'chemical.upload_sds' ? false : null);
        try { $this->integrity(fn () => $this->upload($root, [], $key)); }
        finally { AuditLog::flushEventListeners(); }
        return ChemicalEvidenceStaging::where('idempotency_key', $key)->sole();
    }

    public function test_committed_reservation_precedes_bytes_and_acceptance_binds_actual_digest_and_current_pointer(): void
    {
        $root = $this->catalogue(); $sawReservedWithoutBytes = false; $sawPhysicalCommittedReservation = false;
        config(['database.connections.chemical_durable' => DB::connection()->getConfig()]); DB::purge('chemical_durable');
        ChemicalEvidenceStaging::created(function ($row) use (&$sawReservedWithoutBytes) {
            $sawReservedWithoutBytes = DB::transactionLevel() > 0 && ! Storage::disk('private')->exists($row->path);
        });
        ChemicalEvidenceStaging::updating(function ($row) use (&$sawPhysicalCommittedReservation) {
            if ($row->state === 'stored') {
                $sawPhysicalCommittedReservation = DB::connection('chemical_durable')->table('chemical_evidence_staging')
                    ->where('id', $row->id)->where('state', 'reserved')->exists();
            }
        });
        try { $r = $this->upload($root, ['supplier_name' => 'Recorded supplier', 'supplier_contact' => 'Recorded contact']); }
        finally { ChemicalEvidenceStaging::flushEventListeners(); DB::purge('chemical_durable'); }
        $sheet = SafetyDataSheet::findOrFail($r['source_id']); $stage = ChemicalEvidenceStaging::where('idempotency_key', $r['idempotency_key'])->sole();
        $this->assertTrue($sawReservedWithoutBytes); $this->assertTrue($sawPhysicalCommittedReservation); $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('accepted', $stage->state); $this->assertNotNull($stage->receipt_id);
        $this->assertSame($sheet->id, $root->fresh()->current_sds_id); $this->assertSame(2, $r['version']);
        $this->assertSame('Recorded supplier', $sheet->supplier_name); $this->assertSame('Recorded contact', $sheet->supplier_contact);
        $this->assertSame(hash('sha256', Storage::disk('private')->get($sheet->document_path)), $sheet->document_sha256);
        $this->assertSame($r, $this->service()->recoverSds($root, $this->manager, strtoupper($r['idempotency_key'])));
        $this->assertStringNotContainsString($sheet->document_path, json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function test_upload_refuses_outer_transaction_before_reservation_or_bytes(): void
    {
        $root = $this->catalogue(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->upload($root)); }
        finally { DB::rollBack(); }
        $this->assertSame([], Storage::disk('private')->allFiles()); $this->assertSame(0, ChemicalEvidenceStaging::count());
    }

    public function test_rejected_audit_keeps_failed_durable_intent_bytes_and_original_current_sds(): void
    {
        $root = $this->catalogue(); $first = $this->upload($root); $failed = $this->failed($root);
        Storage::disk('private')->assertExists($failed->path); $this->assertSame('failed', $failed->state);
        $this->assertNull($failed->receipt_id); $this->assertSame($first['source_id'], $root->fresh()->current_sds_id);
        $this->assertSame('current', SafetyDataSheet::findOrFail($first['source_id'])->status);
        $this->assertSame(1, SafetyDataSheet::where('hazardous_substance_id', $root->id)->count());
        $this->assertNull($this->service()->recoverSds($root, $this->manager, $failed->idempotency_key));
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $failed->idempotency_key)->count());
    }

    public function test_exact_failed_upload_retry_reuses_verified_bytes_and_converges_to_one_receipt(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root); $r = $this->upload($root, [], $failed->idempotency_key);
        $this->assertSame($r, $this->service()->uploadSds($root, $this->manager, $this->file(),
            ['idempotency_key' => $failed->idempotency_key, 'expected_version' => 1, 'version' => 'Provided 1', 'issue_date' => '2026-10-01', 'review_date' => '2027-10-01']));
        $this->assertSame('accepted', $failed->fresh()->state); $this->assertSame(1, ChemicalCommandReceipt::where('idempotency_key', $failed->idempotency_key)->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_future_issued_upload_preserves_printed_metadata_without_superseding_and_set_current_refuses_until_effective(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles']); $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T01:00:00Z'));
        $root = $this->catalogue(); $first = $this->upload($root); $future = $this->upload($root, ['issue_date' => '2026-10-12']);
        $sheet = SafetyDataSheet::findOrFail($future['source_id']); $this->assertSame('not_yet_effective', $sheet->status);
        $this->assertSame('2026-10-12', $sheet->issue_date->toDateString()); $this->assertSame($first['source_id'], $root->fresh()->current_sds_id);
        $this->validation(fn () => $this->service()->setCurrentSds($root, $sheet, $this->manager, $this->command($root, ['reason' => 'Select recorded document'])), 'sds_id');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T08:00:00Z'));
        $this->service()->setCurrentSds($root, $sheet, $this->manager, $this->command($root, ['reason' => 'Now effective']));
        $this->assertSame($sheet->id, $root->fresh()->current_sds_id); $this->assertSame('superseded', SafetyDataSheet::findOrFail($first['source_id'])->status);
    }

    public function test_metadata_correction_history_can_clear_current_pointer_without_inventing_an_alternative(): void
    {
        $root = $this->catalogue(); $r = $this->upload($root); $sheet = SafetyDataSheet::findOrFail($r['source_id']); $before = $sheet->attributesToArray();
        $corrected = $this->service()->correctSdsMetadata($root, $sheet, $this->manager,
            $this->command($root, ['issue_date' => '2026-11-01', 'supplier_contact' => 'Corrected contact', 'reason' => 'Printed issue correction']));
        $this->assertNull($root->fresh()->current_sds_id); $this->assertSame('not_yet_effective', $sheet->fresh()->status);
        $version = ChemicalSourceVersion::findOrFail($corrected['source_version_id']);
        $this->assertSame($before['issue_date'], $version->content['source_before']['issue_date']);
        $this->assertSame('Corrected contact', $sheet->fresh()->supplier_contact);
    }

    public function test_download_proof_rejects_wrong_parent_tampered_bytes_and_missing_bytes_without_green_currency(): void
    {
        $root = $this->catalogue(); $r = $this->upload($root); $sheet = SafetyDataSheet::findOrFail($r['source_id']);
        $this->assertTrue($this->service()->downloadProof($root, $sheet, $this->manager)['available']);
        $this->denial(fn () => $this->service()->downloadProof($this->catalogue(), $sheet, $this->manager), 404);
        Storage::disk('private')->put($sheet->document_path, 'Wrong private bytes');
        $this->assertFalse($this->service()->downloadProof($root, $sheet, $this->manager)['available']);
        Storage::disk('private')->delete($sheet->document_path);
        $p = app(ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->service()->proofForCurrentReads($root, null, $current, $reads, $sites));
        $this->assertSame('unavailable', $p['current_sds']['state']); $this->assertSame('unknown', $p['current_sds']['currency']);
    }

    public function test_cleanup_deletes_only_unaccepted_exact_verified_bytes_and_never_accepted_document(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root);
        $this->assertTrue($this->service()->cleanupSds($root, $this->manager, $failed->idempotency_key));
        Storage::disk('private')->assertMissing($failed->path); $this->assertSame('cleaned', $failed->fresh()->state);
        $r = $this->upload($root); $accepted = ChemicalEvidenceStaging::where('idempotency_key', $r['idempotency_key'])->sole();
        $this->assertFalse($this->service()->cleanupSds($root, $this->manager, $r['idempotency_key']));
        Storage::disk('private')->assertExists($accepted->path);
    }

    public function test_retained_soft_deleted_sds_local_alias_prevents_failed_file_cleanup(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root);
        $legacy = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => $failed->path]);
        DB::table('safety_data_sheets')->where('id', $legacy->id)->update(['document_disk' => 'local', 'deleted_at' => now()]);
        $this->assertFalse($this->service()->cleanupSds($root, $this->manager, $failed->idempotency_key));
        Storage::disk('private')->assertExists($failed->path);
    }

    public function test_retained_hr_and_ppe_pointer_guards_are_identity_only_and_never_delete_a_shared_blob(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root);
        $doc = \App\Domain\Hr\Models\HrDocument::factory()->create(['employee_profile_id' => $this->manager->hrEmployeeProfile->id,
            'storage_path' => $failed->path, 'storage_disk' => 'local']);
        $this->assertFalse($this->service()->cleanupSds($root, $this->manager, $failed->idempotency_key));
        Storage::disk('private')->assertExists($failed->path);
        $this->assertNotNull($doc->id);
        $another = $this->failed($root); $item = \App\Models\PpeInventory::factory()->create(['site_id' => $this->site->id]);
        \App\Models\PpeAttachment::create(['ppe_inventory_id' => $item->id, 'uploaded_by' => $this->manager->id,
            'disk' => 'local', 'path' => $another->path, 'original_name' => 'Retained identity.pdf', 'mime' => 'application/pdf', 'size' => $another->size]);
        $this->assertFalse($this->service()->cleanupSds($root, $this->manager, $another->idempotency_key));
        Storage::disk('private')->assertExists($another->path);
    }

    public function test_staging_identity_mutation_cannot_bind_or_cleanup_an_arbitrary_path(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root); $original = $failed->path;
        Storage::disk('private')->put('retained-document.pdf', 'Retained foreign bytes');
        DB::table('chemical_evidence_staging')->where('id', $failed->id)->update(['path' => 'retained-document.pdf']);
        $this->integrity(fn () => $this->service()->cleanupSds($root, $this->manager, $failed->idempotency_key));
        $this->integrity(fn () => $this->upload($root, [], $failed->idempotency_key));
        Storage::disk('private')->assertExists($original); Storage::disk('private')->assertExists('retained-document.pdf');
    }

    public function test_private_exposure_retained_pointer_prevents_public_cleanup_without_private_record_discovery(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root);
        $exposure = \App\Models\SubstanceExposureRecord::factory()->createQuietly([
            'hazardous_substance_id' => $root->id, 'site_id' => $this->site->id, 'user_id' => $this->manager->id,
            'circumstances' => 'Retained private occurrence must not be discovered by public cleanup',
        ]);
        \App\Models\ChemicalExposureEvidence::create(['exposure_id' => $exposure->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'disk' => 'local', 'path' => $failed->path,
            'original_name' => 'Private retained evidence.pdf', 'mime' => 'application/pdf', 'size' => $failed->size,
            'sha256' => $failed->sha256, 'uploaded_by' => $this->manager->id, 'uploader_snapshot' => ['state' => 'legacy_unknown']]);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->assertFalse($this->service()->cleanupSds($root, $this->manager, $failed->idempotency_key));
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally { DB::disableQueryLog(); }
        Storage::disk('private')->assertExists($failed->path);
        $retention = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'from `chemical_exposure_evidence`')));
        $this->assertNotEmpty($retention);
        foreach ($retention as $sql) { $this->assertStringStartsWith('select `id` ', strtolower($sql)); }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('from `substance_exposure_records`', $sql);
            $this->assertStringNotContainsString('from `chemical_exposure_command_receipts`', $sql);
        }
        $this->assertNull($this->service()->recoverSds($root, $this->manager, $failed->idempotency_key));
    }

    public function test_warmed_capture_revoke_denies_upload_retry_recovery_and_bytes_never_echo_in_receipt(): void
    {
        $root = $this->catalogue(); $r = $this->upload($root); $this->manager->load('permissionOverrides');
        $this->allow($this->manager, 'hazards.manage', false); $this->allow($this->manager, 'hazards.create', false);
        $this->denial(fn () => $this->service()->recoverSds($root, $this->manager, $r['idempotency_key']));
        $this->allow($this->manager, 'hazards.manage'); $this->assertSame($r, $this->service()->recoverSds($root, $this->manager, $r['idempotency_key']));
        $this->assertArrayNotHasKey('document_path', $r); $this->assertArrayNotHasKey('supplier_contact', $r);
    }

    public function test_vetoed_physical_binding_rolls_back_sds_supersession_and_receipt_while_retaining_unaccepted_bytes(): void
    {
        $root = $this->catalogue(); $first = $this->upload($root); $key = (string) Str::uuid();
        ChemicalEvidenceStaging::updating(fn ($row) => $row->state === 'accepted' ? false : null);
        try { $this->integrity(fn () => $this->upload($root, [], $key)); }
        finally { ChemicalEvidenceStaging::flushEventListeners(); }
        $this->assertSame($first['source_id'], $root->fresh()->current_sds_id); $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame('current', SafetyDataSheet::findOrFail($first['source_id'])->status);
        $failed = ChemicalEvidenceStaging::where('idempotency_key', $key)->sole(); $this->assertSame('failed', $failed->state);
        Storage::disk('private')->assertExists($failed->path); $this->assertSame(0, ChemicalCommandReceipt::where('idempotency_key', $key)->count());
    }

    public function test_cleanup_final_reference_recheck_preserves_a_document_link_added_after_claim_commit(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root); $evidence = app(\App\Services\HealthSafety\ChemicalEvidenceStorage::class);
        $claim = app(ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads) => $evidence->prepareCleanup($failed, $reads));
        $this->assertNotNull($claim);
        SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => $failed->path]);
        $authority = fn () => app(ChemicalAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root) {
            app(ChemicalAccessService::class)->assertOperation($current, 'manage');
            return $this->service()->proofForCurrentReads($root, null, $current, $reads, $sites);
        });
        $this->integrity(fn () => $evidence->finishCleanup($claim, $authority));
        Storage::disk('private')->assertExists($failed->path); $this->assertSame('cleaning', $failed->fresh()->state);
    }

    public function test_cleanup_requires_fresh_management_after_claim_and_rejects_changed_bytes(): void
    {
        $root = $this->catalogue(); $failed = $this->failed($root); $evidence = app(\App\Services\HealthSafety\ChemicalEvidenceStorage::class);
        $claim = app(ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads) => $evidence->prepareCleanup($failed, $reads));
        $authority = fn () => app(ChemicalAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root) {
            app(ChemicalAccessService::class)->assertOperation($current, 'manage');
            return $this->service()->proofForCurrentReads($root, null, $current, $reads, $sites);
        });
        $this->allow($this->manager, 'hazards.manage', false); $this->denial(fn () => $evidence->finishCleanup($claim, $authority));
        $this->allow($this->manager, 'hazards.manage'); Storage::disk('private')->put($failed->path, 'Externally altered blob');
        $this->integrity(fn () => $evidence->finishCleanup($claim, $authority)); Storage::disk('private')->assertExists($failed->path);
        $this->assertSame('cleaning', $failed->fresh()->state);
    }

    public function test_damaged_parent_proof_cannot_even_claim_failed_file_cleanup(): void
    {
        $root = $this->catalogue(); $stage = $this->failed($root);
        DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'Unaccepted catalogue mutation']);
        $this->integrity(fn () => $this->service()->cleanupSds($root, $this->manager, $stage->idempotency_key));
        $this->assertSame('failed', $stage->fresh()->state); Storage::disk('private')->assertExists($stage->path);
    }

    public function test_available_legacy_document_keeps_unknown_attribution_until_current_selection_observes_actual_bytes(): void
    {
        $root = \App\Models\HazardousSubstance::factory()->create(); $path = 'health-safety/sds/'.$root->id.'/legacy.pdf';
        Storage::disk('private')->put($path, '%PDF-1.4 retained legacy bytes');
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => $path,
            'issue_date' => '2026-10-01', 'review_date' => '2027-10-01']);
        $proof = $this->service()->downloadProof($root, $sheet, $this->manager);
        $this->assertTrue($proof['available']); $this->assertSame('legacy_unknown', $proof['attribution_state']);
        $this->assertNull($sheet->fresh()->document_sha256); $this->assertNull($root->fresh()->current_sds_id);
        $this->service()->setCurrentSds($root, $sheet, $this->manager, $this->command($root, ['reason' => 'Observed existing source bytes']));
        $this->assertSame(hash('sha256', Storage::disk('private')->get($path)), $sheet->fresh()->document_sha256);
        $this->assertSame($sheet->id, $root->fresh()->current_sds_id); $this->assertNull($sheet->fresh()->original_name);
    }

    public function test_recomputed_staging_descriptor_cannot_replace_original_command_fingerprint_for_cleanup(): void
    {
        $root = $this->catalogue(); $stage = $this->failed($root); $values = $stage->command_values;
        $bytes = 'Externally changed unaccepted bytes'; $sha = hash('sha256', $bytes); $values['file_evidence']['sha256'] = $sha;
        $values['file_evidence']['size'] = strlen($bytes); Storage::disk('private')->put($stage->path, $bytes);
        DB::table('chemical_evidence_staging')->where('id', $stage->id)->update(['sha256' => $sha, 'size' => strlen($bytes),
            'command_values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        $this->integrity(fn () => $this->service()->cleanupSds($root, $this->manager, $stage->idempotency_key));
        Storage::disk('private')->assertExists($stage->path); $this->assertNull($stage->fresh()->receipt_id);
    }
}
