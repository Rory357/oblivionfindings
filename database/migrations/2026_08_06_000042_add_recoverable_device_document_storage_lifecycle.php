<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_documents', function (Blueprint $table): void {
            $table->string('lifecycle_state', 32)->default('active')->after('content_sha256');
            $table->uuid('upload_operation_uuid')->nullable()->after('lifecycle_state');
            // Preserve immutable actor provenance even when the User relation
            // is later removed before an interrupted upload is reconciled.
            $table->unsignedBigInteger('upload_requested_by_user_id')->nullable()->after('upload_operation_uuid');
            $table->string('staged_storage_path')->nullable()->after('upload_requested_by_user_id');
            $table->timestamp('storage_verified_at')->nullable()->after('staged_storage_path');
            $table->string('lifecycle_error_code', 64)->nullable()->after('storage_verified_at');
            $table->uuid('removal_operation_uuid')->nullable()->after('lifecycle_error_code');
            $table->timestamp('removal_requested_at')->nullable()->after('removal_operation_uuid');
            $table->unsignedBigInteger('removal_requested_by_user_id')->nullable()->after('removal_requested_at');
            $table->string('removal_request_reason', 500)->nullable()->after('removal_requested_by_user_id');
            $table->string('quarantine_storage_path')->nullable()->after('removal_request_reason');

            $table->unique('upload_operation_uuid', 'dev_docs_upload_operation_unique');
            $table->unique('removal_operation_uuid', 'dev_docs_removal_operation_unique');
            $table->index(['lifecycle_state', 'updated_at'], 'dev_docs_lifecycle_updated_idx');
        });

        DB::table('device_documents')
            ->whereNotNull('removed_at')
            ->update(['lifecycle_state' => 'removed']);
    }

    public function down(): void
    {
        $recoverableEvidenceExists = DB::table('device_documents')
            ->where(function ($query): void {
                $query->where('lifecycle_state', '!=', 'active')
                    ->orWhereNotNull('upload_operation_uuid')
                    ->orWhereNotNull('upload_requested_by_user_id')
                    ->orWhereNotNull('staged_storage_path')
                    ->orWhereNotNull('storage_verified_at')
                    ->orWhereNotNull('lifecycle_error_code')
                    ->orWhereNotNull('removal_operation_uuid')
                    ->orWhereNotNull('removal_requested_at')
                    ->orWhereNotNull('removal_requested_by_user_id')
                    ->orWhereNotNull('removal_request_reason')
                    ->orWhereNotNull('quarantine_storage_path');
            })
            ->exists();

        if ($recoverableEvidenceExists) {
            throw new RuntimeException(
                'Cannot remove the recoverable document-storage lifecycle while staged, verified, removal, or recovery evidence exists.',
            );
        }

        Schema::table('device_documents', function (Blueprint $table): void {
            $table->dropUnique('dev_docs_upload_operation_unique');
            $table->dropUnique('dev_docs_removal_operation_unique');
            $table->dropIndex('dev_docs_lifecycle_updated_idx');
            $table->dropColumn([
                'lifecycle_state',
                'upload_operation_uuid',
                'upload_requested_by_user_id',
                'staged_storage_path',
                'storage_verified_at',
                'lifecycle_error_code',
                'removal_operation_uuid',
                'removal_requested_at',
                'removal_requested_by_user_id',
                'removal_request_reason',
                'quarantine_storage_path',
            ]);
        });
    }
};
