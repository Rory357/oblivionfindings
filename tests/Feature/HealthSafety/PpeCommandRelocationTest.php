<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeCommandReceipt;
use App\Models\PpeEvidenceRelocationCleanup;
use App\Models\PpeEvidenceStaging;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeSourceVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual copy/acceptance/delivery boundaries; never a historical migration backfill. */
final class PpeCommandRelocationTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('public'); Storage::fake('private'); }
    public static function parents(): array { return [['inventory'], ['allocation'], ['inspection']]; }
    private function fixture(string $type = 'inventory'): array
    {
        $item = $this->item();
        $parent = match ($type) {
            'inventory' => $item, 'allocation' => $this->issue($item),
            'inspection' => \App\Models\PpeInspection::findOrFail($this->service()->inspect($item, $this->manager,
                $this->command($item, ['result' => 'pass']))['source_id']),
        };
        $class = match ($type) { 'inventory' => PpeAttachment::class, 'allocation' => PpeAllocationAttachment::class,
            'inspection' => PpeInspectionAttachment::class };
        $foreign = match ($type) { 'inventory' => 'ppe_inventory_id', 'allocation' => 'ppe_allocation_id', 'inspection' => 'ppe_inspection_id' };
        $folder = match ($type) { 'inventory' => 'ppe_attachments', 'allocation' => 'ppe_allocation_attachments', 'inspection' => 'ppe_inspection_attachments' };
        $path = $folder.'/'.str_repeat('a', 40).'.txt';
        Storage::disk('public')->put($path, 'Actual retained public bytes');
        $file = $class::create([$foreign => $parent->id, 'uploaded_by' => $this->worker->id, 'disk' => 'public', 'path' => $path,
            'original_name' => 'Original PPE evidence.txt', 'mime' => 'text/plain', 'size' => strlen('Actual retained public bytes'),
            'kind' => 'document', 'notes' => 'Original attributed note']);
        return [$item->fresh(), $parent, $file];
    }
    private function input($root): array { return $this->command($root, ['reason' => 'Move this actual retained public file into protected storage.']); }
    private function transfer(string $type, Model $parent, Model $file, array $input): array
    {
        return $this->service()->relocateLegacyAttachment($type, $parent->id, $file->id, $this->manager, $input);
    }
    private function mockDisks(string $which, callable $configure): array
    {
        $manager = Storage::getFacadeRoot(); $real = $manager->disk($which); $other = $manager->disk($which === 'public' ? 'private' : 'public');
        $disk = \Mockery::mock($real)->makePartial(); $configure($disk, $real);
        $facade = \Mockery::mock(); $facade->shouldReceive('disk')->with($which)->andReturn($disk);
        $facade->shouldReceive('disk')->with($which === 'public' ? 'private' : 'public')->andReturn($other);
        Storage::swap($facade); return [$manager, $real];
    }
    #[DataProvider('parents')]
    public function test_actual_per_file_transfer_preserves_original_identity_and_records_observed_origin_separately(string $type): void
    {
        [$root, $parent, $file] = $this->fixture($type); $before = $file->getAttributes(); $revision = $root->source_revision;
        $receipt = $this->transfer($type, $parent, $file, $this->input($root));
        $fresh = $file->fresh(); $this->assertSame(11, count($receipt)); $this->assertSame('relocate_attachment', $receipt['command']);
        $this->assertSame($file->id, $receipt['source_id']); $this->assertSame($revision + 1, $root->fresh()->source_revision);
        foreach (['id', 'uploaded_by', 'original_name', 'mime', 'size', 'kind', 'notes', 'created_at'] as $field) {
            $this->assertSame($before[$field], $fresh->getAttributes()[$field], $field);
        }
        $this->assertSame('private', $fresh->disk); $this->assertNotSame($before['path'], $fresh->path);
        Storage::disk('private')->assertExists($fresh->path); Storage::disk('public')->assertMissing($before['path']);
        $version = PpeSourceVersion::findOrFail($receipt['source_version_id']);
        $origin = $version->content['facts']['legacy_origin'];
        $this->assertSame('legacy_unknown', $origin['original_attribution_state']);
        $this->assertSame($before['path'], $origin['path']); $this->assertSame($this->worker->id, $origin['original_metadata']['uploaded_by']);
        // MySQL's json column returns these keys in its own order (shorter keys first); the values must match exactly.
        $this->assertSame(\App\Support\JsonEvidence::canonicalise(['digest' => hash('sha256', 'Actual retained public bytes'), 'size' => strlen('Actual retained public bytes')]), \App\Support\JsonEvidence::canonicalise($origin['observed_bytes']));
        $this->assertSame(\App\Support\JsonEvidence::canonicalise($origin['observed_bytes']), \App\Support\JsonEvidence::canonicalise($version->content['facts']['file_evidence']));
        $cleanup = PpeEvidenceRelocationCleanup::where('receipt_id', PpeCommandReceipt::where('idempotency_key', $receipt['idempotency_key'])->sole()->id)->sole();
        $projection = $this->service()->relocationCleanupForViewer($this->manager, $cleanup->id);
        $this->assertSame('complete', $projection['public_copy_cleanup']); $this->assertTrue($projection['private_reference_recorded']);
        $this->assertArrayNotHasKey('removed_from_record', $projection);
        $this->assertSame('accepted', PpeEvidenceStaging::where('idempotency_key', $receipt['idempotency_key'])->sole()->state);
    }
    public function test_exact_retry_reuses_acceptance_after_later_root_revision_without_copy_or_original_receipt_change(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        $receipt = $this->transfer('inventory', $parent, $file, $input);
        $this->service()->updateInventory($root, $this->manager, $this->command($root, ['location' => 'Later same-Site location']));
        $this->assertSame($receipt, $this->transfer('inventory', $parent, $file, $input));
        $this->assertSame($receipt, $this->service()->confirmationForKey($this->manager, strtoupper($input['idempotency_key'])));
        $this->assertSame(1, PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, [...$input, 'reason' => 'Different intent']), 'idempotency_key');
    }
    public function test_public_cleanup_failure_is_discoverable_and_retry_does_not_change_acceptance_or_revision(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $receipt = $this->transfer('inventory', $parent, $file, $input); }
        finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $this->assertSame('pending', $cleanup->state);
        $queue = $this->service()->relocationCleanupAttemptsForViewer($this->manager);
        $this->assertSame($cleanup->id, $queue['attempts'][0]['id']); $this->assertTrue($queue['attempts'][0]['can_retry']);
        foreach (['path', 'origin_path', 'destination_path', 'idempotency_key', 'file_digest', 'removed_from_record'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $queue['attempts'][0]);
        }
        $this->assertSame('complete', $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame($receipt, $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
    }
    public function test_changed_public_or_private_bytes_are_not_erased_by_cleanup(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $this->transfer('inventory', $parent, $file, $this->input($root)); }
        finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole();
        Storage::disk('public')->put($origin, 'Unattributed replacement');
        $this->assertSame('unknown', $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('public')->assertExists($origin);
        Storage::disk('public')->put($origin, 'Actual retained public bytes');
        Storage::disk('private')->delete($file->fresh()->path);
        $this->assertSame('unknown', $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('public')->assertExists($origin);
    }
    public function test_new_other_source_reference_blocks_public_cleanup_even_after_transfer(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $this->transfer('inventory', $parent, $file, $this->input($root)); }
        finally { Storage::swap($manager); }
        $other = $this->item(); PpeAttachment::create(['ppe_inventory_id' => $other->id, 'disk' => 'public', 'path' => $origin, 'original_name' => 'Other record.txt']);
        $this->assertSame('unknown', $this->service()->resumeRelocationCleanup($this->manager, PpeEvidenceRelocationCleanup::sole()->id)['state']);
        Storage::disk('public')->assertExists($origin);
    }
    public function test_false_copy_and_audit_refusal_keep_public_origin_and_durable_rejected_attempt(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root); $origin = $file->path;
        [$manager] = $this->mockDisks('private', fn ($disk) => $disk->shouldReceive('writeStream')->andReturn(false));
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { Storage::swap($manager); }
        $attempt = PpeEvidenceStaging::sole(); $this->assertNull($attempt->receipt_id); $this->assertSame('cleaned', $attempt->state);
        $this->assertSame('public', $file->fresh()->disk); Storage::disk('public')->assertExists($origin);
        AuditLog::creating(fn ($row) => $row->action === 'ppe.relocate_attachment' ? false : null);
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame(0, PpeCommandReceipt::where('command', 'relocate_attachment')->count());
        $this->assertSame(0, PpeEvidenceRelocationCleanup::count()); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame([], Storage::disk('private')->allFiles()); Storage::disk('public')->assertExists($origin);
    }
    public function test_public_mutation_during_copy_rolls_back_private_reference_and_keeps_attempt_identity(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path; $input = $this->input($root);
        [$manager] = $this->mockDisks('private', function ($disk, $real) use ($origin) {
            $disk->shouldReceive('writeStream')->andReturnUsing(function ($path, $stream) use ($real, $origin) {
                $result = $real->writeStream($path, $stream); Storage::disk('public')->put($origin, 'Changed during copy'); return $result;
            });
        });
        try { $this->validation(fn () => $this->transfer('inventory', $parent, $file, $input), 'attachment'); }
        finally { Storage::swap($manager); }
        $this->assertSame('public', $file->fresh()->disk); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertNull(PpeEvidenceStaging::sole()->receipt_id); $this->assertCount(0, Storage::disk('private')->allFiles());
    }
    public function test_missing_unexpected_or_size_mismatched_origin_refuses_before_any_reservation(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        Storage::disk('public')->delete($origin);
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)), 'attachment');
        Storage::disk('public')->put($origin, 'Actual retained public bytes');
        $file->update(['size' => 999]);
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)), 'attachment');
        $file->update(['size' => strlen('Actual retained public bytes'), 'path' => 'ppe_attachments/../../unrelated.txt']);
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)), 'attachment');
        $this->assertSame(0, PpeEvidenceStaging::count()); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_shared_public_path_or_cross_parent_cannot_be_promoted(): void
    {
        [$root, $parent, $file] = $this->fixture(); $other = $this->item();
        // ppe_attachments.disk is NOT NULL (default 'public'): the other record shares the same legacy public path.
        PpeAttachment::create(['ppe_inventory_id' => $other->id, 'disk' => 'public', 'path' => $file->path, 'original_name' => 'Other retained source']);
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)), 'attachment');
        $this->denial(fn () => $this->transfer('inventory', $other, $file, $this->input($other)), 404);
        $constraint = app(\App\Services\HealthSafety\PpeAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->service()->relocationConstraint($other->fresh(), $file->fresh()));
        $this->assertFalse($constraint['allowed']); $this->assertStringNotContainsString($file->path, $constraint['blocked_reason']);
        $this->assertSame(0, PpeEvidenceStaging::count());
    }
    public function test_typed_evidence_or_downgraded_retained_history_is_never_recertified_as_legacy(): void
    {
        [$root, $parent, $file] = $this->fixture();
        $receipt = $this->transfer('inventory', $parent, $file, $this->input($root));
        DB::table('ppe_attachments')->where('id', $file->id)->update(['source_schema_version' => 0, 'source_revision' => 0, 'disk' => 'public']);
        $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)));
        $this->assertSame(1, PpeCommandReceipt::where('command', 'relocate_attachment')->count());
        $this->assertSame($receipt['version'], $root->fresh()->source_revision);
    }
    public function test_stale_current_authority_withdrawal_and_other_actor_recovery_preserve_public_source(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        $this->service()->updateInventory($root, $this->manager, $this->command($root, ['location' => 'Later source']));
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $input), 'expected_version');
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)));
        $this->assertSame('public', $file->fresh()->disk); $this->assertSame(0, PpeEvidenceStaging::count());
    }
    public function test_cleanup_recovery_is_actor_site_current_authority_bound_and_audit_corruption_never_purges(): void
    {
        [$root, $parent, $file] = $this->fixture();
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $receipt = $this->transfer('inventory', $parent, $file, $this->input($root)); }
        finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $other = $this->staff(['hazards.manage']);
        $this->denial(fn () => $this->service()->relocationCleanupForViewer($other, $cleanup->id), 404);
        $this->assertSame([], $this->service()->relocationCleanupAttemptsForViewer($other)['attempts']);
        $auditId = PpeCommandReceipt::where('idempotency_key', $receipt['idempotency_key'])->sole()->audit_log_id;
        DB::table('audit_logs')->where('id', $auditId)->update(['action' => 'unrelated.action']);
        $this->integrity(fn () => $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id));
        Storage::disk('public')->assertExists($file->path);
    }
    public function test_cleanup_delivery_write_veto_never_claims_persisted_completion(): void
    {
        [$root, $parent, $file] = $this->fixture();
        PpeEvidenceRelocationCleanup::updating(fn () => false);
        try { $this->transfer('inventory', $parent, $file, $this->input($root)); }
        finally { PpeEvidenceRelocationCleanup::flushEventListeners(); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $this->assertSame('pending', $cleanup->state);
        $this->assertSame('complete', $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id)['state']);
        $this->assertSame(2, $root->fresh()->source_revision);
    }
    public function test_outer_transaction_refusal_and_reservation_veto_do_not_copy_or_delete_any_bytes(): void
    {
        [$root, $parent, $file] = $this->fixture(); DB::beginTransaction();
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $this->input($root))); }
        finally { DB::rollBack(); }
        PpeEvidenceStaging::creating(fn () => false);
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $this->input($root))); }
        finally { PpeEvidenceStaging::flushEventListeners(); }
        $this->assertSame(0, PpeEvidenceStaging::count()); Storage::disk('public')->assertExists($file->path);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_retained_transfer_intent_cannot_reuse_failed_uuid_for_changed_reason_or_nonfile_command(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        AuditLog::creating(fn ($row) => $row->action === 'ppe.relocate_attachment' ? false : null);
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { AuditLog::flushEventListeners(); }
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, [...$input, 'reason' => 'Different reason']), 'idempotency_key');
        $this->validation(fn () => $this->service()->updateInventory($root, $this->manager,
            ['idempotency_key' => $input['idempotency_key'], 'expected_version' => $input['expected_version'], 'location' => 'Different verb']), 'idempotency_key');
        $this->assertSame(1, PpeEvidenceStaging::count()); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame(0, PpeCommandReceipt::where('command', 'relocate_attachment')->count());
    }
    public function test_copy_starts_with_physically_retained_reservation_and_public_origin_is_not_deleted_before_acceptance(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root); $origin = $file->path; $seen = false;
        [$manager] = $this->mockDisks('private', function ($disk, $real) use ($input, &$seen) {
            $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream) use ($real, $input, &$seen) {
                $attempt = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
                $this->assertSame($path, $attempt->path); $this->assertSame('staged', $attempt->state); $this->assertNull($attempt->receipt_id);
                $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
                $seen = true; return $real->writeStream($path, $stream);
            });
        });
        AuditLog::creating(function ($audit) use ($origin) {
            if ($audit->action === 'ppe.relocate_attachment') {
                Storage::disk('public')->assertExists($origin); $this->assertSame(0, PpeEvidenceRelocationCleanup::count());
            }
        });
        try { $this->transfer('inventory', $parent, $file, $input); }
        finally { Storage::swap($manager); AuditLog::flushEventListeners(); }
        $this->assertTrue($seen); Storage::disk('public')->assertMissing($origin);
    }
    public function test_private_copy_read_failure_and_attachment_write_veto_roll_back_reference_and_keep_public_origin(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root); $origin = $file->path;
        [$manager] = $this->mockDisks('private', fn ($disk) => $disk->shouldReceive('readStream')->andReturn(false));
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { Storage::swap($manager); }
        $attempt = PpeEvidenceStaging::sole(); $this->assertNull($attempt->receipt_id);
        $this->assertSame('cleanup_pending', $attempt->state);
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        PpeAttachment::updating(fn () => false);
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { PpeAttachment::flushEventListeners(); }
        Storage::disk('public')->assertExists($origin); $this->assertSame('public', $file->fresh()->disk);
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, PpeEvidenceRelocationCleanup::count());
    }
    public function test_public_read_failure_and_oversized_file_refuse_before_any_private_reservation(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('readStream')->andReturn(false));
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { Storage::swap($manager); }
        Storage::disk('public')->put($file->path, str_repeat('x', 20971521));
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, $input), 'attachment');
        $this->assertSame(0, PpeEvidenceStaging::count()); $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(1, $root->fresh()->source_revision);
    }
    public function test_private_transfer_rollback_with_failed_purge_retains_retryable_attempt_without_public_loss(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        [$manager] = $this->mockDisks('private', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        AuditLog::creating(fn ($row) => $row->action === 'ppe.relocate_attachment' ? false : null);
        try { $this->integrity(fn () => $this->transfer('inventory', $parent, $file, $input)); }
        finally { Storage::swap($manager); AuditLog::flushEventListeners(); }
        $attempt = PpeEvidenceStaging::sole(); $this->assertSame('cleanup_pending', $attempt->state);
        $dto = $this->service()->stagingForViewer($this->manager, $attempt->id); $this->assertFalse($dto['accepted']);
        Storage::disk('public')->assertExists($file->path); Storage::disk('private')->assertExists($attempt->path);
        $this->assertSame('cleaned', $this->service()->resumeStagingCleanup($this->manager, $attempt->id)['state']);
        $this->assertSame('public', $file->fresh()->disk); $this->assertSame(1, $root->fresh()->source_revision);
    }
    public function test_original_current_site_or_manage_revocation_denies_exact_transfer_replay_and_delivery_recovery(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $this->transfer('inventory', $parent, $file, $input); }
        finally { Storage::swap($manager); }
        $cleanup = PpeEvidenceRelocationCleanup::sole(); $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->transfer('inventory', $parent, $file, $input));
        $this->denial(fn () => $this->service()->resumeRelocationCleanup($this->manager, $cleanup->id));
        $permission = \App\Models\Permission::where('key', 'hazards.manage')->sole();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $foreign = \App\Models\Site::factory()->create(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->denial(fn () => $this->transfer('inventory', $parent, $file, $input), 404);
        $this->denial(fn () => $this->service()->relocationCleanupForViewer($this->manager, $cleanup->id), 404);
        $this->assertSame([], $this->service()->relocationCleanupAttemptsForViewer($this->manager)['attempts']);
        Storage::disk('public')->assertExists($file->path);
    }
    public function test_required_reason_strict_payload_and_retained_origin_cleanup_prevent_unreviewed_mutation(): void
    {
        [$root, $parent, $file] = $this->fixture(); $input = $this->input($root);
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, array_diff_key($input, ['reason' => true])), 'reason');
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, [...$input, 'reason' => '  ']), 'reason');
        $this->validation(fn () => $this->transfer('inventory', $parent, $file, [...$input, '_legacy_origin' => ['path' => 'forged']]), 'command');
        $this->assertSame(0, PpeEvidenceStaging::count());
        $this->transfer('inventory', $parent, $file, $input);
        $migration = require database_path('migrations/2026_10_12_200000_create_ppe_command_history.php');
        try { $migration->down(); $this->fail('Retained transfer delivery must block destructive rollback.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame(1, PpeEvidenceRelocationCleanup::count());
        $this->integrity(fn () => PpeEvidenceRelocationCleanup::sole()->update(['origin_path' => 'ppe_attachments/forged.txt']));
    }
    #[DataProvider('parents')]
    public function test_foreign_parent_transfer_never_hydrates_attachment_metadata_before_denial(string $type): void
    {
        [$root, $parent, $file] = $this->fixture($type); $other = $this->item();
        $wrong = match ($type) { 'inventory' => $other, 'allocation' => $this->issue($other),
            'inspection' => \App\Models\PpeInspection::findOrFail($this->service()->inspect($other, $this->manager,
                $this->command($other, ['result' => 'pass']))['source_id']) };
        $class = $file::class; $hydrated = 0;
        $class::retrieved(function ($row) use ($file, &$hydrated) { if ((int) $row->id === (int) $file->id) { $hydrated++; } });
        try { $this->denial(fn () => $this->transfer($type, $wrong, $file, $this->input($other)), 404); }
        finally { $class::flushEventListeners(); }
        $this->assertSame(0, $hydrated); $this->assertSame(0, PpeEvidenceStaging::count());
        Storage::disk('public')->assertExists($file->path); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_exact_parent_move_after_reservation_refuses_copy_before_foreign_metadata_rehydration(): void
    {
        [$root, $parent, $file] = $this->fixture(); $other = $this->item(); $afterReservation = false; $hydratedAfterMove = 0;
        PpeEvidenceStaging::created(function ($row) use ($file, $other, &$afterReservation) {
            if ($row->command === 'relocate_attachment') {
                DB::table('ppe_attachments')->where('id', $file->id)->update(['ppe_inventory_id' => $other->id]);
                $afterReservation = true;
            }
        });
        PpeAttachment::retrieved(function ($row) use ($file, &$afterReservation, &$hydratedAfterMove) {
            if ($afterReservation && (int) $row->id === (int) $file->id) { $hydratedAfterMove++; }
        });
        try { $this->denial(fn () => $this->transfer('inventory', $parent, $file, $this->input($root)), 404); }
        finally { PpeAttachment::flushEventListeners(); PpeEvidenceStaging::flushEventListeners(); }
        $this->assertTrue($afterReservation); $this->assertSame(0, $hydratedAfterMove);
        $this->assertSame(0, PpeCommandReceipt::where('command', 'relocate_attachment')->count());
        $this->assertSame(1, $root->fresh()->source_revision); Storage::disk('public')->assertExists($file->path);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_pending_public_origin_cleanup_keeps_private_copy_until_origin_then_removal_delivery_complete(): void
    {
        [$root, $parent, $file] = $this->fixture(); $origin = $file->path;
        [$manager] = $this->mockDisks('public', fn ($disk) => $disk->shouldReceive('delete')->andReturn(false));
        try { $transfer = $this->transfer('inventory', $parent, $file, $this->input($root)); }
        finally { Storage::swap($manager); }
        $destination = $file->fresh()->path; $originCleanup = PpeEvidenceRelocationCleanup::sole();
        $remove = $this->service()->removeAttachment('inventory', $root->id, $file->id, $this->manager,
            $this->command($root, ['reason' => 'Remove active reference after transfer']));
        $cleanup = \App\Models\PpeEvidenceCleanup::where('attachment_id', $file->id)->sole();
        $this->assertSame('unknown', $cleanup->state); Storage::disk('private')->assertExists($destination); Storage::disk('public')->assertExists($origin);
        $this->assertSame('complete', $this->service()->resumeRelocationCleanup($this->manager, $originCleanup->id)['state']);
        Storage::disk('public')->assertMissing($origin); Storage::disk('private')->assertExists($destination);
        $this->assertSame('complete', $this->service()->resumeCleanup($this->manager, $cleanup->id)['state']);
        Storage::disk('private')->assertMissing($destination);
        $this->assertSame($transfer, PpeCommandReceipt::where('idempotency_key', $transfer['idempotency_key'])->sole()->accepted_result);
        $this->assertSame($remove, PpeCommandReceipt::where('idempotency_key', $remove['idempotency_key'])->sole()->accepted_result);
        $this->assertSame(3, $root->fresh()->source_revision);
    }
    public function test_unrelated_origin_delivery_cannot_escape_exact_destination_path_and_block_normal_removal(): void
    {
        [$originRoot, $originParent, $legacy] = $this->fixture();
        $this->transfer('inventory', $originParent, $legacy, $this->input($originRoot));
        $this->assertSame('complete', PpeEvidenceRelocationCleanup::sole()->state);
        $other = $this->item();
        $upload = $this->service()->uploadAttachment('inventory', $other->id, $this->manager,
            $this->command($other, ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('independent.txt', 'Independent private bytes')]));
        $file = PpeAttachment::findOrFail($upload['source_id']); $path = $file->path;
        $this->service()->removeAttachment('inventory', $other->id, $file->id, $this->manager,
            $this->command($other, ['reason' => 'Remove only this independent record']));
        $this->assertSame('complete', \App\Models\PpeEvidenceCleanup::where('attachment_id', $file->id)->sole()->state);
        Storage::disk('private')->assertMissing($path);
        Storage::disk('private')->assertExists($legacy->fresh()->path);
    }
}
