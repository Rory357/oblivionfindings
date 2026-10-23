<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryEvidence;
use App\Models\ChemicalExposureRecoveryReceipt;
use App\Models\ChemicalExposureRecoveryStaging;
use App\Models\ChemicalExposureRecoveryVersion;
use App\Services\HealthSafety\ChemicalExposureRecoveryEvidenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Actual durable reservations/bytes on the admitted native schema. Prepared only; every case UNRUN. */
final class ChemicalExposureRecoveryEvidenceTest extends ChemicalExposureRecoveryTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function files(): ChemicalExposureRecoveryEvidenceService { return app(ChemicalExposureRecoveryEvidenceService::class); }
    private function file(): UploadedFile { return UploadedFile::fake()->createWithContent('review-basis.pdf', "%PDF-1.4\nActual newly provided review basis"); }
    private function unresolved(): \App\Models\SubstanceExposureRecord
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()); return $root;
    }
    public function test_actual_unresolved_new_basis_is_accepted_and_stored_but_never_saved_file_read_self_grant(): void
    {
        $root = $this->unresolved(); $before = $root->getRawOriginal();
        $receipt = $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $this->assertSame('upload_review_basis', $receipt['command']); $this->assertTrue($receipt['accepted']);
        $file = ChemicalExposureRecoveryEvidence::sole(); Storage::disk('private')->assertExists($file->path);
        $this->denial(fn () => $this->files()->evidence($this->manager, $root->id, $file->id));
        $this->assertSame($before, $root->fresh()->getRawOriginal());
    }
    private function uploadInput(int $version = 1, ?string $uuid = null): array
    {
        return ['idempotency_key' => $uuid ?? (string) \Illuminate\Support\Str::uuid(), 'expected_version' => $version,
            'description' => 'New review basis only'];
    }
    public function test_real_known_new_context_download_uses_exact_new_basis_bytes_not_old_root_evidence(): void
    {
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id]));
        $receipt = $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $file = ChemicalExposureRecoveryEvidence::sole(); $dto = $this->files()->evidence($this->manager, $root->id, $file->id);
        $this->assertSame('private', $dto['disk']); $this->assertSame(hash('sha256', Storage::disk('private')->get($file->path)), $dto['sha256']);
        $this->assertSame(strlen(Storage::disk('private')->get($file->path)), $dto['size']);
        $this->assertSame(2, $receipt['version']); $this->assertSame(0, $root->fresh()->source_revision);
    }
    public function test_earlier_unknown_basis_stays_sealed_after_later_reviewed_association(): void
    {
        $root = $this->unresolved(); $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $file = ChemicalExposureRecoveryEvidence::sole(); $incident = $this->selectedIncident();
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id], 2));
        $this->denial(fn () => $this->files()->evidence($this->manager, $root->id, $file->id));
        $this->assertNull($this->recovery()->inspect($this->manager, $root->id)['legacy_source']['evidence']);
    }
    public function test_upload_lost_response_replay_keeps_same_receipt_basis_bytes_and_case_revision(): void
    {
        $root = $this->unresolved(); $v = $this->uploadInput(); $r = $this->files()->upload($this->manager, $root->id, $this->file(), $v);
        $this->assertSame($r, $this->files()->upload($this->manager, $root->id, $this->file(), $v));
        $this->assertSame(1, ChemicalExposureRecoveryEvidence::count()); $this->assertSame(2, ChemicalExposureRecoveryVersion::count());
    }
    public function test_different_uuid_command_actor_or_bytes_cannot_reuse_an_original_upload_claim(): void
    {
        $root = $this->unresolved(); $v = $this->uploadInput(); $this->files()->upload($this->manager, $root->id, $this->file(), $v);
        $different = UploadedFile::fake()->createWithContent('review-basis.pdf', '%PDF-1.4 Different actual bytes');
        $this->validation(fn () => $this->files()->upload($this->manager, $root->id, $different, $v), 'idempotency_key');
        $this->validation(fn () => $this->recovery()->recordReview($this->manager, $root->id,
            $this->reviewInput([], 1, $v['idempotency_key'])), 'idempotency_key');
    }
    public function test_metadata_inspection_is_minimal_and_never_stores_private_path_uuid_or_filename_in_page_dto(): void
    {
        $root = $this->unresolved(); $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $stage = ChemicalExposureRecoveryStaging::sole(); $dto = $this->files()->inspectStaging($this->manager, $root->id, $stage->id);
        $this->assertSame(['id' => $stage->id, 'state' => 'accepted', 'accepted' => true], $dto);
        foreach (['path', 'claim_frame', 'original_name', 'idempotency_key', 'sha256'] as $field) { $this->assertArrayNotHasKey($field, $dto); }
    }
    public function test_exact_foreign_parent_denies_before_stage_or_file_metadata_hydration(): void
    {
        $root = $this->unresolved(); $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $other = $this->legacy(); $this->recovery()->recordReview($this->manager, $other->id, $this->reviewInput());
        $file = ChemicalExposureRecoveryEvidence::sole(); $stage = ChemicalExposureRecoveryStaging::sole();
        $this->denial(fn () => $this->files()->inspectStaging($this->manager, $other->id, $stage->id), 404);
        $this->denial(fn () => $this->files()->evidence($this->manager, $other->id, $file->id), 404);
    }
    public function test_save_veto_after_real_byte_storage_leaves_committed_reservation_pending_and_no_false_acceptance(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole(); $this->assertSame('cleanup_pending', $stage->state);
        $this->assertNull($stage->receipt_id); Storage::disk('private')->assertExists($stage->path);
        $this->assertSame(1, ChemicalExposureRecoveryVersion::count()); $this->assertSame(1, ChemicalExposureRecoveryReceipt::count());
        $this->assertSame(1, ChemicalExposureRecoveryCase::sole()->revision);
    }
    public function test_rejected_stored_basis_cleanup_checks_actual_bytes_and_keeps_accepted_evidence(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole(); $dto = $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id);
        $this->assertSame('cleaned', $dto['state']); $this->assertFalse($dto['accepted']); Storage::disk('private')->assertMissing($stage->path);
    }
    public function test_damaged_retained_review_body_prevents_cleanup_instead_of_deleting_bytes(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole();
        // External retained-body corruption must refuse cleanup, not certify this path as a new acceptance.
        $version = ChemicalExposureRecoveryVersion::sole(); $content = $version->content; $content['facts']['path'] = $stage->path;
        DB::table('chemical_exposure_recovery_versions')->where('id', $version->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        // Source integrity is damaged, so canonical cleanup must refuse before touching any bytes.
        $this->integrity(fn () => $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id));
        Storage::disk('private')->assertExists($stage->path);
    }
    public function test_accepted_basis_never_becomes_staging_cleanup_even_after_other_review(): void
    {
        $root = $this->unresolved(); $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $stage = ChemicalExposureRecoveryStaging::sole(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput([], 2));
        $this->integrity(fn () => $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id)); Storage::disk('private')->assertExists($stage->path);
    }
    public function test_outer_transaction_refuses_before_reservation_or_file_write(): void
    {
        $root = $this->unresolved(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput())); }
        finally { DB::rollBack(); }
        $this->assertSame(0, ChemicalExposureRecoveryStaging::count());
    }
    public function test_permission_or_site_withdrawal_denies_physical_cleanup_and_inspection_preserving_pending_bytes(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole(); $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->denial(fn () => $this->files()->inspectStaging($this->manager, $root->id, $stage->id));
        $this->denial(fn () => $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id));
        Storage::disk('private')->assertExists($stage->path); $this->allow($this->manager, 'hr.wellbeing.view');
        $this->assertSame('cleaned', $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id)['state']);
    }
    public function test_changed_private_basis_bytes_are_not_served_from_a_matching_filename_or_delivered_flag(): void
    {
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id]));
        $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()); $file = ChemicalExposureRecoveryEvidence::sole();
        Storage::disk('private')->put($file->path, '%PDF-1.4 changed private bytes');
        $this->integrity(fn () => $this->files()->evidence($this->manager, $root->id, $file->id));
    }
    public function test_upload_requires_existing_later_case_and_does_not_release_original_legacy_medical_body(): void
    {
        $root = $this->legacy(); $this->validation(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput(0)), 'source');
        $this->assertSame(0, ChemicalExposureRecoveryStaging::count()); $this->assertSame('SEALED-ORIGINAL-MEDICAL', $root->fresh()->symptoms);
    }
    public function test_real_other_hr_document_pointer_blocks_cleanup_without_hydrating_its_private_body(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole();
        $profile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->worker->id)->sole();
        \App\Domain\Hr\Models\HrDocument::factory()->create(['employee_profile_id' => $profile->id,
            'storage_path' => $stage->path, 'storage_disk' => 'private', 'title' => 'OTHER-PRIVATE-DOCUMENT',
            'created_by' => $this->manager->id, 'uploaded_by' => $this->manager->id]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id));
        $this->assertSame(['id' => $stage->id, 'state' => 'unknown', 'accepted' => false], $dto);
        Storage::disk('private')->assertExists($stage->path);
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'from `hr_documents`')) {
                $this->assertStringNotContainsString('title', $query['query']);
                $this->assertStringNotContainsString('select *', strtolower($query['query']));
            }
        }
    }
    public function test_accepted_stage_lost_original_receipt_binding_is_repair_not_false_unaccepted_cleanup(): void
    {
        $root = $this->unresolved(); $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput());
        $stage = ChemicalExposureRecoveryStaging::sole();
        DB::table('chemical_exposure_recovery_staging')->where('id', $stage->id)->update(['receipt_id' => null, 'state' => 'reserved']);
        $this->integrity(fn () => $this->files()->inspectStaging($this->manager, $root->id, $stage->id));
        $this->integrity(fn () => $this->files()->retryStagingCleanup($this->manager, $root->id, $stage->id));
        Storage::disk('private')->assertExists($stage->path);
    }
    public function test_durable_unaccepted_basis_resumes_exact_original_upload_after_acceptance_veto(): void
    {
        $root = $this->unresolved(); $v = $this->uploadInput(); $blocked = true;
        ChemicalExposureRecoveryEvidence::creating(function () use (&$blocked) { return $blocked ? false : null; });
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $v));
        $stage = ChemicalExposureRecoveryStaging::sole(); $path = $stage->path; $claim = $stage->intent_id;
        Storage::disk('private')->assertExists($path); $blocked = false;
        $receipt = $this->files()->upload($this->manager, $root->id, $this->file(), $v);
        $this->assertTrue($receipt['accepted']); $this->assertSame($path, ChemicalExposureRecoveryEvidence::sole()->path);
        $this->assertSame($claim, ChemicalExposureRecoveryStaging::sole()->intent_id);
        $this->assertSame(1, ChemicalExposureRecoveryStaging::count()); $this->assertSame(2, ChemicalExposureRecoveryVersion::count());
    }
    public function test_original_durable_stage_claim_fk_cannot_be_deleted_and_recreated_as_the_same_uuid(): void
    {
        $root = $this->unresolved(); ChemicalExposureRecoveryEvidence::creating(fn () => false);
        $this->integrity(fn () => $this->files()->upload($this->manager, $root->id, $this->file(), $this->uploadInput()));
        $stage = ChemicalExposureRecoveryStaging::sole();
        try { DB::table('chemical_command_intents')->where('id', $stage->intent_id)->delete(); $this->fail('Original staging claim must be restrictively retained.'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23000', $error->errorInfo[0]); }
        $this->assertSame($stage->intent_id, ChemicalExposureRecoveryStaging::sole()->intent_id);
        $this->assertSame('cleanup_pending', $this->files()->inspectStaging($this->manager, $root->id, $stage->id)['state']);
        Storage::disk('private')->assertExists($stage->path);
    }
}
