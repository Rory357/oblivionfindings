<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the legacy interview-scorecard stack. Structured interview scoring
 * now lives on `hr_interview_scores` (kit-driven, written from the unified
 * recruitment hub); the old free-text `hr_interview_scorecards` table and its
 * controller/pages have been removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('hr_interview_scorecards');
    }

    public function down(): void
    {
        Schema::create('hr_interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('criteria');
            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->string('recommendation');
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->text('overall_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'interview_id']);
            $table->unique(['interview_id', 'interviewer_user_id']);
        });
    }
};
