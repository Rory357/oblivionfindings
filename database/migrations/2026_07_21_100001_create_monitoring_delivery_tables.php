<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('stream', 64);
            $table->string('source', 128);
            $table->unsignedBigInteger('sequence');
            $table->string('idempotency_key', 128);
            $table->mediumText('envelope_bytes');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['source', 'sequence'], 'monitoring_outbox_source_sequence_uq');
            $table->unique(['source', 'idempotency_key'], 'monitoring_outbox_source_idempotency_uq');
            $table->index(['stream', 'published_at', 'available_at'], 'monitoring_outbox_pending_idx');
        });

        Schema::create('monitoring_inbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id');
            $table->string('consumer', 128);
            $table->string('source', 128);
            $table->unsignedBigInteger('sequence');
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->mediumText('envelope_bytes');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['consumer', 'message_id'], 'monitoring_inbox_consumer_message_uq');
            $table->unique(
                ['consumer', 'source', 'idempotency_key'],
                'monitoring_inbox_consumer_source_idempotency_uq',
            );
            $table->index(['consumer', 'processed_at'], 'monitoring_inbox_consumer_processed_idx');
        });

        Schema::create('monitoring_consumer_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 128);
            $table->string('source', 128);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('gap_from')->nullable();
            $table->unsignedBigInteger('gap_to')->nullable();
            $table->timestamps();

            $table->unique(
                ['consumer', 'source'],
                'monitoring_checkpoints_consumer_source_uq',
            );
        });

        Schema::create('monitoring_dead_letters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id');
            $table->string('consumer', 128);
            $table->string('source', 128);
            $table->unsignedBigInteger('sequence');
            $table->string('idempotency_key', 128);
            $table->string('reason_code', 64);
            $table->string('reason_message', 500);
            $table->mediumText('envelope_bytes');
            // Canonical site context comes from trusted intake/routing metadata,
            // never from the untrusted envelope payload. Null remains reviewable.
            $table->foreignId('site_id')->nullable()->index()->constrained('sites')->nullOnDelete();
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['consumer', 'resolved_at'], 'monitoring_dead_letters_consumer_resolved_idx');
            $table->index('created_at', 'monitoring_dead_letters_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_dead_letters');
        Schema::dropIfExists('monitoring_consumer_checkpoints');
        Schema::dropIfExists('monitoring_inbox');
        Schema::dropIfExists('monitoring_outbox');
    }
};
