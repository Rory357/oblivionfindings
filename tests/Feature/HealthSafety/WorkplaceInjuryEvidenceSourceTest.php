<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjuryCommandReceipt;
use App\Models\WorkplaceInjurySourceVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

final class WorkplaceInjuryEvidenceSourceTest extends WorkplaceInjurySourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function file(): UploadedFile { return UploadedFile::fake()->image('recorded-evidence.png', 10, 10); }

    private function proof($injury, $attachment): array
    {
        return $this->access()->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
            $this->access()->evidenceProof($injury, $attachment, $current, $reads, $sites));
    }

    public function test_governed_upload_has_exact_private_bytes_version_and_minimal_receipt(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(),
            $this->command($injury, ['kind' => 'medical_cert', 'notes' => 'Private evidence narrative']));
        $attachment = WorkplaceInjuryAttachment::findOrFail($accepted['source_id']);
        $this->assertSame(1, $attachment->source_schema_version);
        $this->assertSame(2, $attachment->source_revision);
        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertSame(['attribution_state' => 'recorded', 'available' => true,
            'source_version_id' => $accepted['source_version_id'], 'source_revision' => 2], $this->proof($injury, $attachment));
        $this->assertStringNotContainsString('recorded-evidence.png', json_encode($accepted));
        $this->assertStringNotContainsString('Private evidence', json_encode($accepted));
        $download = $this->access()->attachmentForDownload($injury, $attachment, $this->manager);
        $this->assertSame($attachment->path, $download['path']);
        $this->denial(fn () => $this->access()->attachmentForDownload($injury, $attachment, $this->factual));
    }

    public function test_same_upload_retry_retains_original_bytes_and_removes_only_new_staging(): void
    {
        $injury = $this->injury();
        $file = $this->file();
        $payload = $this->command($injury, ['kind' => 'photo']);
        $accepted = $this->recovery()->uploadEvidence($injury, $this->manager, $file, $payload);
        $original = Storage::disk('private')->allFiles();
        $replayed = $this->recovery()->uploadEvidence($injury, $this->manager, $file, $payload);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($accepted['receipt_id'], $replayed['receipt_id']);
        $this->assertSame($original, Storage::disk('private')->allFiles());
        $this->assertSame(1, WorkplaceInjuryAttachment::count());
    }

    public function test_audit_failure_rolls_back_evidence_row_and_cleans_only_this_staged_file(): void
    {
        $injury = $this->injury();
        Storage::disk('private')->put('workplace_injury_attachments/retained.txt', 'retained source bytes');
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(fn (AuditLog $audit) => $audit->action === 'health_safety.injury.upload_evidence' ? false : null);
        try {
            $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
            $this->fail('Missing audit must refuse staged acceptance.');
        } catch (LogicException $error) { $this->assertStringContainsString('audit', $error->getMessage()); }
        finally { AuditLog::setEventDispatcher($dispatcher); }
        $this->assertSame(['workplace_injury_attachments/retained.txt'], Storage::disk('private')->allFiles());
        $this->assertSame(0, WorkplaceInjuryAttachment::count());
        $this->assertSame(1, $injury->fresh()->source_revision);
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_later_enclosing_transaction_rollback_removes_new_evidence_bytes_and_source_facts(): void
    {
        $injury = $this->injury();
        try {
            DB::transaction(function () use ($injury) {
                $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
                $this->assertSame(1, WorkplaceInjuryAttachment::count());
                throw new RuntimeException('Outer source command refused');
            });
        } catch (RuntimeException $error) { $this->assertSame('Outer source command refused', $error->getMessage()); }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(0, WorkplaceInjuryAttachment::count());
        $this->assertSame(1, $injury->fresh()->source_revision);
    }

    public function test_soft_removal_retains_bytes_history_and_exact_receipt_can_replay(): void
    {
        $injury = $this->injury();
        $uploaded = $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
        $attachment = WorkplaceInjuryAttachment::findOrFail($uploaded['source_id']);
        $payload = $this->command($injury, ['reason' => 'Source-owned withdrawal']);
        $removed = $this->recovery()->removeEvidence($injury, $attachment, $this->manager, $payload);
        $this->assertSoftDeleted('workplace_injury_attachments', ['id' => $attachment->id]);
        Storage::disk('private')->assertExists($attachment->path);
        $otherWorker = $this->staff();
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['user_id' => $otherWorker->id])), 'user_id');
        $otherSite = \App\Models\Site::factory()->create();
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$otherSite->id])]);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->worker->id)->update(['secondary_site_ids' => json_encode([$otherSite->id])]);
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['site_id' => $otherSite->id])), 'site_id');
        $this->assertSame($this->worker->id, (int) $injury->fresh()->user_id);
        $this->assertSame($this->site->id, (int) $injury->fresh()->site_id);
        $replayed = $this->recovery()->removeEvidence($injury, $attachment, $this->manager, $payload);
        $this->assertSame($removed['receipt_id'], $replayed['receipt_id']);
        $this->assertTrue($replayed['replayed']);
        $this->denial(fn () => $this->access()->attachmentForDownload($injury, $attachment, $this->manager), 404);
    }

    public function test_typed_missing_version_is_unavailable_never_a_legacy_downgrade(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
        $attachment = WorkplaceInjuryAttachment::findOrFail($accepted['source_id']);
        DB::table('workplace_injury_source_versions')->where('id', $accepted['source_version_id'])->delete();
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false,
            'source_version_id' => null, 'source_revision' => 2], $this->proof($injury, $attachment));
        $this->denial(fn () => $this->access()->attachmentForDownload($injury, $attachment, $this->manager), 404);
    }

    public function test_external_metadata_mutation_and_changed_bytes_are_unavailable(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
        $attachment = WorkplaceInjuryAttachment::findOrFail($accepted['source_id']);
        DB::table('workplace_injury_attachments')->where('id', $attachment->id)->update(['original_name' => 'Ungoverned name']);
        $this->assertSame('repair_required', $this->proof($injury, $attachment)['attribution_state']);
        DB::table('workplace_injury_attachments')->where('id', $attachment->id)->update(['original_name' => $attachment->original_name]);
        Storage::disk('private')->put($attachment->path, 'replaced bytes');
        $this->assertFalse($this->proof($injury, $attachment)['available']);
        $this->denial(fn () => $this->access()->attachmentForDownload($injury, $attachment, $this->manager), 404);
    }

    public function test_governed_evidence_marker_downgrade_cannot_restore_legacy_download_availability(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->uploadEvidence($injury, $this->manager, $this->file(), $this->command($injury));
        $attachment = WorkplaceInjuryAttachment::findOrFail($receipt['source_id']);
        DB::table('workplace_injury_attachments')->where('id', $attachment->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $proof = $this->proof($injury, $attachment);
        $this->assertSame('repair_required', $proof['attribution_state']);
        $this->assertFalse($proof['available']);
        $this->assertSame($receipt['source_version_id'], $proof['source_version_id']);
        $this->denial(fn () => $this->access()->attachmentForDownload($injury, $attachment, $this->manager), 404);
    }

    public function test_legacy_evidence_remains_unknown_and_does_not_invent_version_or_hash(): void
    {
        $injury = $this->injury();
        $path = 'workplace_injury_attachments/legacy.pdf';
        Storage::disk('private')->put($path, 'retained legacy bytes');
        $attachment = WorkplaceInjuryAttachment::create(['workplace_injury_id' => $injury->id, 'uploaded_by' => $this->manager->id,
            'disk' => 'private', 'path' => $path, 'original_name' => 'Legacy.pdf', 'mime' => 'application/pdf', 'size' => 21]);
        $this->assertSame(['attribution_state' => 'legacy_unknown', 'available' => true,
            'source_version_id' => null, 'source_revision' => null], $this->proof($injury, $attachment));
        $this->assertSame(0, $attachment->fresh()->source_schema_version);
    }
    public function test_fake_legacy_reparent_node_cannot_restore_governed_evidence_availability(): void
    {
        $original = $this->injury();
        $receipt = $this->recovery()->uploadEvidence($original, $this->manager, $this->file(), $this->command($original));
        $attachment = WorkplaceInjuryAttachment::findOrFail($receipt['source_id']);
        $destination = $this->injury();
        DB::table('workplace_injury_attachments')->where('id', $attachment->id)->update([
            'workplace_injury_id' => $destination->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $content = ['scope' => 'medical', 'before' => null, 'after' => [], 'command_facts' => [], 'attribution_state' => 'legacy_unknown'];
        $fake = WorkplaceInjurySourceVersion::create(['workplace_injury_id' => $destination->id, 'aggregate_revision' => 0,
            'source_schema_version' => 0, 'source_type' => 'attachment', 'source_id' => $attachment->id,
            'command' => 'legacy_baseline', 'worker_id' => $this->worker->id, 'site_id' => $this->site->id,
            'recorded_by' => $this->manager->id, 'recorder_snapshot' => ['id' => $this->manager->id, 'name' => $this->manager->name],
            'content' => $content, 'content_digest' => \App\Services\HealthSafety\RestraintCommandValues::digest($content),
            'factual_content' => null, 'factual_digest' => null, 'recorded_at' => now()->utc()]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $proof = $this->proof($destination, $attachment);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        $this->assertSame(['attribution_state' => 'repair_required', 'available' => false,
            'source_version_id' => $fake->id, 'source_revision' => 0], $proof);
        $identityProof = array_values(array_filter($queries, fn ($query) =>
            str_contains(strtolower($query['query']), 'workplace_injury_source_versions')
            && str_contains(strtolower($query['query']), 'exists')
            && str_contains(strtolower($query['query']), 'source_type')
            && ! str_contains(strtolower($query['query']), 'workplace_injury_id')));
        $this->assertNotEmpty($identityProof);
        foreach ($identityProof as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertContains('attachment', $query['bindings']);
            $this->assertContains($attachment->id, $query['bindings']);
        }
        $this->denial(fn () => $this->access()->attachmentForDownload($destination, $attachment, $this->manager), 404);
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertDatabaseHas('workplace_injury_source_versions', ['id' => $receipt['source_version_id'],
            'workplace_injury_id' => $original->id, 'source_schema_version' => 1]);
    }
}
