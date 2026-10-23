<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeAttachment;
use App\Models\PpeCommandReceipt;
use App\Models\PpeEvidenceStaging;
use App\Services\HealthSafety\PpeCommandValues;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PpeCommandStagingTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function input($item, ?string $key = null): array
    {
        return $this->command($item, ['file' => UploadedFile::fake()->createWithContent('actual.txt', 'Reserved actual bytes')], $key);
    }
    private function failedUpload($item, array $input): PpeEvidenceStaging
    {
        AuditLog::creating(fn ($row) => $row->action === 'ppe.upload_attachment' ? false : null);
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input)); }
        finally { AuditLog::flushEventListeners(); }
        return PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
    }
    private function mockDisk(callable $configure): array
    {
        $manager = Storage::getFacadeRoot(); $real = $manager->disk('private');
        $disk = \Mockery::mock($real)->makePartial(); $configure($disk, $real);
        Storage::swap(\Mockery::mock()->shouldReceive('disk')->with('private')->andReturn($disk)->getMock());
        return [$manager, $real];
    }
    public function test_reservation_commits_before_first_filesystem_write_and_acceptance_binds_exact_attempt(): void
    {
        $item = $this->item(); $input = $this->input($item); $seen = false;
        [$manager] = $this->mockDisk(function ($disk, $real) use (&$seen, $input) {
            $disk->shouldReceive('putFileAs')->once()->andReturnUsing(function ($folder, $file, $name) use (&$seen, $input, $real) {
                $row = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
                $this->assertSame('staged', $row->state); $this->assertNull($row->receipt_id);
                $this->assertSame($folder.'/'.$name, $row->path); $seen = true;
                return $real->putFileAs($folder, $file, $name);
            });
        });
        try { $receipt = $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input); }
        finally { Storage::swap($manager); }
        $this->assertTrue($seen); $row = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->assertSame('accepted', $row->state); $this->assertNotNull($row->receipt_id);
        $this->assertSame(['id' => $row->id, 'state' => 'accepted', 'accepted' => true], $this->service()->stagingForViewer($this->manager, $row->id));
        $this->assertSame(11, count($receipt));
    }
    public function test_false_store_is_not_accepted_and_planned_path_remains_durably_accounted_for(): void
    {
        $item = $this->item(); $input = $this->input($item);
        [$manager] = $this->mockDisk(fn ($disk) => $disk->shouldReceive('putFileAs')->andReturn(false));
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input)); }
        finally { Storage::swap($manager); }
        $row = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->assertSame('cleaned', $row->state); $this->assertNull($row->receipt_id);
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_failed_rollback_delete_is_pending_then_manual_retry_cleans_without_lifecycle_revision(): void
    {
        $item = $this->item(); $input = $this->input($item);
        [$manager, $real] = $this->mockDisk(fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $row = $this->failedUpload($item, $input); }
        finally { Storage::swap($manager); }
        $this->assertSame('cleanup_pending', $row->state); $this->assertTrue($real->exists($row->path));
        $this->assertSame(['id' => $row->id, 'state' => 'cleaned', 'accepted' => false], $this->service()->resumeStagingCleanup($this->manager, $row->id));
        $this->assertFalse($real->exists($row->path)); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_failed_byte_read_keeps_unknown_delivery_discoverable_until_retry(): void
    {
        $item = $this->item(); $input = $this->input($item);
        [$manager] = $this->mockDisk(fn ($disk) => $disk->shouldReceive('readStream')->andReturn(false));
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input)); }
        finally { Storage::swap($manager); }
        $row = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->assertSame('cleanup_pending', $row->state); $this->assertNull($row->receipt_id);
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $row->id)['state']);
    }
    public function test_write_veto_of_reservation_prevents_filesystem_write(): void
    {
        $item = $this->item(); PpeEvidenceStaging::creating(fn () => false);
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $this->input($item))); }
        finally { PpeEvidenceStaging::flushEventListeners(); }
        $this->assertSame([], Storage::disk('private')->allFiles()); $this->assertSame(0, PpeEvidenceStaging::count());
    }
    public function test_crash_before_and_after_byte_write_can_be_inspected_without_claiming_acceptance(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        // Recreate the durable pre-file crash state without adding a domain acceptance.
        DB::table('ppe_evidence_staging')->where('id', $row->id)->update(['state' => 'staged']);
        $this->assertSame(['id' => $row->id, 'state' => 'staged', 'accepted' => false], $this->service()->stagingForViewer($this->manager, $row->id));
        Storage::disk('private')->put($row->path, 'Reserved actual bytes');
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $row->id)['state']);
        $this->assertSame(0, PpeAttachment::count()); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_stale_rejection_before_reservation_does_not_write_files_or_attempts(): void
    {
        $item = $this->item(); $input = $this->input($item);
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['location' => 'New work']));
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input), 'expected_version');
        $this->assertSame(0, PpeEvidenceStaging::count()); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_staged_attempt_current_grant_or_site_revocation_denies_recovery(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->service()->stagingForViewer($this->manager, $row->id));
        $this->denial(fn () => $this->service()->resumeStagingCleanup($this->manager, $row->id));
        $this->assertNull($row->fresh()->receipt_id);
    }
    public function test_other_actor_or_forged_identity_cannot_inspect_or_cleanup_attempt(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        $other = $this->staff(['hazards.manage']);
        $this->denial(fn () => $this->service()->stagingForViewer($other, $row->id), 404);
        DB::table('ppe_evidence_staging')->where('id', $row->id)->update(['path' => 'ppe_attachments/'.Str::uuid().'.bin']);
        $this->integrity(fn () => $this->service()->resumeStagingCleanup($this->manager, $row->id));
    }
    public function test_accepted_or_referenced_bytes_are_never_rejected_attempt_cleanup(): void
    {
        $item = $this->item(); $input = $this->input($item);
        $receipt = $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input);
        $row = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->validation(fn () => $this->service()->resumeStagingCleanup($this->manager, $row->id), 'staging');
        Storage::disk('private')->assertExists(PpeAttachment::findOrFail($receipt['source_id'])->path);
        $this->assertSame($receipt, $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input));
        $this->assertSame(1, PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_changed_unaccepted_bytes_are_unknown_not_deleted_or_certified(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        Storage::disk('private')->put($row->path, 'Unattributed different bytes');
        $this->assertSame(['id' => $row->id, 'state' => 'unknown', 'accepted' => false], $this->service()->resumeStagingCleanup($this->manager, $row->id));
        Storage::disk('private')->assertExists($row->path);
    }
    public function test_failed_attempt_and_later_same_intent_acceptance_have_distinct_protected_paths(): void
    {
        $item = $this->item(); $input = $this->input($item); $failed = $this->failedUpload($item, $input);
        $receipt = $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input);
        $accepted = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->whereNotNull('receipt_id')->sole();
        $this->assertNotSame($failed->path, $accepted->path);
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $failed->id)['state']);
        Storage::disk('private')->assertExists($accepted->path); $this->assertSame(11, count($receipt));
    }
    public function test_new_root_failed_attempt_retains_current_capture_authority_and_minimal_discovery(): void
    {
        $type = \App\Models\PpeType::factory()->create(); $key = (string) Str::uuid();
        AuditLog::creating(fn ($row) => $row->action === 'ppe.create_inventory' ? false : null);
        try { $this->integrity(fn () => $this->service()->createInventory($this->manager, ['idempotency_key' => $key,
            'site_id' => $this->site->id, 'ppe_type_id' => $type->id,
            'documents' => [['file' => UploadedFile::fake()->createWithContent('capture.txt', 'Capture attempt')]]])); }
        finally { AuditLog::flushEventListeners(); }
        $row = PpeEvidenceStaging::where('idempotency_key', $key)->sole(); $this->assertNull($row->root_id);
        $dto = $this->service()->stagingAttemptsForKey($this->manager, strtoupper($key));
        $this->assertSame([['id' => $row->id, 'state' => 'cleaned', 'accepted' => false]], $dto['attempts']);
        $this->assertNull($dto['next_cursor']); $this->assertArrayNotHasKey('path', $dto);
        $this->assertSame([], $this->service()->stagingAttemptsForKey($this->staff(['hazards.manage']), $key)['attempts']);
    }
    public function test_outer_transaction_refusal_creates_no_reservation(): void
    {
        $item = $this->item(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $this->input($item))); }
        finally { DB::rollBack(); }
        $this->assertSame(0, PpeEvidenceStaging::count()); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_current_unresolved_list_is_actor_site_and_source_bound_without_private_identity_or_uuid(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        DB::table('ppe_evidence_staging')->where('id', $row->id)->update(['state' => 'staged']);
        $dto = $this->service()->stagingAttemptsForViewer($this->manager);
        $this->assertSame($row->id, $dto['attempts'][0]['id']);
        $this->assertFalse($dto['attempts'][0]['accepted']); $this->assertTrue($dto['attempts'][0]['can_retry']);
        foreach (['path', 'intent', 'original_name', 'idempotency_key', 'site_id', 'source_id'] as $private) {
            $this->assertArrayNotHasKey($private, $dto['attempts'][0]);
        }
        $this->assertSame([], $this->service()->stagingAttemptsForViewer($this->staff(['hazards.manage']))['attempts']);
        $foreign = \App\Models\Site::factory()->create(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->assertSame([], $this->service()->stagingAttemptsForViewer($this->manager)['attempts']);
        $this->denial(fn () => $this->service()->resumeStagingCleanup($this->manager, $row->id), 404);
    }
    public function test_reservation_alone_blocks_destructive_down_even_when_source_acceptance_was_rejected(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        $this->assertNull($row->receipt_id);
        DB::table('ppe_command_receipts')->delete(); DB::table('ppe_source_versions')->delete();
        DB::table('ppe_inventory')->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $migration = require database_path('migrations/2026_10_12_200000_create_ppe_command_history.php');
        try { $migration->down(); $this->fail('Expected retained reservation refusal.'); }
        catch (\RuntimeException $failure) { $this->assertStringContainsString('retained', $failure->getMessage()); }
        $this->assertDatabaseHas('ppe_evidence_staging', ['id' => $row->id]);
    }    public function test_later_cleanup_retains_attempt_identity_while_mutable_delivery_timestamp_advances(): void
    {
        $item = $this->item(); $row = $this->failedUpload($item, $this->input($item));
        $identity = $row->identity(); $recorded = $row->created_at->toIso8601String();
        $this->travel(2)->seconds();
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $row->id)['state']);
        $fresh = $row->fresh(); $this->assertSame($identity, $fresh->identity());
        $this->assertSame($recorded, $fresh->created_at->toIso8601String());
        $this->assertGreaterThan($row->updated_at->getTimestamp(), $fresh->updated_at->getTimestamp());
    }    public function test_failed_upload_uuid_cannot_change_bytes_actor_parent_or_verb_even_without_acceptance(): void
    {
        $item = $this->item(); $input = $this->input($item); $attempt = $this->failedUpload($item, $input);
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager,
            [...$input, 'file' => UploadedFile::fake()->createWithContent('actual.txt', 'Different bytes')]), 'idempotency_key');
        $other = $this->staff(['hazards.manage']);
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $other, $input), 'idempotency_key');
        $otherItem = $this->item();
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $otherItem->id, $this->manager, $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager,
            ['idempotency_key' => $input['idempotency_key'], 'expected_version' => 1, 'location' => 'Different verb']), 'idempotency_key');
        $this->assertSame(1, PpeEvidenceStaging::count()); $this->assertNull($attempt->fresh()->receipt_id);
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, $item->fresh()->source_revision); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_corrupted_retained_attempt_cannot_be_ignored_when_same_intent_retries(): void
    {
        $item = $this->item(); $input = $this->input($item); $attempt = $this->failedUpload($item, $input);
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['source_id' => $item->id + 1000]);
        $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input));
        $this->assertSame(1, PpeEvidenceStaging::count());
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_hr_document_exact_pointer_blocks_rejected_staging_purge_without_hydrating_private_metadata(): void
    {
        $item = $this->item(); $input = $this->input($item); $attempt = $this->failedUpload($item, $input);
        Storage::disk('private')->put($attempt->path, 'Reserved actual bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        $document = \App\Domain\Hr\Models\HrDocument::factory()->create([
            'employee_profile_id' => $this->worker->hrEmployeeProfile->id, 'storage_disk' => 'private',
            'storage_path' => $attempt->path, 'title' => 'PRIVATE-HR-REFERENCE']);
        $hydrated = 0;
        \App\Domain\Hr\Models\HrDocument::retrieved(function () use (&$hydrated) { $hydrated++; });
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $dto = $this->service()->resumeStagingCleanup($this->manager, $attempt->id);
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); \App\Domain\Hr\Models\HrDocument::flushEventListeners(); }
        $this->assertSame(['id' => $attempt->id, 'state' => 'unknown', 'accepted' => false], $dto);
        $this->assertSame(0, $hydrated); $this->assertStringNotContainsString('PRIVATE-HR-REFERENCE', json_encode($dto));
        $references = array_filter($queries, fn ($query) => str_contains($query['query'], 'hr_documents'));
        $this->assertNotEmpty($references);
        foreach ($references as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertStringNotContainsString('title', $query['query']);
        }
        Storage::disk('private')->assertExists($attempt->path); $this->assertNotNull($document->fresh());
        DB::table('hr_documents')->where('id', $document->id)->update(['storage_disk' => 'local']);
        // The actual installed local and private disks share app/private; the legacy alias cannot bypass retention.
        $this->assertSame(config('filesystems.disks.private.root'), config('filesystems.disks.local.root'));
        $this->assertSame('unknown', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertExists($attempt->path);
        // Retrying the original UUID uses a distinct reservation; it cannot consume the shared old path.
        $receipt = $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input);
        $accepted = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->whereNotNull('receipt_id')->sole();
        $this->assertNotSame($attempt->path, $accepted->path);
        $this->assertSame($receipt, $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input));
        $this->assertSame('unknown', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertExists($attempt->path); Storage::disk('private')->assertExists($accepted->path);
    }
    public function test_another_module_durable_staging_pointer_blocks_rejected_byte_cleanup_until_removed(): void
    {
        $item = $this->item(); $attempt = $this->failedUpload($item, $this->input($item));
        Storage::disk('private')->put($attempt->path, 'Reserved actual bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        $pointer = DB::table('it_attachment_storage_intents')->insertGetId([
            'intent_uuid' => (string) Str::uuid(), 'path' => $attempt->path, 'actor_user_id' => $this->worker->id,
            'parent_type' => 'support_request', 'parent_id' => 1, 'content_sha256' => $attempt->file_digest,
            'original_name_sha256' => hash('sha256', 'OTHER-PRIVATE-NAME'), 'size' => $attempt->file_size,
            'state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('unknown', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertExists($attempt->path);
        // A retained softdeleted/cleaned technical pointer is still not proof that this stage owns the bytes alone.
        DB::table('it_attachment_storage_intents')->where('id', $pointer)->update(['state' => 'cleaned', 'deleted_at' => now()]);
        $this->assertSame('unknown', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertExists($attempt->path);
        DB::table('it_attachment_storage_intents')->where('id', $pointer)->delete();
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertMissing($attempt->path);
        $this->assertNull($attempt->fresh()->receipt_id); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_other_removal_delivery_pointer_keeps_rejected_stage_unknown_even_when_delivery_is_complete(): void
    {
        $item = $this->item(); $attempt = $this->failedUpload($item, $this->input($item));
        Storage::disk('private')->put($attempt->path, 'Reserved actual bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        $other = $this->item(); $accepted = $this->service()->uploadAttachment('inventory', $other->id, $this->manager, $this->input($other));
        $this->service()->removeAttachment('inventory', $other->id, $accepted['source_id'], $this->manager,
            $this->command($other, ['reason' => 'Remove that active record']));
        $cleanup = \App\Models\PpeEvidenceCleanup::where('attachment_id', $accepted['source_id'])->sole();
        $this->assertSame('complete', $cleanup->state);
        // Deliberately contradictory durable delivery identity must preserve the path, not certify this other stage.
        DB::table('ppe_evidence_cleanups')->where('id', $cleanup->id)->update(['path' => $attempt->path]);
        $this->assertSame('unknown', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        Storage::disk('private')->assertExists($attempt->path);
        $this->assertNull($attempt->fresh()->receipt_id); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_orphan_chemical_immutable_document_history_protects_unaccepted_stage_bytes(): void
    {
        $item = $this->item(); $attempt = $this->failedUpload($item, $this->input($item));
        Storage::disk('private')->put($attempt->path, 'Reserved actual bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        // A deliberately orphaned retained pointer remains a blocker, never a verified source/history claim.
        DB::table('chemical_source_versions')->insert(['root_type' => 'substance', 'root_id' => 900003,
            'source_type' => 'sds', 'source_id' => 900004, 'aggregate_revision' => 1, 'source_schema_version' => 1,
            'command' => 'upload_sds', 'idempotency_key' => (string) Str::uuid(), 'actor_id' => $this->manager->id,
            'actor_snapshot' => json_encode(['id' => $this->manager->id]),
            'content' => json_encode(['source_after' => ['document_path' => $attempt->path, 'document_disk' => 'private']]),
            'content_digest' => str_repeat('0', 64), 'recorded_at' => now()]);
        $dto = $this->service()->resumeStagingCleanup($this->manager, $attempt->id);
        $this->assertSame(['id' => $attempt->id, 'state' => 'unknown', 'accepted' => false], $dto);
        Storage::disk('private')->assertExists($attempt->path);
        $this->assertSame(1, $item->fresh()->source_revision); $this->assertNull($attempt->fresh()->receipt_id);
    }
}
