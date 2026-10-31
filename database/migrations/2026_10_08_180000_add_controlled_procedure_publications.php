<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_work_procedure_versions', function (Blueprint $table) {
            $table->json('document_manifest')->nullable();
            $table->string('provenance')->default('legacy_incomplete');
            $table->timestamp('captured_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('legacy_approved_by')->nullable();
            $table->timestamp('legacy_approved_at')->nullable();
            $table->string('content_digest', 64)->nullable();
        });
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->constrained('safe_work_procedure_versions')->restrictOnDelete();
            $table->date('published_review_date')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
        });
        Schema::create('procedure_acknowledgement_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_work_procedure_id')->constrained('safe_work_procedures', indexName: 'swp_ack_procedure_fk')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('safe_work_procedure_versions', indexName: 'swp_ack_version_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'swp_ack_user_fk')->restrictOnDelete();
            $table->string('provenance');
            $table->string('content_digest', 64)->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['version_id', 'user_id'], 'procedure_receipt_version_user_unique');
        });
        Schema::create('procedure_review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_work_procedure_id')->constrained('safe_work_procedures', indexName: 'swp_request_procedure_fk')->restrictOnDelete();
            $table->foreignId('hs_event_id')->constrained('hs_events', indexName: 'swp_request_event_fk')->restrictOnDelete();
            $table->foreignId('hs_corrective_action_id')->nullable()->constrained('hs_corrective_actions', indexName: 'swp_request_action_fk')->restrictOnDelete();
            $table->foreignId('source_site_id')->constrained('sites', indexName: 'swp_request_site_fk')->restrictOnDelete();
            $table->foreignId('requested_version_id')->constrained('safe_work_procedure_versions', indexName: 'swp_request_requested_version_fk')->restrictOnDelete();
            $table->foreignId('result_version_id')->nullable()->constrained('safe_work_procedure_versions', indexName: 'swp_request_result_version_fk')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users', indexName: 'swp_request_requester_fk')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->constrained('users', indexName: 'swp_request_executor_fk')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users', indexName: 'swp_request_decider_fk')->restrictOnDelete();
            $table->text('note');
            $table->string('status')->default('requested');
            $table->string('outcome')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->uuid('idempotency_key')->unique();
            $table->string('fingerprint', 64);
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('procedure_review_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_review_request_id')->constrained('procedure_review_requests', indexName: 'swp_command_request_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'swp_command_user_fk')->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'swp_command_audit_fk')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('procedure_review_command_key_unique');
            $table->string('fingerprint', 64);
            $table->json('confirmed_result');
            $table->timestamps();
        });
        $this->restoreArchivedCaptures();
        $this->captureLegacyPublications();
    }

    public function captureLegacyPublications(): void
    {
        // Capture CURRENT instructions, never recreate or overwrite an alleged historical approval.
        DB::table('safe_work_procedures')->where('status', 'approved')->whereNull('published_version_id')->whereNull('deleted_at')->orderBy('id')->chunkById(100, function ($records) {
            foreach ($records as $record) {
                $fields = ['title', 'reference_number', 'category', 'purpose', 'scope', 'steps', 'ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles', 'applicable_sites', 'related_training', 'review_date', 'review_frequency_months', 'owner_id'];
                $arrays = ['steps', 'ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles', 'applicable_sites', 'related_training'];
                $snapshot = [];
                foreach ($fields as $field) {
                    $value = $record->{$field} ?? null;
                    $snapshot[$field] = in_array($field, $arrays, true) ? json_decode($value ?? 'null', true) : $value;
                }
                foreach (['applicable_roles', 'applicable_sites'] as $audience) {
                    if (! is_array($snapshot[$audience])) {
                        $snapshot[$audience] = null;
                    }
                }
                $manifest = DB::table('hs_attachments')->whereIn('attachable_type', ['App\\Models\\SafeWorkProcedure', 'safe_work_procedure'])->where('attachable_id', $record->id)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                $existing = DB::table('safe_work_procedure_versions')->where('safe_work_procedure_id', $record->id)->where('version_number', $record->current_version)->where('provenance', 'legacy_current_capture')->first();
                if ($existing) {
                    $prior = json_decode($existing->content_snapshot, true, flags: JSON_THROW_ON_ERROR);
                    $sameContent = collect($fields)->every(fn ($field) => array_key_exists($field, $prior) && $prior[$field] === $snapshot[$field]);
                    if ($sameContent && json_decode($existing->document_manifest, true, flags: JSON_THROW_ON_ERROR) === $manifest) {
                        DB::table('safe_work_procedures')->where('id', $record->id)->update(['published_version_id' => $existing->id, 'published_review_date' => $snapshot['review_date']]);

                        continue;
                    }
                }
                $capturedAt = now();
                $snapshot['_publication_provenance'] = 'legacy_current_capture';
                $snapshot['_captured_at'] = $capturedAt->toISOString();
                $number = max((int) $record->current_version, (int) DB::table('safe_work_procedure_versions')->where('safe_work_procedure_id', $record->id)->max('version_number')) + 1;
                $versionId = DB::table('safe_work_procedure_versions')->insertGetId(['safe_work_procedure_id' => $record->id, 'version_number' => $number, 'content_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'document_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'change_summary' => 'Current legacy instructions captured during publication migration; original version provenance incomplete.', 'changed_by' => null, 'provenance' => 'legacy_current_capture', 'captured_at' => $capturedAt, 'legacy_approved_by' => $record->approved_by, 'legacy_approved_at' => $record->approved_at, 'content_digest' => hash('sha256', json_encode([$snapshot, $manifest], JSON_THROW_ON_ERROR)), 'created_at' => $capturedAt, 'updated_at' => $capturedAt]);
                DB::table('safe_work_procedures')->where('id', $record->id)->update(['current_version' => $number, 'published_version_id' => $versionId, 'published_review_date' => $record->review_date]);
            }
        });
    }

    private function archiveLegacyCaptures(): void
    {
        DB::transaction(function () {
            $rows = DB::table('safe_work_procedure_versions')->where('provenance', 'legacy_current_capture')->lockForUpdate()->get();
            $prepared = [];
            foreach ($rows as $row) {
                $snapshot = json_decode($row->content_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists('_publication_capture_archive', $snapshot)) {
                    throw new RuntimeException('Preserve the existing capture archive before rollback.');
                }
                $meta = ['provenance' => $row->provenance, 'captured_at' => $row->captured_at, 'legacy_approved_by' => $row->legacy_approved_by, 'legacy_approved_at' => $row->legacy_approved_at, 'document_manifest' => json_decode($row->document_manifest, true, flags: JSON_THROW_ON_ERROR), 'content_digest' => $row->content_digest];
                $this->validateCaptureArchive($snapshot, $meta);
                $prepared[$row->id] = [...$snapshot, '_publication_capture_archive' => $meta];
            }
            foreach ($prepared as $id => $snapshot) {
                DB::table('safe_work_procedure_versions')->where('id', $id)->update(['content_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function restoreArchivedCaptures(): void
    {
        DB::transaction(function () {
            $rows = DB::table('safe_work_procedure_versions')->whereNotNull('content_snapshot->_publication_capture_archive')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $snapshot = json_decode($row->content_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $meta = $snapshot['_publication_capture_archive'];
                unset($snapshot['_publication_capture_archive']);
                $this->validateCaptureArchive($snapshot, $meta);
                DB::table('safe_work_procedure_versions')->where('id', $row->id)->update([...$meta, 'document_manifest' => json_encode($meta['document_manifest'], JSON_THROW_ON_ERROR), 'content_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function validateCaptureArchive(array $snapshot, mixed $meta): void
    {
        $keys = ['provenance', 'captured_at', 'legacy_approved_by', 'legacy_approved_at', 'document_manifest', 'content_digest'];
        $valid = is_array($meta) && count(array_diff(array_keys($meta), $keys)) === 0 && count(array_diff($keys, array_keys($meta))) === 0
            && $meta['provenance'] === 'legacy_current_capture' && ($snapshot['_publication_provenance'] ?? null) === 'legacy_current_capture'
            && is_string($meta['captured_at']) && is_string($snapshot['_captured_at'] ?? null)
            && ($meta['legacy_approved_by'] === null || (is_int($meta['legacy_approved_by']) && $meta['legacy_approved_by'] > 0))
            && ($meta['legacy_approved_at'] === null || is_string($meta['legacy_approved_at']))
            && is_array($meta['document_manifest']) && array_is_list($meta['document_manifest'])
            && collect($meta['document_manifest'])->every(fn ($id) => is_int($id) && $id > 0)
            && is_string($meta['content_digest']) && preg_match('/^[a-f0-9]{64}$/D', $meta['content_digest']) === 1;
        if (! $valid) {
            throw new RuntimeException('Preserve incomplete legacy capture metadata before changing its schema.');
        }
        $ordered = [];
        foreach (['title', 'reference_number', 'category', 'purpose', 'scope', 'steps', 'ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles', 'applicable_sites', 'related_training', 'review_date', 'review_frequency_months', 'owner_id', '_publication_provenance', '_captured_at'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                throw new RuntimeException('Preserve incomplete legacy capture content before changing its schema.');
            }
            $ordered[$field] = $snapshot[$field];
        }
        if (count($snapshot) !== count($ordered) || hash('sha256', json_encode([$ordered, $meta['document_manifest']], JSON_THROW_ON_ERROR)) !== $meta['content_digest']) {
            throw new RuntimeException('Preserve legacy capture digest mismatch before changing its schema.');
        }
    }

    public function down(): void
    {
        // Do not silently discard newly collected compliance evidence on rollback.
        if (DB::table('procedure_acknowledgement_receipts')->exists() || DB::table('procedure_review_requests')->exists() || DB::table('safe_work_procedure_versions')->where('provenance', 'controlled')->exists()) {
            throw new RuntimeException('Export and preserve controlled publication, acknowledgement and review-request evidence before rollback.');
        }
        $this->archiveLegacyCaptures();
        Schema::dropIfExists('procedure_review_command_receipts');
        Schema::dropIfExists('procedure_review_requests');
        Schema::dropIfExists('procedure_acknowledgement_receipts');
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn(['published_review_date', 'lock_version']);
        });
        Schema::table('safe_work_procedure_versions', function (Blueprint $table) {
            $table->dropColumn(['document_manifest', 'provenance', 'captured_at', 'approved_by', 'approved_at', 'legacy_approved_by', 'legacy_approved_at', 'content_digest']);
        });
        // Appended captures remain snapshots, explicitly labelled in their content, and old rows remain untouched.
    }
};
