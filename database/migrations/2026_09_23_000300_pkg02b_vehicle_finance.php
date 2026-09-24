<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle Finance view: links from a vehicle to existing Finance
 * records (fixed asset, purchase order, supplier invoice) and review
 * requests that Finance decides, with their retained history. Finance
 * records themselves are never changed here. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_finance_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('record_type', 20);
            $table->unsignedBigInteger('record_id');
            // 1 while the link is active and NULL once removed, so a record has
            // at most one active link to a vehicle while history is kept.
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->string('reason', 2000);
            $table->foreignId('linked_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_fin_links_linker_fk')->nullOnDelete();
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('unlinked_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_fin_links_unlinker_fk')->nullOnDelete();
            $table->string('unlink_reason', 2000)->nullable();
            // One save can add a link and replace another, so the key is not unique here.
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->string('unlink_request_key', 100)->nullable();
            $table->char('unlink_request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'record_type', 'record_id', 'active_slot'], 'fleet_fin_links_active_uq');
            $table->index(['record_type', 'record_id', 'active_slot'], 'fleet_fin_links_record_idx');
            $table->index(['asset_id', 'request_key'], 'fleet_fin_links_request_idx');
            $table->index(['asset_id', 'unlink_request_key'], 'fleet_fin_links_unlink_request_idx');
        });

        Schema::create('fleet_finance_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 30)->nullable()->unique('fleet_fin_reviews_reference_uq');
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('request_type', 40);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            // The source as the requester saw it, kept if the source changes later.
            $table->string('source_label', 255);
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('note');
            $table->foreignId('existing_document_id')->nullable()
                ->constrained('asset_documents', 'id', 'fleet_fin_reviews_document_fk')->nullOnDelete();
            $table->string('status', 20)->default('submitted');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_fin_reviews_requester_fk')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_fin_reviews_decider_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_fin_reviews_request_uq');
            $table->index(['asset_id', 'status'], 'fleet_fin_reviews_asset_status_idx');
            $table->index(['status', 'created_at'], 'fleet_fin_reviews_queue_idx');
            $table->index(['source_type', 'source_id'], 'fleet_fin_reviews_source_idx');
        });

        Schema::create('fleet_finance_review_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_request_id')
                ->constrained('fleet_finance_review_requests', 'id', 'fleet_fin_review_events_request_fk')->restrictOnDelete();
            $table->string('action', 24);
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_fin_review_events_actor_fk')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->unique(['review_request_id', 'request_key'], 'fleet_fin_review_events_request_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_vehicle_finance_links')->exists() || DB::table('fleet_finance_review_requests')->exists()) {
            throw new RuntimeException('PKG-02B vehicle Finance records exist; preserve them instead of rolling back the vehicle Finance view.');
        }

        Schema::dropIfExists('fleet_finance_review_request_events');
        Schema::dropIfExists('fleet_finance_review_requests');
        Schema::dropIfExists('fleet_vehicle_finance_links');
    }
};
