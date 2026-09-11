<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_mailbox_connections', function (Blueprint $table): void {
            $table->char('inbox_scan_scope', 64)->nullable();
            $table->timestamp('inbox_scan_before')->nullable();
            $table->text('inbox_scan_cursor')->nullable();
            $table->json('inbox_scan_cursor_hashes')->nullable();
            $table->boolean('inbox_scan_complete')->default(false);
        });
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->foreignId('it_mailbox_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->char('mailbox_scope_hash', 64)->nullable()->index();
            $table->char('transport_key', 64)->nullable()->unique();
            $table->text('remote_message_id')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('it_inbound_emails')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedInteger('acknowledgement_attempts')->default(0);
            $table->string('transport_failure_code', 40)->nullable();
            $table->timestamp('transport_retry_at')->nullable();
            $table->index(['mailbox_scope_hash', 'acknowledged_at', 'id'], 'it_inbound_pending_transport');
        });
    }

    public function down(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('it_mailbox_connection_id');
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropIndex('it_inbound_pending_transport');
            $table->dropColumn(['mailbox_scope_hash', 'transport_key', 'remote_message_id', 'acknowledged_at', 'processing_attempts', 'acknowledgement_attempts', 'transport_failure_code', 'transport_retry_at']);
        });
        Schema::table('it_mailbox_connections', fn (Blueprint $table) => $table->dropColumn([
            'inbox_scan_scope', 'inbox_scan_before', 'inbox_scan_cursor', 'inbox_scan_cursor_hashes', 'inbox_scan_complete',
        ]));
    }
};
