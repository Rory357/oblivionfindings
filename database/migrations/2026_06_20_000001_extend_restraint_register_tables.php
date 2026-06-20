<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restraints & Behaviour Support redesign — schema spine.
 *
 *  - behaviour_support_plans: add lifecycle audit columns (status_changed_at/by)
 *    so plan activate/submit-review/archive transitions are attributable.
 *  - behaviour_support_plan_reviews: per-plan review history (outcome, next review
 *    date, resulting status) surfaced in the plan-detail "Reviews" section.
 *  - restraint_event_attachments: premium document/photo evidence for an event
 *    (body maps, injury photos, authorisation forms, debrief notes). Mirrors the
 *    client_incident_attachments shape so the shared AttachmentUploader can post here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('behaviour_support_plans', function (Blueprint $table) {
            $table->dateTime('status_changed_at')->nullable()->after('status');
            $table->unsignedBigInteger('status_changed_by')->nullable()->after('status_changed_at');
            $table->foreign('status_changed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('behaviour_support_plan_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('behaviour_support_plan_id');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at');
            $table->string('outcome'); // continued, modified, reduced, discontinued, escalated
            $table->date('next_review_date')->nullable();
            $table->string('resulting_status')->nullable(); // plan status after this review
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('behaviour_support_plan_id', 'bsp_reviews_plan_fk')
                ->references('id')->on('behaviour_support_plans')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('behaviour_support_plan_id', 'bsp_reviews_plan_idx');
        });

        Schema::create('restraint_event_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restraint_event_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('disk')->default('public');

            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->string('category')->nullable(); // body_map, injury_photo, authorisation, debrief, other
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('restraint_event_id', 'rea_event_fk')
                ->references('id')->on('restraint_events')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->index('restraint_event_id', 'rea_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restraint_event_attachments');
        Schema::dropIfExists('behaviour_support_plan_reviews');

        Schema::table('behaviour_support_plans', function (Blueprint $table) {
            $table->dropForeign(['status_changed_by']);
            $table->dropColumn(['status_changed_at', 'status_changed_by']);
        });
    }
};
