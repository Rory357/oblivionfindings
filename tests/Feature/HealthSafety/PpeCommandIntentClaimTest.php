<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeCommandIntentClaim;
use App\Models\PpeCommandReceipt;
use App\Models\PpeEvidenceStaging;
use App\Models\PpeInventory;
use App\Services\HealthSafety\PpeCommandValues;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Durable intent exclusion is separate from domain acceptance and byte delivery. */
final class PpeCommandIntentClaimTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function uploadInput($item): array
    {
        return $this->command($item, ['file' => UploadedFile::fake()->createWithContent('reviewed.txt', 'Reviewed intent bytes')]);
    }
    private function rejectedUpload($item, array $input): PpeEvidenceStaging
    {
        AuditLog::creating(fn ($row) => $row->action === 'ppe.upload_attachment' ? false : null);
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input)); }
        finally { AuditLog::flushEventListeners(); }
        return PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->sole();
    }
    public function test_actual_capture_changed_write_and_noop_each_retain_one_exact_immutable_claim(): void
    {
        $item = $this->item(); $capture = PpeCommandReceipt::where('root_id', $item->id)->where('command', 'create_inventory')->sole();
        $claim = PpeCommandIntentClaim::where('idempotency_key', $capture->idempotency_key)->sole();
        $this->assertNull($claim->root_id); $this->assertNull($claim->source_id); $this->assertNull($claim->expected_version);
        $this->assertSame($this->site->id, $claim->site_id);
        $input = $this->command($item, ['location' => 'Reviewed rack']);
        $receipt = $this->service()->updateInventory($item, $this->manager, $input);
        $this->assertSame($receipt, $this->service()->updateInventory($item, $this->manager, $input));
        $this->assertSame(1, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
        $noop = $this->service()->updateInventory($item, $this->manager, $this->command($item, ['location' => 'Reviewed rack']));
        $this->assertNull($noop['source_version_id']); $this->assertSame(2, $noop['version']);
        $claim = PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->assertSame(PpeCommandValues::digest($claim->identity()), $claim->identity_digest);
        $this->integrity(fn () => $claim->update(['command' => 'dispose']));
        $this->integrity(fn () => $claim->delete());
    }
    public function test_rejected_file_acceptance_keeps_durable_claim_and_exact_retry_uses_new_attempt_only(): void
    {
        $item = $this->item(); $input = $this->uploadInput($item); $attempt = $this->rejectedUpload($item, $input);
        $claim = PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->sole(); $identity = $claim->identity();
        $this->assertNull($attempt->receipt_id); $this->assertSame('cleaned', $attempt->state);
        $receipt = $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input);
        $this->assertSame($identity, $claim->fresh()->identity()); $this->assertSame(1, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
        $attempts = PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->orderBy('id')->get();
        $this->assertCount(2, $attempts); $this->assertNotSame($attempts[0]->path, $attempts[1]->path);
        $this->assertSame('accepted', $attempts[1]->state); $this->assertSame(11, count($receipt));
        $this->assertSame($receipt, $this->service()->confirmationForKey($this->manager, strtoupper($input['idempotency_key'])));
    }
    public function test_retained_claim_refuses_changed_actor_root_verb_or_bytes_before_new_reservation(): void
    {
        $item = $this->item(); $input = $this->uploadInput($item); $attempt = $this->rejectedUpload($item, $input);
        $other = $this->staff(['hazards.manage']); $otherItem = $this->item();
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $other, $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $otherItem->id, $this->manager, $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager,
            array_diff_key([...$input, 'location' => 'Different verb'], ['file' => true])), 'idempotency_key');
        $this->validation(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager,
            [...$input, 'file' => UploadedFile::fake()->createWithContent('reviewed.txt', 'Different bytes')]), 'idempotency_key');
        $this->assertSame(1, PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertNull($attempt->fresh()->receipt_id); $this->assertSame([], Storage::disk('private')->allFiles());
    }
    public function test_ordinary_transaction_failure_rolls_back_its_new_claim_without_clearing_file_claim(): void
    {
        $item = $this->item(); $fileInput = $this->uploadInput($item); $this->rejectedUpload($item, $fileInput);
        $input = $this->command($item, ['location' => 'Refused rack']);
        AuditLog::creating(fn ($row) => $row->action === 'ppe.update_inventory' ? false : null);
        try { $this->integrity(fn () => $this->service()->updateInventory($item, $this->manager, $input)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame(0, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, PpeCommandIntentClaim::where('idempotency_key', $fileInput['idempotency_key'])->count());
        $this->assertNull($item->fresh()->location); $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_claim_save_veto_prevents_any_new_bytes_reservation_or_source_acceptance(): void
    {
        $item = $this->item(); $input = $this->uploadInput($item);
        PpeCommandIntentClaim::creating(fn () => false);
        try { $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input)); }
        finally { PpeCommandIntentClaim::flushEventListeners(); }
        $this->assertSame(0, PpeEvidenceStaging::count()); $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_missing_or_corrupt_claim_never_reconstructs_receipt_or_purges_unaccepted_staging(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Recorded rack']);
        $this->service()->updateInventory($item, $this->manager, $input);
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        try { DB::table('ppe_command_intent_claims')->where('idempotency_key', $input['idempotency_key'])->delete(); }
        finally { \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints(); }
        $this->integrity(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
        $this->integrity(fn () => $this->service()->updateInventory($item, $this->manager, $input));
        $this->assertSame(0, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
        $fileInput = $this->uploadInput($item); $attempt = $this->rejectedUpload($item, $fileInput);
        Storage::disk('private')->put($attempt->path, 'Reviewed intent bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        DB::table('ppe_command_intent_claims')->where('idempotency_key', $fileInput['idempotency_key'])->update(['identity_digest' => str_repeat('0', 64)]);
        $this->integrity(fn () => $this->service()->resumeStagingCleanup($this->manager, $attempt->id));
        Storage::disk('private')->assertExists($attempt->path); $this->assertNull($attempt->fresh()->receipt_id);
    }
    public function test_rehashed_wrong_claim_target_does_not_satisfy_actor_bound_acceptance(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Recorded rack']);
        $this->service()->updateInventory($item, $this->manager, $input);
        $claim = PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->sole();
        $identity = [...$claim->identity(), 'root_id' => $item->id + 999];
        DB::table('ppe_command_intent_claims')->where('id', $claim->id)->update([...$identity, 'identity_digest' => PpeCommandValues::digest($identity)]);
        $this->integrity(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
        $this->assertSame(2, $item->fresh()->source_revision);
    }
    public function test_stale_or_revoked_authority_cannot_commit_new_claim_and_retained_claim_does_not_grant_access(): void
    {
        $item = $this->item(); $stale = $this->command($item, ['location' => 'Stale rack']);
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['location' => 'Current rack']));
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $stale), 'expected_version');
        $this->assertSame(0, PpeCommandIntentClaim::where('idempotency_key', $stale['idempotency_key'])->count());
        $input = $this->uploadInput($item); $attempt = $this->rejectedUpload($item, $input);
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input));
        $this->denial(fn () => $this->service()->stagingForViewer($this->manager, $attempt->id));
        $this->assertSame(1, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_retained_claim_alone_blocks_destructive_down_without_certifying_legacy_source(): void
    {
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id]);
        $input = $this->command($item); $receipt = $this->service()->updateInventory($item, $this->manager, $input);
        $this->assertNull($receipt['source_version_id']); $this->assertSame(0, $item->fresh()->source_revision);
        DB::table('ppe_command_receipts')->where('idempotency_key', $input['idempotency_key'])->delete();
        $this->assertSame('legacy_unknown', $this->sourceState($item));
        $migration = require database_path('migrations/2026_10_12_200000_create_ppe_command_history.php');
        try { $migration->down(); $this->fail('A retained reviewed claim must not be discarded.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame(1, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_missing_original_file_claim_cannot_be_recreated_on_exact_retry_or_used_for_cleanup(): void
    {
        $item = $this->item(); $input = $this->uploadInput($item); $attempt = $this->rejectedUpload($item, $input);
        $originalId = $attempt->intent_claim_id;
        Storage::disk('private')->put($attempt->path, 'Reviewed intent bytes');
        DB::table('ppe_evidence_staging')->where('id', $attempt->id)->update(['state' => 'staged']);
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        try { DB::table('ppe_command_intent_claims')->where('id', $originalId)->delete(); }
        finally { \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints(); }
        $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager, $input));
        $this->assertSame(0, PpeCommandIntentClaim::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame($originalId, $attempt->fresh()->intent_claim_id);
        $this->integrity(fn () => $this->service()->stagingForViewer($this->manager, $attempt->id));
        $this->integrity(fn () => $this->service()->resumeStagingCleanup($this->manager, $attempt->id));
        $this->assertSame(1, PpeEvidenceStaging::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, $item->fresh()->source_revision);
        Storage::disk('private')->assertExists($attempt->path);
    }
}
