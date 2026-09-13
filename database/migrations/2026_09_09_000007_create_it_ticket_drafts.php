<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_ticket_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 32);
            $table->string('context_key', 64);
            $table->string('audience', 12);
            $table->uuid('draft_uuid')->unique();
            // Preserve a deleted context identity so it cannot become an
            // unbound intake draft or expose its former private contents.
            $table->unsignedBigInteger('it_ticket_id')->nullable()->index();
            $table->uuid('request_uuid')->nullable();
            $table->string('state', 16)->default('active');
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('last_base_revision')->nullable();
            $table->string('last_mutation', 16)->default('initialized');
            $table->unsignedBigInteger('base_ticket_version')->nullable();
            $table->longText('encrypted_payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->json('bound_scope')->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_user_id', 'purpose', 'context_key', 'audience'], 'it_ticket_drafts_context_unique');
        });
        Schema::table('it_attachments', function (Blueprint $table): void {
            $table->uuid('draft_generation_uuid')->nullable()->index();
            $table->uuid('draft_upload_uuid')->nullable()->unique();
            $table->char('draft_content_hash', 64)->nullable();
            $table->string('draft_storage_state', 24)->nullable()->index();
            $table->unsignedInteger('draft_cleanup_attempts')->default(0);
            $table->string('draft_cleanup_error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('it_ticket_drafts') && DB::table('it_ticket_drafts')->exists()) {
            throw new RuntimeException('Retained IT recovery slots must be handled before rolling back their schema.');
        }
        if (DB::table('it_attachments')->whereNotNull('draft_generation_uuid')->exists()) {
            throw new RuntimeException('Staged IT attachments must be handled before rolling back their recovery schema.');
        }
        Schema::table('it_attachments', function (Blueprint $table): void {
            $table->dropUnique(['draft_upload_uuid']);
            $table->dropIndex(['draft_generation_uuid']);
            $table->dropIndex(['draft_storage_state']);
            $table->dropColumn(['draft_generation_uuid', 'draft_upload_uuid', 'draft_content_hash', 'draft_storage_state', 'draft_cleanup_attempts', 'draft_cleanup_error_code']);
        });
        Schema::dropIfExists('it_ticket_drafts');
    }
};
