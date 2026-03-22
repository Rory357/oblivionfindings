<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('criteria'); // array of {name, rating(1-5), notes}
            $table->unsignedTinyInteger('overall_rating')->nullable(); // 1-5
            $table->string('recommendation'); // strong_yes, yes, neutral, no, strong_no
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->text('overall_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'interview_id']);
            $table->unique(['interview_id', 'interviewer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interview_scorecards');
    }
};
