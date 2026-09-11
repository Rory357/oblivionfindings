<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table) {
            $table->char('attachment_manifest_hash', 64)->nullable();
            $table->unsignedTinyInteger('attachment_expected_count')->nullable();
        });
        Schema::table('it_attachments', function (Blueprint $table) {
            // Staged files belong to the canonical inbound receipt, never a public ticket.
            $table->unsignedTinyInteger('inbound_position')->nullable();
            $table->foreignId('source_inbound_attachment_id')->nullable()->constrained('it_attachments')->restrictOnDelete();
            $table->char('inbound_content_hash', 64)->nullable();
            $table->string('inbound_storage_state', 24)->nullable()->index();
            $table->string('inbound_error_code', 64)->nullable();
            $table->unsignedInteger('inbound_cleanup_attempts')->default(0);
            $table->string('malware_scan_status', 24)->nullable();
            $table->string('malware_scanner', 80)->nullable();
            $table->timestamp('malware_scan_attempted_at')->nullable();
            $table->timestamp('malware_scanned_at')->nullable();
            $table->unique(['attachable_type', 'attachable_id', 'inbound_position'], 'it_attachment_inbound_position_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('it_attachments')->whereNotNull('inbound_storage_state')->exists()) {
            throw new RuntimeException('Reconcile inbound file storage and preserve scan evidence before removing staging metadata.');
        }
        Schema::table('it_attachments', function (Blueprint $table) {
            $table->dropUnique('it_attachment_inbound_position_unique');
            $table->dropForeign(['source_inbound_attachment_id']);
            $table->dropIndex(['inbound_storage_state']);
            $table->dropColumn(['inbound_position', 'source_inbound_attachment_id', 'inbound_content_hash', 'inbound_storage_state', 'inbound_error_code',
                'inbound_cleanup_attempts', 'malware_scan_status', 'malware_scanner', 'malware_scan_attempted_at', 'malware_scanned_at']);
        });
        Schema::table('it_inbound_emails', fn (Blueprint $table) => $table->dropColumn(['attachment_manifest_hash', 'attachment_expected_count']));
    }
};
