<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('review_type'); // peer, manager, direct_report, self
            $table->foreignId('performance_review_id')->nullable()->constrained('hr_performance_reviews')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, completed, declined, expired
            $table->date('due_date')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reviewer_user_id', 'status']);
        });

        Schema::create('hr_feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_request_id')->constrained('hr_feedback_requests')->cascadeOnDelete();
            $table->string('question_key');
            $table->integer('rating')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_feedback_responses');
        Schema::dropIfExists('hr_feedback_requests');
    }
};
