<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\RestraintEventAttachment;
use App\Models\RestraintSourceDelivery;
use App\Services\HealthSafety\RestraintEvidenceService;
use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

class RestraintEvidenceTest extends RestraintEventCommandTestCase
{
    public function test_private_upload_replay_binds_bytes_name_category_and_notes(): void
    {
        Storage::fake('private');
        $row = $this->event();
        $service = app(RestraintEvidenceService::class);
        $file = UploadedFile::fake()->createWithContent('evidence.txt', 'actual evidence bytes');
        $input = $this->command($row, ['category' => 'debrief', 'notes' => 'Evidence is not actual contact']);
        $accepted = $service->add($row, $this->actor, $input, $file);
        $this->assertTrue($service->add($row, $this->actor, $input, $file)['replayed']);
        $this->assertDatabaseCount('restraint_event_attachments', 1);
        $attachment = RestraintEventAttachment::findOrFail($accepted['attachment_id']);
        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($attachment->path);
        $this->invalid(fn () => $service->add($row, $this->actor, $input, UploadedFile::fake()->createWithContent('evidence.txt', 'changed bytes')), 'idempotency_key');
        $this->invalid(fn () => $service->add($row, $this->actor, [...$input, 'notes' => 'Changed category facts'], $file), 'idempotency_key');
        $this->assertTrue($row->fresh()->needsReview());
        $this->assertDatabaseCount('restraint_debriefs', 0);
    }

    public function test_failed_semantic_audit_cleans_new_blob_and_metadata(): void
    {
        Storage::fake('private');
        $row = $this->event();
        AuditLog::creating(fn () => false);
        try {
            app(RestraintEvidenceService::class)->add($row, $this->actor, $this->command($row), UploadedFile::fake()->createWithContent('proof.txt', 'bytes'));
            $this->fail('Failed audit must not accept evidence.');
        } catch (LogicException) {
            $this->assertSame([], Storage::disk('private')->allFiles());
            $this->assertDatabaseCount('restraint_event_attachments', 0);
            $this->assertSame(1, $row->fresh()->lock_version);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
    }

    public function test_remove_retains_supporting_metadata_and_replay_stages_one_private_deletion(): void
    {
        Storage::fake('private');
        $row = $this->event();
        $service = app(RestraintEvidenceService::class);
        $accepted = $service->add($row, $this->actor, $this->command($row), UploadedFile::fake()->createWithContent('proof.txt', 'bytes'));
        $a = RestraintEventAttachment::findOrFail($accepted['attachment_id']);
        $input = $this->command($row, ['reason' => 'Retained evidence made unavailable']);
        $removed = $service->remove($row, $a, $this->actor, $input);
        $this->assertTrue($service->remove($row, $a, $this->actor, $input)['replayed']);
        $this->assertNotNull($a->fresh()->removed_at);
        $this->assertNotNull($a->fresh()->sha256);
        $this->assertSame(1, RestraintSourceDelivery::where('purpose', 'evidence_removal')->count());
        $intent = RestraintSourceDelivery::where('purpose', 'evidence_removal')->sole();
        app(RestraintSourceDeliveryService::class)->deliver($intent->id);
        Storage::disk('private')->assertMissing($a->path);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertDatabaseCount('restraint_event_attachments', 1);
        $this->denied(fn () => $service->download($row, $a, $this->actor));
    }

    public function test_download_uses_current_parent_exact_child_and_private_sandbox_headers(): void
    {
        Storage::fake('private');
        $row = $this->event();
        $service = app(RestraintEvidenceService::class);
        $accepted = $service->add($row, $this->actor, $this->command($row), UploadedFile::fake()->createWithContent('proof.txt', 'bytes'));
        $a = RestraintEventAttachment::findOrFail($accepted['attachment_id']);
        $response = $service->download($row, $a, $this->actor);
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->revoke($this->actor, 'restraints.view');
        $this->revoke($this->actor, 'restraints.review');
        $this->revoke($this->actor, 'restraints.manage');
        $this->denied(fn () => $service->download($row, $a, $this->actor));
        $this->actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'restraints.view')->sole()->id, ['allowed' => true]);
        $a->update(['disk' => 'public']);
        $this->denied(fn () => $service->download($row, $a, $this->actor));
    }

    public function test_upload_limit_is_ten_megabytes_without_a_success_receipt(): void
    {
        $row = $this->event();
        $this->invalid(fn () => app(RestraintEvidenceService::class)->add($row, $this->actor, $this->command($row), UploadedFile::fake()->create('large.pdf', 10241)), 'file');
        $this->assertDatabaseCount('restraint_event_attachments', 0);
    }

    public function test_outer_transaction_rollback_cleans_bytes_after_inner_acceptance_and_review_workspace_can_read_evidence(): void
    {
        Storage::fake('private');
        $row = $this->event();
        $service = app(RestraintEvidenceService::class);
        try {
            DB::transaction(function () use ($service, $row): void {
                $service->add($row, $this->actor, $this->command($row), UploadedFile::fake()->createWithContent('proof.txt', 'bytes'));
                throw new RuntimeException('Outer source failure');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Outer source failure', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('restraint_event_attachments', 0);
        $accepted = $service->add($row, $this->actor, $this->command($row), UploadedFile::fake()->createWithContent('proof.txt', 'bytes'));
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $response = $service->download($row, RestraintEventAttachment::findOrFail($accepted['attachment_id']), $reviewer);
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }
}
