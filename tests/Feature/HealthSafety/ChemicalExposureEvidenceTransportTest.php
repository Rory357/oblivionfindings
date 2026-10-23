<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Services\HealthSafety\ChemicalExposureDownloadService;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Physical file/commit contract; prospective HTTP routes remain UNAPPLIED and all cases UNRUN. */
final class ChemicalExposureEvidenceTransportTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private const BASE = '/health-safety/chemical-exposures';
    private const BYTES = "%PDF-1.4\nActual private transport evidence\n%%EOF";
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function upload($root): ChemicalExposureEvidence
    {
        $r = $this->evidenceService()->upload($root, $this->manager,
            UploadedFile::fake()->createWithContent('provided-proof.pdf', self::BYTES),
            $this->privateCommand($root, ['description' => 'Actual protected evidence description']));
        return ChemicalExposureEvidence::findOrFail($r['source_id']);
    }
    private function download($root, $file, $actor = null): StreamedResponse
    {
        return app(ChemicalExposureDownloadService::class)->download($actor ?? $this->manager, $root->id, $file->id);
    }
    private function emit(StreamedResponse $response): string
    {
        ob_start(); try { $response->sendContent(); return ob_get_contents(); } finally { ob_end_clean(); }
    }
    private function withDisk(callable $configure, callable $operation): mixed
    {
        $manager = Storage::getFacadeRoot(); $real = $manager->disk('private');
        $disk = \Mockery::mock($real)->makePartial(); $configure($disk, $real);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        try { return $operation(); } finally { Storage::swap($manager); }
    }
    private function failed($root): ChemicalExposureEvidenceStaging
    {
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.upload_evidence' ? false : null);
        try { $this->integrity(fn () => $this->upload($root)); } finally { AuditLog::flushEventListeners(); }
        return ChemicalExposureEvidenceStaging::where('exposure_id', $root->id)->sole();
    }
    public function test_verified_download_emits_exact_uploaded_bytes_private_headers_and_no_descriptor_json(): void
    {
        $root = $this->exposure(); $file = $this->upload($root); $response = $this->download($root, $file);
        $this->assertSame(self::BYTES, $this->emit($response));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('provided-proof.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $dto = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id)['attachments']['items'][0];
        $this->assertTrue($dto['available']); $this->assertSame(self::BASE.'/'.$root->id.'/evidence/'.$file->id.'/download', $dto['href']);
        foreach (['path', 'disk', 'sha256', 'byte_proof', 'content'] as $key) { $this->assertArrayNotHasKey($key, $dto); }
    }
    public function test_stream_changed_between_descriptor_proof_and_open_is_rejected_before_emission(): void
    {
        $root = $this->exposure(); $file = $this->upload($root); $calls = 0;
        $this->withDisk(function ($disk, $real) use ($file, &$calls) {
            $disk->shouldReceive('readStream')->with($file->path)->andReturnUsing(function () use ($real, $file, &$calls) {
                if (++$calls === 1) { return $real->readStream($file->path); }
                $stream = fopen('php://temp', 'w+'); fwrite($stream, 'Unverified replacement bytes'); rewind($stream); return $stream;
            });
        }, fn () => $this->denial(fn () => $this->download($root, $file), 404));
        $this->assertSame(2, $calls);
    }
    public function test_false_second_stream_open_has_no_response_or_unchecked_reopen(): void
    {
        $root = $this->exposure(); $file = $this->upload($root); $calls = 0;
        $this->withDisk(function ($disk, $real) use ($file, &$calls) {
            $disk->shouldReceive('readStream')->with($file->path)->andReturnUsing(function () use ($real, $file, &$calls) {
                return ++$calls === 1 ? $real->readStream($file->path) : false;
            });
        }, fn () => $this->denial(fn () => $this->download($root, $file), 404));
        $this->assertSame(2, $calls);
    }
    public function test_verified_spool_is_not_reopened_when_original_blob_changes_before_emission(): void
    {
        $root = $this->exposure(); $file = $this->upload($root); $calls = 0;
        $response = $this->withDisk(function ($disk, $real) use ($file, &$calls) {
            $disk->shouldReceive('readStream')->with($file->path)->andReturnUsing(function () use ($real, $file, &$calls) {
                $calls++; return $real->readStream($file->path);
            });
        }, fn () => $this->download($root, $file));
        Storage::disk('private')->put($file->path, 'Changed after verified spool');
        $this->assertSame(self::BYTES, $this->emit($response)); $this->assertSame(2, $calls);
    }
    public function test_foreign_parent_is_denied_before_any_attachment_body_or_private_bytes(): void
    {
        $root = $this->exposure(); $other = $this->exposure(); $file = $this->upload($root); $hydrated = false;
        ChemicalExposureEvidence::retrieved(function ($row) use ($file, &$hydrated) { if ($row->id === $file->id) { $hydrated = true; } });
        try { $this->denial(fn () => $this->download($other, $file), 404); } finally { ChemicalExposureEvidence::flushEventListeners(); }
        $this->assertFalse($hydrated); Storage::disk('private')->assertExists($file->path);
    }
    public function test_current_private_permission_and_site_revocation_deny_download_without_private_body_sql(): void
    {
        $root = $this->exposure(); $file = $this->upload($root); $this->manager->canDo('hr.wellbeing.view');
        $this->allow($this->manager, 'hr.wellbeing.view', false); $hydrated = false;
        ChemicalExposureEvidence::retrieved(function () use (&$hydrated) { $hydrated = true; });
        try { $this->denial(fn () => $this->download($root, $file)); } finally { ChemicalExposureEvidence::flushEventListeners(); }
        $this->assertFalse($hydrated); $this->allow($this->manager, 'hr.wellbeing.view');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->download($root, $file), 404);
    }
    public function test_missing_or_modified_private_bytes_are_unavailable_not_a_public_or_legacy_fallback(): void
    {
        $root = $this->exposure(); $file = $this->upload($root);
        Storage::disk('private')->put($file->path, 'Ungoverned altered private bytes');
        $this->integrity(fn () => $this->download($root, $file));
        $dto = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id)['attachments']['items'][0];
        $this->assertFalse($dto['available']); $this->assertNull($dto['href']); $this->assertArrayNotHasKey('original_name', $dto);
        Storage::disk('private')->delete($file->path); $this->integrity(fn () => $this->download($root, $file));
        $dto = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id)['attachments']['items'][0];
        $this->assertFalse($dto['available']); $this->assertNull($dto['href']); $this->assertSame('recorded', $dto['attribution_state']);
    }
    public function test_upload_download_and_remove_literal_http_paths_preserve_receipt_and_real_missing_parity(): void
    {
        $root = $this->exposure(); $this->actingAs($this->manager);
        $upload = $this->postJson(self::BASE.'/'.$root->id.'/evidence', $this->privateCommand($root,
            ['file' => UploadedFile::fake()->createWithContent('provided-proof.pdf', self::BYTES), 'description' => 'Provided']))->assertOk();
        $id = $upload->json('chemical_exposure_confirmation.source_id'); $file = ChemicalExposureEvidence::findOrFail($id);
        $this->get(self::BASE.'/'.$root->id.'/evidence/'.$id.'/download')->assertOk()->assertStreamedContent(self::BYTES);
        $removeInput = $this->privateCommand($root, ['reason' => 'Actual duplicate removal']);
        $removed = $this->deleteJson(self::BASE.'/'.$root->id.'/evidence/'.$id, $removeInput)->assertOk();
        $removed->assertJsonPath('chemical_exposure_confirmation.command', 'remove_evidence');
        $this->assertNull(ChemicalExposureEvidence::find($id)); Storage::disk('private')->assertMissing($file->path);
        $this->get(self::BASE.'/'.$root->id.'/evidence/'.$id.'/download')->assertNotFound();
        $this->assertSame($removed->json('chemical_exposure_confirmation'),
            $this->deleteJson(self::BASE.'/'.$root->id.'/evidence/'.$id, $removeInput)->assertOk()->json('chemical_exposure_confirmation'));
    }
    public function test_false_purge_reports_pending_removal_cleanup_without_new_acceptance_or_revision(): void
    {
        $root = $this->exposure(); $file = $this->upload($root);
        $removed = $this->withDisk(fn ($disk) => $disk->shouldReceive('delete')->with($file->path)->andReturn(false),
            fn () => $this->evidenceService()->remove($root, $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'Actual removal'])));
        $row = ChemicalExposureEvidenceCleanup::where('evidence_id', $file->id)->sole();
        $this->assertSame('pending', $row->state); $this->assertNull(ChemicalExposureEvidence::find($file->id));
        Storage::disk('private')->assertExists($file->path);
        $this->assertSame(3, $root->fresh()->source_revision); $this->assertSame(3, $removed['version']);
        $this->assertSame('complete', $this->evidenceService()->retryRemovalCleanup($this->manager, $row->id)['state']);
        Storage::disk('private')->assertMissing($file->path); $this->assertSame(3, $root->fresh()->source_revision);
    }
    public function test_rejected_stage_recovery_is_actor_only_json_and_never_emits_path_uuid_or_private_file_name(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $this->actingAs($this->manager);
        $url = self::BASE.'/recovery/staging/'.$stage->id;
        $this->get($url)->assertNotFound(); $json = $this->getJson($url)->assertOk()->assertJsonPath('staging.accepted', false);
        foreach (['path', 'command_values', 'file_evidence', 'idempotency_key', 'original_name', 'actor_snapshot'] as $field) { $json->assertJsonMissingPath('staging.'.$field); }
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->getJson(self::BASE.'/recovery/staging')->assertOk()->assertJsonPath('attempts', []);
        $this->actingAs($this->manager)->postJson($url.'/retry-cleanup', ['expected_version' => 1])->assertUnprocessable();
    }
    public function test_shared_hr_pointer_blocks_rejected_stage_cleanup_and_http_retry_remains_honestly_pending(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root);
        HrDocument::factory()->create(['employee_profile_id' => $this->manager->hrEmployeeProfile->id,
            'storage_disk' => 'local', 'storage_path' => $stage->path]);
        $this->actingAs($this->manager)->postJson(self::BASE.'/recovery/staging/'.$stage->id.'/retry-cleanup', [])
            ->assertOk()->assertJsonPath('staging.accepted', false)->assertJsonPath('staging.state', 'cleanup_pending');
        Storage::disk('private')->assertExists($stage->path); $this->assertSame(1, $root->fresh()->source_revision);
    }
    public function test_recovery_permission_revocation_does_not_purge_pending_bytes_or_claim_acceptance(): void
    {
        $root = $this->exposure(); $stage = $this->failed($root); $this->manager->canDo('hr.wellbeing.view');
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->actingAs($this->manager)->postJson(self::BASE.'/recovery/staging/'.$stage->id.'/retry-cleanup', [])->assertForbidden();
        Storage::disk('private')->assertExists($stage->path); $this->assertNull($stage->fresh()->receipt_id);
        $this->assertSame(1, $root->fresh()->source_revision);
    }
}
