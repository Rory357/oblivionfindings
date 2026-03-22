<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('interview_date');
            $table->string('departure_reason');
            $table->boolean('would_recommend')->nullable();
            $table->unsignedTinyInteger('overall_satisfaction')->nullable(); // 1-5
            $table->text('what_went_well')->nullable();
            $table->text('what_could_improve')->nullable();
            $table->text('management_feedback')->nullable();
            $table->text('culture_feedback')->nullable();
            $table->text('additional_comments')->nullable();
            $table->boolean('is_confidential')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'interview_date']);
            $table->index(['tenant_id', 'departure_reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_interviews');
    }
};
