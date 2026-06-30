<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capture confidence on each goal-level check-in so the detail timeline
        // can show a RAG trend.
        Schema::table('hr_goal_updates', function (Blueprint $table) {
            $table->string('confidence')->nullable()->after('progress_percentage'); // on_track, at_risk, off_track
        });

        // KR-level check-in log so a key result's history is first-class.
        Schema::create('hr_key_result_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('key_result_id')->constrained('hr_key_results')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('previous_value', 10, 2)->nullable();
            $table->decimal('new_value', 10, 2)->nullable();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->string('confidence')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('key_result_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_key_result_updates');

        Schema::table('hr_goal_updates', function (Blueprint $table) {
            $table->dropColumn('confidence');
        });
    }
};
