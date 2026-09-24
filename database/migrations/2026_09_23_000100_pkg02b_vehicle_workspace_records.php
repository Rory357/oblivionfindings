<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B slice (a): vehicle details, private versioned documents, reusable
 * choices, calendar-month service schedules with retained completion
 * history, and vehicle reminders. Additive only; legacy columns stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_catalogue_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40);
            $table->string('label', 80);
            $table->string('normalised_label', 80);
            $table->json('value_json')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'normalised_label'], 'fleet_catalogue_kind_label_uq');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->string('body_type', 80)->nullable()->after('category');
            $table->string('use_purpose', 120)->nullable()->after('body_type');
            $table->string('ownership_arrangement', 80)->nullable()->after('use_purpose');
            $table->foreignId('fleet_responsible_user_id')->nullable()->after('primary_driver_user_id')
                ->constrained('users', 'id', 'assets_fleet_responsible_fk')->nullOnDelete();
            $table->string('insurance_provider', 160)->nullable()->after('warranty_expires_at');
            $table->string('insurance_policy_reference', 120)->nullable()->after('insurance_provider');
            $table->date('insurance_expires_at')->nullable()->after('insurance_policy_reference');
            $table->string('warranty_reference', 120)->nullable()->after('insurance_expires_at');
            $table->unsignedInteger('vehicle_profile_version')->default(1)->after('warranty_reference');
        });

        // One document (policy, agreement, certificate) made of one or more
        // files. Replacing its files adds a revision; the set, and so its one
        // renewal reminder, keeps its identity.
        Schema::create('asset_document_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('category', 120);
            $table->string('reference', 120)->nullable();
            $table->date('document_date')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedInteger('current_revision')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->boolean('legacy_backfill')->default(false);
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'asset_doc_sets_asset_request_uq');
            $table->index(['asset_id', 'category'], 'asset_doc_sets_asset_category_idx');
            $table->index(['source_type', 'source_id'], 'asset_doc_sets_source_idx');
        });

        Schema::create('asset_document_set_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_set_id')->constrained('asset_document_sets')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('action', 40);
            $table->unsignedInteger('set_version');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->unique(['document_set_id', 'request_key'], 'asset_doc_set_events_request_uq');
        });

        Schema::table('asset_documents', function (Blueprint $table): void {
            $table->foreignId('document_set_id')->nullable()->after('asset_id')
                ->constrained('asset_document_sets', 'id', 'asset_documents_set_fk')->restrictOnDelete();
            $table->unsignedInteger('revision')->nullable()->after('document_set_id');
            $table->string('source_type', 60)->nullable()->after('revision');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('state', 32)->nullable()->after('size_bytes');
            $table->char('sha256', 64)->nullable()->after('state');
            $table->string('detected_mime', 120)->nullable()->after('sha256');
            $table->string('scan_disposition', 24)->nullable()->after('detected_mime');
            $table->string('scanner', 80)->nullable()->after('scan_disposition');
            $table->string('scan_failure_code', 40)->nullable()->after('scanner');
            $table->timestamp('scan_attempted_at')->nullable()->after('scan_failure_code');
            $table->timestamp('scanned_at')->nullable()->after('scan_attempted_at');
            $table->string('request_key', 100)->nullable()->after('scanned_at');
            $table->char('request_fingerprint', 64)->nullable()->after('request_key');
            $table->timestamp('archived_at')->nullable()->after('request_fingerprint');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')
                ->constrained('users', 'id', 'asset_documents_archived_by_fk')->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by_user_id');
            $table->unique(['asset_id', 'request_key'], 'asset_documents_asset_request_uq');
            $table->index(['document_set_id', 'revision'], 'asset_documents_set_revision_idx');
            $table->index(['source_type', 'source_id'], 'asset_documents_source_idx');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignId('profile_photo_document_id')->nullable()->after('vehicle_profile_version')
                ->constrained('asset_documents', 'id', 'assets_profile_photo_fk')->restrictOnDelete();
        });

        Schema::table('fleet_vehicle_odometer_observations', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('correction_reason');
        });

        Schema::table('fleet_service_schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('interval_months')->nullable()->after('interval_days');
            $table->foreignId('owner_user_id')->nullable()->after('is_active')
                ->constrained('users', 'id', 'fleet_service_schedules_owner_fk')->nullOnDelete();
            $table->unsignedSmallInteger('reminder_days_before')->nullable()->after('owner_user_id');
            $table->unsignedInteger('reminder_km_before')->nullable()->after('reminder_days_before');
            $table->unsignedInteger('lock_version')->default(1)->after('reminder_km_before');
        });

        Schema::create('fleet_service_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('fleet_service_schedules')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->date('completed_on');
            $table->foreignId('odometer_observation_id')->nullable()
                ->constrained('fleet_vehicle_odometer_observations', 'id', 'fleet_service_completions_odo_fk')->restrictOnDelete();
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained('fleet_work_orders')->restrictOnDelete();
            $table->string('provider', 160)->nullable();
            $table->string('evidence_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->date('previous_next_due_at')->nullable();
            $table->decimal('previous_next_due_km', 12, 1)->nullable();
            $table->date('next_due_at')->nullable();
            $table->decimal('next_due_km', 12, 1)->nullable();
            $table->unsignedInteger('schedule_version');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['schedule_id', 'request_key'], 'fleet_service_completions_request_uq');
            $table->index(['asset_id', 'completed_on'], 'fleet_service_completions_asset_idx');
        });

        Schema::create('fleet_vehicle_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('action_text')->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('due_at');
            $table->unsignedTinyInteger('repeat_months')->default(0);
            $table->foreignId('owner_user_id')->nullable()->constrained('users', 'id', 'fleet_vehicle_reminders_owner_fk')->nullOnDelete();
            $table->foreignId('backup_user_id')->nullable()->constrained('users', 'id', 'fleet_vehicle_reminders_backup_fk')->nullOnDelete();
            $table->string('state', 24)->default('scheduled');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fleet_vehicle_reminders_creator_fk')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_vehicle_reminders_request_uq');
            $table->index(['asset_id', 'state', 'due_at'], 'fleet_vehicle_reminders_asset_idx');
            $table->index(['state', 'due_at'], 'fleet_vehicle_reminders_due_idx');
            $table->index(['source_type', 'source_id'], 'fleet_vehicle_reminders_source_idx');
        });

        Schema::create('fleet_vehicle_reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('fleet_vehicle_reminders')->restrictOnDelete();
            $table->string('action', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id', 'fleet_vehicle_reminder_events_actor_fk')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->unique(['reminder_id', 'request_key'], 'fleet_vehicle_reminder_events_request_uq');
        });

        $this->backfillVehicleDocuments();
    }

    /**
     * Existing vehicle files each become a one-file set. Bytes, paths and
     * metadata are untouched and no clean scan is invented.
     */
    private function backfillVehicleDocuments(): void
    {
        DB::table('asset_documents')
            ->whereIn('asset_id', DB::table('assets')->where(function ($vehicle): void {
                $vehicle->where('category', 'vehicle')->orWhereExists(fn ($q) => $q->selectRaw('1')->from('asset_categories')
                    ->whereColumn('asset_categories.id', 'assets.asset_category_id')->where('asset_categories.slug', 'vehicle'));
            })->select('id'))
            ->whereNull('document_set_id')
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    $setId = DB::table('asset_document_sets')->insertGetId([
                        'asset_id' => $document->asset_id,
                        'category' => mb_substr((string) ($document->category ?: 'Other supporting document'), 0, 120),
                        'document_date' => $document->effective_date,
                        'expires_on' => $document->expiry_date,
                        'created_by_user_id' => $document->uploaded_by_user_id,
                        'legacy_backfill' => true,
                        'created_at' => $document->created_at ?? now(),
                        'updated_at' => $document->updated_at ?? now(),
                    ]);
                    DB::table('asset_documents')->where('id', $document->id)->update([
                        'document_set_id' => $setId,
                        'revision' => 1,
                        'state' => 'legacy_unverified',
                    ]);
                }
            });
    }

    public function down(): void
    {
        $hasNewRecords = DB::table('asset_document_sets')->where('legacy_backfill', false)->exists()
            || DB::table('asset_document_set_events')->exists()
            || DB::table('asset_documents')->whereNotNull('document_set_id')
                ->where(fn ($q) => $q->where('state', '!=', 'legacy_unverified')->orWhereNotNull('archived_at')->orWhere('revision', '!=', 1))->exists()
            || DB::table('asset_documents')->whereNull('document_set_id')->whereNotNull('state')->exists()
            || DB::table('fleet_catalogue_entries')->exists()
            || DB::table('fleet_service_completions')->exists()
            || DB::table('fleet_vehicle_reminders')->exists()
            || DB::table('assets')->whereNotNull('profile_photo_document_id')->exists()
            || DB::table('fleet_service_schedules')->whereNotNull('interval_months')->exists();
        if ($hasNewRecords) {
            throw new RuntimeException('PKG-02B vehicle records exist; preserve them instead of rolling back the workspace records.');
        }

        Schema::table('assets', fn (Blueprint $table) => $table->dropForeign('assets_profile_photo_fk'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('profile_photo_document_id'));
        Schema::dropIfExists('fleet_vehicle_reminder_events');
        Schema::dropIfExists('fleet_vehicle_reminders');
        Schema::dropIfExists('fleet_service_completions');
        Schema::table('fleet_service_schedules', function (Blueprint $table): void {
            $table->dropForeign('fleet_service_schedules_owner_fk');
            $table->dropColumn(['interval_months', 'owner_user_id', 'reminder_days_before', 'reminder_km_before', 'lock_version']);
        });
        Schema::table('fleet_vehicle_odometer_observations', fn (Blueprint $table) => $table->dropColumn('notes'));
        DB::table('asset_documents')->whereNotNull('document_set_id')->update(['document_set_id' => null]);
        Schema::table('asset_documents', function (Blueprint $table): void {
            $table->dropForeign('asset_documents_set_fk');
            $table->dropForeign('asset_documents_archived_by_fk');
            $table->dropUnique('asset_documents_asset_request_uq');
            $table->dropIndex('asset_documents_set_revision_idx');
            $table->dropIndex('asset_documents_source_idx');
            $table->dropColumn(['document_set_id', 'revision', 'source_type', 'source_id',
                'state', 'sha256', 'detected_mime', 'scan_disposition', 'scanner', 'scan_failure_code', 'scan_attempted_at',
                'scanned_at', 'request_key', 'request_fingerprint', 'archived_at', 'archived_by_user_id', 'archive_reason']);
        });
        Schema::dropIfExists('asset_document_set_events');
        Schema::dropIfExists('asset_document_sets');
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('assets_fleet_responsible_fk');
            $table->dropColumn(['body_type', 'use_purpose', 'ownership_arrangement', 'fleet_responsible_user_id',
                'insurance_provider', 'insurance_policy_reference', 'insurance_expires_at', 'warranty_reference', 'vehicle_profile_version']);
        });
        Schema::dropIfExists('fleet_catalogue_entries');
    }
};
