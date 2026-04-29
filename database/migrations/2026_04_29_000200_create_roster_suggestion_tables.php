<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_suggestion_runs')) {
            Schema::create('roster_suggestion_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->foreignId('roster_period_id')->nullable()->constrained('roster_periods')->nullOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->date('week_start')->index();
                $table->date('week_end')->index();
                $table->string('status', 24)->default('pending')->index();
                $table->string('strategy', 80)->default('eligibility_scoring');
                $table->json('parameters')->nullable();
                $table->json('totals')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->text('failure_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('roster_suggestions')) {
            Schema::create('roster_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('roster_suggestion_run_id')
                    ->constrained('roster_suggestion_runs')
                    ->cascadeOnDelete();
                $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
                $table->foreignId('candidate_user_id')->constrained('users')->cascadeOnDelete();
                $table->integer('rank')->default(1);
                $table->decimal('score', 8, 2)->default(0);
                $table->json('reasons')->nullable();
                $table->json('eligibility_snapshot')->nullable();
                $table->string('status', 24)->default('suggested')->index();
                $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('accepted_at')->nullable();
                $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dismissed_at')->nullable();
                $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['roster_suggestion_run_id', 'shift_id', 'candidate_user_id'],
                    'roster_suggestion_unique_candidate',
                );
                $table->index(['shift_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_suggestions');
        Schema::dropIfExists('roster_suggestion_runs');
    }
};
