<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_attachment_storage_intents', function (Blueprint $table) {
            // Independent reservations must not wait on the caller's uncommitted FK locks.
            $table->unsignedBigInteger('inbound_email_id')->nullable();
            $table->unsignedBigInteger('source_inbound_attachment_id')->nullable();
            $table->unsignedBigInteger('mailbox_connection_id')->nullable();
            $table->unsignedInteger('mailbox_configuration_version')->nullable();
            $table->uuid('mailbox_claim_token')->nullable();
            $table->index(['state', 'mailbox_connection_id', 'id'], 'it_storage_mailbox_recovery');
        });
    }

    public function down(): void
    {
        if (DB::table('it_attachment_storage_intents')->whereNotNull('inbound_email_id')->exists()) {
            throw new RuntimeException('Preserve mailbox file ownership and recovery evidence before removing its binding.');
        }
        Schema::table('it_attachment_storage_intents', function (Blueprint $table) {
            $table->dropIndex('it_storage_mailbox_recovery');
            $table->dropColumn(['inbound_email_id', 'source_inbound_attachment_id', 'mailbox_connection_id',
                'mailbox_configuration_version', 'mailbox_claim_token']);
        });
    }
};
