<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('behaviour_abc_entries')) {
            return;
        }

        Schema::create('behaviour_abc_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->timestamp('occurred_at')->index();
            $table->string('setting', 255)->nullable();
            $table->string('others_present', 255)->nullable();

            // Antecedent → Behaviour → Consequence (the core ABC record).
            $table->text('antecedent');
            $table->text('behaviour');
            $table->json('behaviour_tags')->nullable();
            $table->text('consequence');

            // PBS analysis. `behaviour_function` (not `function` — reserved word) is
            // the hypothesised function: escape_avoidance, attention_social,
            // tangible_access, sensory_automatic, other.
            $table->string('behaviour_function', 30)->nullable()->index();
            $table->string('intensity', 20)->default('low')->index();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Response & follow-up.
            $table->text('strategies_used')->nullable();
            $table->boolean('harm_occurred')->default(false);
            $table->text('harm_notes')->nullable();
            $table->boolean('escalated')->default(false);
            $table->boolean('requires_followup')->default(false);
            $table->text('followup_notes')->nullable();
            $table->timestamp('followup_completed_at')->nullable();
            $table->foreignId('followup_completed_by')->nullable()->constrained('users')->nullOnDelete();

            // Link to the client's Behaviour Support Plan (a care plan).
            $table->foreignId('linked_care_plan_id')->nullable()->constrained('care_plans')->nullOnDelete();

            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['client_id', 'behaviour_function'], 'abc_client_function_idx');
            // Explicit short names — the auto-generated ones exceed MySQL's 64-char limit.
            $table->index(['requires_followup', 'followup_completed_at'], 'abc_followup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_abc_entries');
    }
};
