<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureDownloadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Physical fixture commits are intentional. Source+Evidence proposals required; all declarations UNRUN. */
final class ChemicalExposureEvidencePrebodyPrivacyTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function upload(SubstanceExposureRecord $root, ?User $actor = null): array
    {
        return $this->evidenceService()->upload($root, $actor ?? $this->manager,
            UploadedFile::fake()->createWithContent('PRIVATE-SOURCE-NAME.pdf', "%PDF-1.4\nActual sealed evidence bytes\n%%EOF"),
            $this->privateCommand($root, ['description' => 'PRIVATE-SOURCE-DESCRIPTION']));
    }
    private function bodies(): object
    {
        $seen = (object) ['files' => [], 'cleanups' => []];
        ChemicalExposureEvidence::retrieved(function ($row) use ($seen) {
            foreach (['path', 'original_name', 'description', 'uploader_snapshot'] as $field) {
                if (array_key_exists($field, $row->getAttributes())) { $seen->files[] = $row->id; break; }
            }
        });
        ChemicalExposureEvidenceCleanup::retrieved(function ($row) use ($seen) {
            if (array_key_exists('path', $row->getAttributes())) { $seen->cleanups[] = $row->id; }
        });
        return $seen;
    }
    private function noBodies(object $seen): void { $this->assertSame([], $seen->files); $this->assertSame([], $seen->cleanups); }
    private function withoutPrivateBytes(callable $operation): mixed
    {
        $manager = Storage::getFacadeRoot(); $disk = \Mockery::mock($manager->disk('private'))->makePartial();
        $disk->shouldNotReceive('readStream'); $disk->shouldNotReceive('delete'); $disk->shouldNotReceive('putFileAs');
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        try { return $operation(); } finally { Storage::swap($manager); }
    }
    private function streamed(SubstanceExposureRecord $root, ChemicalExposureEvidence $file): string
    {
        $response = app(ChemicalExposureDownloadService::class)->download($this->manager, $root->id, $file->id);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        ob_start(); try { $response->sendContent(); return ob_get_contents(); } finally { ob_end_clean(); }
    }
    private function foreignTypedFile(): array
    {
        $actor = $this->staff(['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $worker = $this->staff([], $this->foreignSite);
        $root = SubstanceExposureRecord::findOrFail($this->capture(['site_id' => $this->foreignSite->id, 'user_id' => $worker->id], $actor)['root_id']);
        $receipt = $this->upload($root, $actor);
        return [$root, ChemicalExposureEvidence::findOrFail($receipt['source_id']), $receipt];
    }
    private function pendingRemoval(SubstanceExposureRecord $root, ChemicalExposureEvidence $file): array
    {
        $manager = Storage::getFacadeRoot(); $disk = \Mockery::mock($manager->disk('private'))->makePartial();
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        $input = $this->privateCommand($root, ['reason' => 'Actual retained record removal']);
        try { $receipt = $this->evidenceService()->remove($root, $file->id, $this->manager, $input); }
        finally { Storage::swap($manager); }
        $cleanup = ChemicalExposureEvidenceCleanup::where('evidence_id', $file->id)->sole();
        $this->assertSame('pending', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        return [$receipt, $cleanup, $input];
    }
    public function test_actual_source_upload_and_verified_download_emit_exact_bytes_with_private_headers(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
        $this->assertSame($receipt['source_version_id'], $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id)['source_version_id']);
    }
    public function test_foreign_typed_file_rebound_and_zeroed_refuses_download_before_metadata_or_private_bytes(): void
    {
        [, $file] = $this->foreignTypedFile(); $target = $this->exposure();
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $seen = $this->bodies(); $this->withoutPrivateBytes(fn () => $this->integrity(fn () => app(ChemicalExposureDownloadService::class)
            ->download($this->manager, $target->id, $file->id)));
        $this->noBodies($seen); Storage::disk('private')->assertExists($file->path);
    }
    public function test_foreign_typed_file_rebound_and_zeroed_cannot_gain_a_new_removal_acceptance_or_cleanup(): void
    {
        [, $file] = $this->foreignTypedFile(); $target = $this->exposure(); $receipts = ChemicalExposureCommandReceipt::count();
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $seen = $this->bodies(); $this->withoutPrivateBytes(fn () => $this->integrity(fn () => $this->evidenceService()->remove($target,
            $file->id, $this->manager, $this->privateCommand($target, ['reason' => 'Cannot certify foreign legacy disguise']))));
        $this->noBodies($seen); $this->assertSame($receipts, ChemicalExposureCommandReceipt::count());
        $this->assertSame(0, ChemicalExposureEvidenceCleanup::count()); $this->assertSame(1, $target->fresh()->source_revision);
        Storage::disk('private')->assertExists($file->path);
    }
    public function test_same_parent_typed_marker_downgrade_is_refused_before_file_body_and_exact_restore_reads(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $seen = $this->bodies(); $this->withoutPrivateBytes(fn () => $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id)));
        $this->noBodies($seen);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['source_schema_version' => 1, 'source_revision' => $file->source_revision]);
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
    }
    public function test_moved_version_with_retained_original_receipt_refuses_before_file_hydration_and_restores(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
            ['reviewed_at' => '2026-10-12T01:00:00Z', 'outcome' => 'recorded', 'notes' => 'Independent current aggregate milestone']));
        $other = $this->exposure();
        DB::table('chemical_exposure_source_versions')->where('id', $receipt['source_version_id'])->update(['exposure_id' => $other->id]);
        $seen = $this->bodies(); $this->withoutPrivateBytes(fn () => $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id)));
        $this->noBodies($seen); $this->assertTrue(ChemicalExposureCommandReceipt::where('source_version_id', $receipt['source_version_id'])->exists());
        DB::table('chemical_exposure_source_versions')->where('id', $receipt['source_version_id'])->update(['exposure_id' => $root->id]);
        $this->assertSame($receipt['source_version_id'], $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id)['source_version_id']);
    }
    public function test_missing_exact_evidence_version_kind_with_retained_receipt_refuses_body_and_remove_then_restores(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        DB::table('chemical_exposure_source_versions')->where('id', $receipt['source_version_id'])->update(['source_type' => 'review']);
        $seen = $this->bodies(); $this->withoutPrivateBytes(function () use ($root, $file) {
            $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
            $this->integrity(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'No exact history'])));
        });
        $this->noBodies($seen); $this->assertSame(0, ChemicalExposureEvidenceCleanup::count());
        DB::table('chemical_exposure_source_versions')->where('id', $receipt['source_version_id'])->update(['source_type' => 'evidence']);
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
    }
    public function test_retained_original_client_withdrawal_after_real_unlink_denies_read_remove_and_stage_replay_before_file(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id,
            'site_id' => $this->site->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        $root = $this->exposure(['related_incident_id' => $incident->id]); $receipt = $this->upload($root);
        $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']); $stage = ChemicalExposureEvidenceStaging::where('evidence_id', $file->id)->sole();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual current unlink', 'related_incident_id' => null]));
        $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'clients.viewAny', false); $seen = $this->bodies();
        $input = ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'expected_version' => 3, 'reason' => 'Denied original context'];
        $this->withoutPrivateBytes(function () use ($root, $file, $stage, $input) {
            $this->denial(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id));
            $this->denial(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager, $input));
            $this->denial(fn () => $this->evidenceService()->bindAcceptedStage($this->manager, $stage->id));
        });
        $this->noBodies($seen); $this->allow($this->manager, 'clients.viewAny');
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
        $this->assertTrue($this->evidenceService()->bindAcceptedStage($this->manager, $stage->id)['accepted']);
    }
    public function test_current_original_site_withdrawal_and_restore_never_uses_stale_loaded_actor_or_file(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        $stage = ChemicalExposureEvidenceStaging::where('evidence_id', $file->id)->sole(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $input = $this->privateCommand($root, ['reason' => 'No current original Site']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]); $seen = $this->bodies();
        $this->withoutPrivateBytes(function () use ($root, $file, $stage, $input) {
            $this->denial(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id), 404);
            $this->denial(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager, $input), 404);
            $this->denial(fn () => $this->evidenceService()->bindAcceptedStage($this->manager, $stage->id), 404);
        });
        $this->noBodies($seen); HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
    }
    public function test_private_grant_withdrawal_before_source_sql_preserves_bytes_and_restored_download(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'hr.wellbeing.view', false); $seen = $this->bodies();
        [, $queries] = $this->capturedQueries(fn () => $this->withoutPrivateBytes(fn () => $this->denial(fn () => app(ChemicalExposureDownloadService::class)
            ->download($this->manager, $root->id, $file->id))));
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_evidence', $query['query']); }
        $this->noBodies($seen); $this->allow($this->manager, 'hr.wellbeing.view');
        $this->assertSame(Storage::disk('private')->get($file->path), $this->streamed($root, $file));
    }
    public function test_moved_removed_file_and_cleanup_refuse_path_hydration_and_purge_then_exact_restore_retries(): void
    {
        $root = $this->exposure(); $upload = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($upload['source_id']);
        [, $cleanup] = $this->pendingRemoval($root, $file); $target = $this->exposure();
        $originalFile = ChemicalExposureEvidence::withTrashed()->findOrFail($file->id);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        DB::table('chemical_exposure_evidence_cleanups')->where('id', $cleanup->id)->update(['exposure_id' => $target->id]);
        $seen = $this->bodies(); $this->withoutPrivateBytes(fn () => $this->integrity(fn () => $this->evidenceService()->retryRemovalCleanup($this->manager, $cleanup->id)));
        $this->noBodies($seen); Storage::disk('private')->assertExists($file->path);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $root->id,
            'source_schema_version' => $originalFile->source_schema_version, 'source_revision' => $originalFile->source_revision]);
        DB::table('chemical_exposure_evidence_cleanups')->where('id', $cleanup->id)->update(['exposure_id' => $root->id]);
        $this->assertSame('complete', $this->evidenceService()->retryRemovalCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('private')->assertMissing($file->path);
    }
    public function test_cleanup_audit_subject_with_foreign_retained_receipt_is_refused_before_cleanup_or_file_body(): void
    {
        $root = $this->exposure(); $upload = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($upload['source_id']);
        [, $cleanup] = $this->pendingRemoval($root, $file); $target = $this->exposure();
        DB::table('chemical_exposure_evidence_cleanups')->where('id', $cleanup->id)->update(['exposure_id' => $target->id]); $seen = $this->bodies();
        $this->withoutPrivateBytes(fn () => $this->integrity(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            function ($current, $reads, $sites) use ($target, $cleanup) {
                $admitted = app(ChemicalExposureCommandAccess::class)->source($target->id, $current, $reads, $sites, 'read');
                return $this->evidenceService()->auditSubjectIsProven('cleanup', $cleanup->id, $admitted, $current, $reads, $sites);
            })));
        $this->noBodies($seen); Storage::disk('private')->assertExists($file->path);
    }
    public function test_exact_removal_replay_retains_original_acceptance_and_tombstone_without_a_second_revision(): void
    {
        $root = $this->exposure(); $upload = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($upload['source_id']);
        [$receipt, $cleanup, $input] = $this->pendingRemoval($root, $file); $versions = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count();
        $this->assertSame($receipt, $this->evidenceService()->remove($root, $file->id, $this->manager, $input));
        $this->assertSame($versions, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        $this->assertSame(3, $root->fresh()->source_revision); $this->assertSame($cleanup->id, ChemicalExposureEvidenceCleanup::where('evidence_id', $file->id)->sole()->id);
        $this->assertCount(12, $receipt); $this->assertArrayNotHasKey('path', $receipt); $this->assertArrayNotHasKey('purged', $receipt);
    }
    public function test_stage_replay_refuses_moved_exact_file_before_metadata_and_restored_identity_reconciles(): void
    {
        $root = $this->exposure(); $receipt = $this->upload($root); $file = ChemicalExposureEvidence::findOrFail($receipt['source_id']);
        $stage = ChemicalExposureEvidenceStaging::where('evidence_id', $file->id)->sole(); $target = $this->exposure();
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $target->id]); $seen = $this->bodies();
        $this->withoutPrivateBytes(fn () => $this->denial(fn () => $this->evidenceService()->bindAcceptedStage($this->manager, $stage->id), 404));
        $this->noBodies($seen); DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $root->id]);
        $this->assertTrue($this->evidenceService()->bindAcceptedStage($this->manager, $stage->id)['accepted']);
        $this->assertSame($receipt, $this->exposureService()->confirmationForKey($this->manager, $receipt['idempotency_key'], $root->id));
    }
    public function test_genuine_unlinked_legacy_file_is_unknown_and_never_gets_retrospective_download_or_cleanup_proof(): void
    {
        $root = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact', 'related_incident_id' => null]));
        $root = $root->fresh(); $path = 'health-safety/chemical-exposures/'.$root->id.'/'.\Illuminate\Support\Str::uuid().'/'.\Illuminate\Support\Str::uuid().'/evidence';
        $bytes = "%PDF-1.4\nGenuine unversioned legacy bytes\n%%EOF"; Storage::disk('private')->put($path, $bytes);
        $file = ChemicalExposureEvidence::create(['exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0,
            'disk' => 'private', 'path' => $path, 'original_name' => 'legacy.pdf', 'mime' => 'application/pdf',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $this->manager->id, 'uploader_snapshot' => ['id' => $this->manager->id, 'name' => $this->manager->name]]);
        $this->assertSame('legacy_unknown', $this->proof($root)['attribution_state']);
        $this->withoutPrivateBytes(fn () => $this->integrity(fn () => $this->evidenceService()->evidenceReadProof($this->manager, $root->id, $file->id)));
        // Existing removal cannot promote schema0 child to a typed after-state by changing its revision alone.
        $this->integrity(fn () => $this->evidenceService()->remove($root, $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'No invented certification'])));
        $this->assertSame(0, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureEvidenceCleanup::count());
        $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        Storage::disk('private')->assertExists($path); $this->assertSame(0, $file->fresh()->source_revision);
    }
}
