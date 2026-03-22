<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('survey_type'); // pulse, enps, engagement, custom
            $table->string('status')->default('draft'); // draft, active, closed
            $table->boolean('is_anonymous')->default(true);
            $table->datetime('starts_at')->nullable();
            $table->datetime('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('hr_surveys')->cascadeOnDelete();
            $table->text('question_text');
            $table->string('question_type'); // rating, text, multiple_choice, enps_score
            $table->json('options')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('hr_surveys')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'user_id']);
        });

        Schema::create('hr_survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('hr_survey_responses')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('hr_survey_questions')->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->integer('answer_rating')->nullable();
            $table->string('answer_choice')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_survey_answers');
        Schema::dropIfExists('hr_survey_responses');
        Schema::dropIfExists('hr_survey_questions');
        Schema::dropIfExists('hr_surveys');
    }
};
