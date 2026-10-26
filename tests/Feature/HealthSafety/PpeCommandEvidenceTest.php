<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeAttachment;
use App\Models\PpeEvidenceCleanup;
use App\Models\PpeSourceVersion;
use App\Services\HealthSafety\PpeAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PpeCommandEvidenceTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function upload(\App\Models\PpeInventory $item, string $name = 'certificate.pdf'): array
    {
        return $this->service()->uploadAttachment('inventory', $item->id, $this->manager,
            $this->command($item, ['file' => UploadedFile::fake()->createWithContent($name, 'Actual bounded file bytes'), 'kind' => 'certificate']));
    }
    private function proof(\App\Models\PpeInventory $item, PpeAttachment $attachment): array
    {
        return app(PpeAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($item, $attachment) {
            $root = app(PpeAccessService::class)->assertInventory($item, $current, $reads, $sites);
            $file = app(PpeAccessService::class)->assertAttachment($attachment, $root, $current, $reads, $sites);
            return $this->service()->evidenceProof($root, $file);
        });
    }
    public function test_private_upload_records_exact_bytes_and_minimal_acceptance(): void
    {
        $item = $this->item(); $receipt = $this->upload($item); $file = PpeAttachment::findOrFail($receipt['source_id']);
        Storage::disk('private')->assertExists($file->path);
        $this->assertSame(['attribution_state' => 'recorded', 'available' => true], $this->proof($item, $file));
        $this->assertArrayNotHasKey('path', $receipt); $this->assertArrayNotHasKey('original_name', $receipt);
        $this->assertSame(2, $item->fresh()->source_revision);
    }
    public function test_inline_inventory_and_inspection_documents_have_bound_governed_proof(): void
    {
        $item = $this->item(['documents' => [['file' => UploadedFile::fake()->createWithContent('inline.pdf', 'Inline source bytes'), 'note' => 'Source note']]]);
        $file = $item->attachments()->sole();
        $this->assertSame(['attribution_state' => 'recorded', 'available' => true], $this->proof($item, $file));
        $receipt = $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass',
            'documents' => [['file' => UploadedFile::fake()->createWithContent('inspection.txt', 'Inspection bytes'), 'kind' => 'inspection_report']]]));
        $inspection = \App\Models\PpeInspection::findOrFail($receipt['source_id']);
        $this->assertSame(1, $inspection->attachments()->count());
        $this->assertSame('recorded', $this->sourceState($item, $inspection));
    }
    public function test_audit_refusal_rolls_back_uploaded_row_revision_and_staged_file(): void
    {
        $item = $this->item();
        AuditLog::creating(fn ($row) => $row->action === 'ppe.upload_attachment' ? false : null);
        try { $this->integrity(fn () => $this->upload($item)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(0, $item->attachments()->count()); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_multipart_outer_transaction_refusal_stages_no_file_or_source_write(): void
    {
        $item = $this->item(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->upload($item)); }
        finally { DB::rollBack(); }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_removal_commits_active_record_tombstone_then_checked_blob_purge(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $receipt = $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager,
            $this->command($item, ['reason' => 'Superseded source document']));
        $this->assertSoftDeleted('ppe_attachments', ['id' => $file->id]);
        Storage::disk('private')->assertMissing($file->path);
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame(['id' => $cleanup->id, 'state' => 'complete', 'removed_from_record' => true], $this->service()->cleanupForViewer($this->manager, $cleanup->id));
        $this->assertArrayNotHasKey('physically_deleted', $receipt);
    }
    public function test_nested_removal_waits_for_physical_commit_and_outer_rollback_preserves_file(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        DB::beginTransaction();
        try {
            $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Nested removal']));
            Storage::disk('private')->assertExists($file->path);
            $this->assertSame('pending', PpeEvidenceCleanup::where('attachment_id', $file->id)->sole()->state);
        } finally { DB::rollBack(); }
        $this->assertNotNull($file->fresh()); Storage::disk('private')->assertExists($file->path);
        $this->assertSame(0, PpeEvidenceCleanup::where('attachment_id', $file->id)->count());
        DB::beginTransaction();
        $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Physical commit']));
        Storage::disk('private')->assertExists($file->path);
        DB::commit(); Storage::disk('private')->assertMissing($file->path);
    }
    public function test_failed_purge_stays_pending_and_exact_retry_resumes_without_new_revision(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $storageManager = Storage::getFacadeRoot();
        $real = $storageManager->disk('private'); $disk = \Mockery::mock($real)->makePartial();
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        $input = $this->command($item, ['reason' => 'Recorded removal']);
        $receipt = $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input);
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('pending', $cleanup->state); $this->assertTrue($real->exists($file->path));
        Storage::swap($storageManager);
        $this->assertSame($receipt, $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input));
        $this->assertSame(3, $item->fresh()->source_revision); $this->assertSame('complete', $cleanup->fresh()->state);
    }
    public function test_removal_requires_reason_and_forged_parent_cannot_remove_or_purge(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']); $other = $this->item();
        $this->validation(fn () => $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item)), 'reason');
        $this->denial(fn () => $this->service()->removeAttachment('inventory', $other->id, $file->id, $this->manager, $this->command($other, ['reason' => 'Forged parent'])), 404);
        Storage::disk('private')->assertExists($file->path); $this->assertNotNull($file->fresh());
    }
    public function test_missing_changed_bytes_metadata_or_marker_downgrade_do_not_produce_available_links(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        Storage::disk('private')->put($file->path, 'Different bytes');
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false], $this->proof($item, $file));
        Storage::disk('private')->delete($file->path);
        $this->assertSame(['attribution_state' => 'unavailable', 'available' => false], $this->proof($item, $file));
        DB::table('ppe_attachments')->where('id', $file->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false], $this->proof($item, $file));
    }
    public function test_public_legacy_metadata_is_not_private_availability_or_verified_provenance(): void
    {
        $item = \App\Models\PpeInventory::factory()->create(['site_id' => $this->site->id]);
        $file = PpeAttachment::create(['ppe_inventory_id' => $item->id, 'uploaded_by' => $this->manager->id,
            'disk' => 'public', 'path' => 'legacy.pdf', 'original_name' => 'legacy.pdf']);
        $this->assertSame(['attribution_state' => 'legacy_unknown', 'available' => false], $this->proof($item, $file));
    }
    public function test_evidence_cleanup_revoke_denies_retry_and_immutable_receipt_does_not_change(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $input = $this->command($item, ['reason' => 'Recorded removal']);
        $receipt = $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input);
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->service()->resumeCleanup($this->manager, $cleanup->id));
        $this->denial(fn () => $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input));
        $this->assertSame($receipt, \App\Models\PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole()->accepted_result);
    }
    public function test_changed_file_same_command_uuid_is_a_different_reviewed_intent(): void
    {
        $item = $this->item(); $input = $this->command($item, ['file' => UploadedFile::fake()->createWithContent('same.pdf', 'Original bytes')]);
        $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input);
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager,
            [...$input, 'file' => UploadedFile::fake()->createWithContent('same.pdf', 'Changed bytes')]), 'idempotency_key');
        $this->assertSame(1, $item->attachments()->count());
    }
    public function test_allocation_and_inspection_evidence_use_exact_parent_tuple_and_private_cleanup(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $inspectionReceipt = $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass']));
        $inspection = \App\Models\PpeInspection::findOrFail($inspectionReceipt['source_id']);
        foreach (['allocation' => $allocation, 'inspection' => $inspection] as $type => $parent) {
            $receipt = $this->service()->uploadAttachment($type, $parent->id, $this->manager,
                $this->command($item, ['file' => UploadedFile::fake()->createWithContent($type.'.txt', 'Actual '.$type.' source')]));
            $class = $type === 'allocation' ? \App\Models\PpeAllocationAttachment::class : \App\Models\PpeInspectionAttachment::class;
            $attachment = $class::findOrFail($receipt['source_id']);
            $this->assertSame($parent->id, (int) $attachment->{$type === 'allocation' ? 'ppe_allocation_id' : 'ppe_inspection_id'});
            $this->service()->removeAttachment($type, $parent->id, $attachment->id, $this->manager,
                $this->command($item, ['reason' => 'Superseded '.$type.' evidence']));
            $this->assertSoftDeleted($attachment->getTable(), ['id' => $attachment->id]);
            Storage::disk('private')->assertMissing($attachment->path);
        }
        $this->assertSame('allocated', $item->fresh()->status);
    }
    public function test_typed_evidence_missing_version_or_external_metadata_never_downgrades_to_legacy(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        DB::table('ppe_attachments')->where('id', $file->id)->update(['original_name' => 'Externally changed private name']);
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false], $this->proof($item, $file));
        DB::table('ppe_source_versions')->where('root_type', 'inventory')->where('root_id', $item->id)->delete();
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false], $this->proof($item, $file));
        Storage::disk('private')->assertExists($file->path);
    }
    public function test_same_read_byte_descriptor_is_returned_only_after_verified_digest_comparison(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $descriptor = app(PpeAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($item, $file) {
            $root = app(PpeAccessService::class)->assertInventory($item, $current, $reads, $sites);
            $source = app(PpeAccessService::class)->assertAttachment($file, $root, $current, $reads, $sites);
            return $this->service()->evidenceReadProof($root, $source);
        });
        $this->assertSame(['digest' => hash('sha256', 'Actual bounded file bytes'), 'size' => strlen('Actual bounded file bytes')], $descriptor['byte_proof']);
        $this->assertSame(2, count($this->proof($item, $file)));
        Storage::disk('private')->put($file->path, 'Modified bytes');
        $descriptor = app(PpeAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($item, $file) {
            return $this->service()->evidenceReadProof(app(PpeAccessService::class)->assertInventory($item, $current, $reads, $sites), $file->fresh());
        });
        $this->assertNull($descriptor['byte_proof']); $this->assertFalse($descriptor['available']);
    }    public function test_pending_removal_cleanup_is_discoverable_only_to_current_actor_source_and_site(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $manager = Storage::getFacadeRoot(); $real = $manager->disk('private');
        $disk = \Mockery::mock($real)->makePartial(); $disk->shouldReceive('delete')->andReturn(false);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        try { $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Removal with pending delivery'])); }
        finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $dto = $this->service()->cleanupAttemptsForViewer($this->manager);
        $this->assertSame($cleanup->id, $dto['attempts'][0]['id']); $this->assertSame('pending', $dto['attempts'][0]['state']);
        $this->assertTrue($dto['attempts'][0]['removed_from_record']); $this->assertTrue($dto['attempts'][0]['can_retry']);
        foreach (['path', 'original_name', 'attachment_id', 'idempotency_key', 'receipt_id'] as $private) { $this->assertArrayNotHasKey($private, $dto['attempts'][0]); }
        $this->assertSame([], $this->service()->cleanupAttemptsForViewer($this->staff(['hazards.manage']))['attempts']);
        $this->assertSame('complete', $this->service()->resumeCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame([], $this->service()->cleanupAttemptsForViewer($this->manager)['attempts']);
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->service()->cleanupAttemptsForViewer($this->manager));
    }    public function test_foreign_attachment_is_not_hydrated_before_exact_inventory_allocation_or_inspection_parent_denial(): void
    {
        $item = $this->item(); $other = $this->item();
        foreach (['inventory', 'allocation', 'inspection'] as $type) {
            $parent = $type === 'inventory' ? $item : ($type === 'allocation' ? $this->issue($item)
                : \App\Models\PpeInspection::findOrFail($this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass']))['source_id']));
            $wrong = $type === 'inventory' ? $other : ($type === 'allocation' ? $this->issue($other)
                : \App\Models\PpeInspection::findOrFail($this->service()->inspect($other, $this->manager, $this->command($other, ['result' => 'pass']))['source_id']));
            $class = match ($type) { 'inventory' => PpeAttachment::class, 'allocation' => \App\Models\PpeAllocationAttachment::class, 'inspection' => \App\Models\PpeInspectionAttachment::class };
            $foreign = match ($type) { 'inventory' => 'ppe_inventory_id', 'allocation' => 'ppe_allocation_id', 'inspection' => 'ppe_inspection_id' };
            $file = $class::create([$foreign => $parent->id, 'disk' => 'private', 'path' => 'private-'.$type.'.txt', 'original_name' => 'Foreign private metadata']);
            $hydrated = 0; $class::retrieved(function ($row) use ($file, &$hydrated) { if ((int) $row->id === (int) $file->id) { $hydrated++; } });
            DB::enableQueryLog(); DB::flushQueryLog();
            try { $this->denial(fn () => $this->service()->removeAttachment($type, $wrong->id, $file->id, $this->manager,
                $this->command($other, ['reason' => 'Wrong canonical parent'])), 404); $queries = DB::getQueryLog(); }
            finally { DB::disableQueryLog(); DB::flushQueryLog(); $class::flushEventListeners(); }
            $this->assertSame(0, $hydrated, 'Foreign private metadata must never reach model hydration.');
            $reads = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], '`'.$file->getTable().'`'));
            $this->assertNotEmpty($reads);
            foreach ($reads as $read) { $this->assertStringContainsString('`'.$foreign.'` = ?', $read['query']); }
        }
        $this->assertSame(0, PpeEvidenceCleanup::count());
    }
    public function test_private_blob_shared_by_another_attachment_table_survives_initial_cleanup_and_exact_retry(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $other = $this->item(); $allocation = $this->issue($other);
        $pointer = \App\Models\PpeAllocationAttachment::create(['ppe_allocation_id' => $allocation->id, 'disk' => 'private',
            'path' => $file->path, 'original_name' => 'Separate retained historical record']);
        $input = $this->command($item, ['reason' => 'Remove this record only']);
        $receipt = $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input);
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->where('attachment_type', 'inventory_attachment')->sole();
        $this->assertSame('unknown', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        $this->assertSame($receipt, $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $input));
        $this->assertSame('unknown', $cleanup->fresh()->state); $this->assertNotNull($pointer->fresh());
        $this->assertSame(3, $item->fresh()->source_revision);
    }
    public function test_soft_deleted_other_private_pointer_is_retained_and_not_assumed_to_release_shared_bytes(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $other = $this->item(); $pointer = PpeAttachment::create(['ppe_inventory_id' => $other->id,
            'disk' => 'private', 'path' => $file->path, 'original_name' => 'Deleted historical pointer']);
        $pointer->delete();
        $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Remove active pointer']));
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('unknown', $this->service()->resumeCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('private')->assertExists($file->path); $this->assertSoftDeleted('ppe_attachments', ['id' => $pointer->id]);
    }
    public function test_unaccepted_or_other_accepted_staging_reference_blocks_private_purge(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $other = $this->item(); $this->upload($other); $attempt = \App\Models\PpeEvidenceStaging::where('root_id', $other->id)->sole();
        // Deliberate external corruption first vacates the UNIQUE path identity of the original stage.
        // Another contradictory retained attempt can then point to that path without weakening its unique constraint.
        DB::table('ppe_evidence_staging')->where('root_id', $item->id)->update(['path' => 'ppe_attachments/'.\Illuminate\Support\Str::uuid().'.bin']);
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['path' => $file->path]);
        $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Remove one source only']));
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('unknown', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['receipt_id' => null, 'state' => 'staged']);
        $this->assertSame('unknown', $this->service()->resumeCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('private')->assertExists($file->path);
    }
    public function test_inline_evidence_removal_can_purge_its_own_exact_accepted_staging_and_history_pointer(): void
    {
        $item = $this->item(['documents' => [['file' => UploadedFile::fake()->createWithContent('inline.txt', 'Inline bytes')]]]);
        $file = $item->attachments()->sole();
        $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Remove inline source']));
        Storage::disk('private')->assertMissing($file->path);
        $this->assertSame('complete', PpeEvidenceCleanup::where('attachment_id', $file->id)->sole()->state);
    }
    public function test_other_inline_immutable_history_pointer_keeps_shared_private_blob_unknown(): void
    {
        $item = $this->item(['documents' => [['file' => UploadedFile::fake()->createWithContent('one.txt', 'First bytes')],
            ['file' => UploadedFile::fake()->createWithContent('two.txt', 'Second bytes')]]]);
        $files = $item->attachments()->orderBy('id')->get(); $version = PpeSourceVersion::where('root_id', $item->id)->sole();
        $content = $version->content; $content['facts']['documents'][1]['after']['path'] = $files[0]->path;
        DB::table('ppe_source_versions')->where('id', $version->id)->update(['content' => json_encode($content),
            'content_digest' => \App\Services\HealthSafety\PpeCommandValues::digest($content)]);
        $this->service()->removeAttachment('inventory', $item->id, $files[0]->id, $this->manager, $this->command($item, ['reason' => 'Remove one historical reference']));
        $this->assertSame('unknown', PpeEvidenceCleanup::where('attachment_id', $files[0]->id)->sole()->state);
        Storage::disk('private')->assertExists($files[0]->path); Storage::disk('private')->assertExists($files[1]->path);
    }
    public function test_other_module_legacy_null_disk_pointer_blocks_purge_without_private_content_hydration(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        $event = \App\Models\HsEvent::factory()->create(['site_id' => $this->site->id]);
        $pointer = \App\Models\HsAttachment::create(['attachable_type' => $event->getMorphClass(), 'attachable_id' => $event->id,
            // hs_attachments.disk is NOT NULL (default 'private') in main's and Codex's schema, so the legacy pointer
            // carries the default disk; it still names the same file and must block the purge.
            'uploaded_by' => $this->worker->id, 'disk' => 'private', 'path' => $file->path, 'original_name' => 'OTHER-MODULE-PRIVATE-NAME']);
        $hydrated = 0; \App\Models\HsAttachment::retrieved(function () use (&$hydrated) { $hydrated++; });
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager, $this->command($item, ['reason' => 'Remove only PPE reference']));
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); \App\Models\HsAttachment::flushEventListeners(); }
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('unknown', $cleanup->state); $this->assertSame(0, $hydrated);
        $references = array_filter($queries, fn ($query) => str_contains($query['query'], '`hs_attachments`'));
        $this->assertNotEmpty($references);
        // An EXISTS probe's "select *" returns no columns; any real wide select of the pointer still fails.
        foreach ($references as $query) { $this->assertStringNotContainsString('original_name', $query['query']); $this->assertStringNotContainsString('select *', (string) preg_replace('/exists\s*\(\s*select \*/', 'exists (select 1', strtolower($query['query']))); }
        $dto = $this->service()->cleanupForViewer($this->manager, $cleanup->id);
        $this->assertStringNotContainsString('OTHER-MODULE-PRIVATE-NAME', json_encode($dto));
        Storage::disk('private')->assertExists($file->path); $this->assertNotNull($pointer->fresh());
    }
    public function test_orphan_retained_chemical_history_pointer_blocks_private_removal_without_being_certified(): void
    {
        $item = $this->item(); $file = PpeAttachment::findOrFail($this->upload($item)['source_id']);
        // Deliberate retained orphan history is not a chemical acceptance fixture or proof of permission.
        // Its exact path still prevents deletion, even if current SDS/staging pointers are missing.
        $content = ['source_before' => ['document_path' => $file->path, 'document_disk' => 'private']];
        DB::table('chemical_source_versions')->insert(['root_type' => 'substance', 'root_id' => 900001,
            'source_type' => 'sds', 'source_id' => 900002, 'aggregate_revision' => 1, 'source_schema_version' => 1,
            'command' => 'upload_sds', 'idempotency_key' => (string) Str::uuid(), 'actor_id' => $this->manager->id,
            'actor_snapshot' => json_encode(['id' => $this->manager->id]), 'content' => json_encode($content),
            'content_digest' => str_repeat('0', 64), 'recorded_at' => now()]);
        $receipt = $this->service()->removeAttachment('inventory', $item->id, $file->id, $this->manager,
            $this->command($item, ['reason' => 'Remove the active PPE record only']));
        $cleanup = PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('unknown', $cleanup->state); Storage::disk('private')->assertExists($file->path);
        $this->assertSame('unknown', $this->service()->resumeCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame(11, count($receipt));
    }
}
