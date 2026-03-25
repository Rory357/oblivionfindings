<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('care_plan_goal_id')->nullable();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('note_type')->default('general');
            $table->text('content');
            $table->unsignedTinyInteger('mood_rating')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->string('flagged_reason')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('visibility')->default('staff_only');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('care_plan_goal_id')->references('id')->on('care_plan_goals')->nullOnDelete();

            $table->index(['client_id', 'note_type']);
            $table->index('author_id');
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_notes');
    }
};
