<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_participation_meeting_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('meeting_id')->constrained('hs_committee_meetings', indexName: 'wp_event_meeting_fk')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            // Attendee additions retain the current source epoch; a changed source creates a new one.
            $table->uuid('source_epoch_id');
            $table->foreignId('committee_id')->constrained('hs_committees', indexName: 'wp_event_committee_fk')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites', indexName: 'wp_event_site_fk')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users', indexName: 'wp_event_actor_fk')->restrictOnDelete();
            $table->string('purpose', 24);
            $table->json('source_descriptor');
            $table->string('source_digest', 64);
            $table->json('audience_membership');
            $table->string('audience_digest', 64);
            $table->timestamps();
            $table->unique(['meeting_id', 'sequence'], 'wp_event_sequence_unique');
        });
        Schema::create('worker_participation_meeting_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('worker_participation_meeting_events', indexName: 'wp_delivery_event_fk')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users', indexName: 'wp_delivery_recipient_fk')->restrictOnDelete();
            $table->string('audience', 24);
            $table->string('database_state', 20)->default('pending');
            $table->string('mail_state', 20)->default('pending');
            $table->unsignedInteger('database_attempts')->default(0);
            $table->unsignedInteger('mail_attempts')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('database_delivered_at')->nullable();
            $table->dateTime('mail_started_at')->nullable();
            $table->uuid('mail_claim_id')->nullable();
            $table->dateTime('mail_delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'recipient_user_id', 'audience'], 'wp_delivery_recipient_unique');
            $table->index(['database_state', 'next_attempt_at'], 'wp_delivery_database_retry_idx');
            $table->index(['mail_state', 'next_attempt_at'], 'wp_delivery_mail_retry_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('worker_participation_meeting_events')->exists() || DB::table('worker_participation_meeting_deliveries')->exists()) {
            throw new LogicException('Retained participation delivery evidence must not be dropped.');
        }
        Schema::dropIfExists('worker_participation_meeting_deliveries');
        Schema::dropIfExists('worker_participation_meeting_events');
    }
};
