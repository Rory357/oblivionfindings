<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #13: an administrator's recorded reset of a risk assessment's sealed change history after a break was investigated
 * (`php artisan health-safety:risk-history {id} --reset`). Sealed entries up to after_sequence stay in place, unverified;
 * a new sealed chain starts after them, so a break can never lock the team out of the assessment for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_risk_assessment_history_resets')) {
            return;
        }
        Schema::create('hs_risk_assessment_history_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('hs_risk_assessments')->restrictOnDelete();
            $table->unsignedInteger('after_sequence');
            $table->text('reason');
            $table->foreignId('reset_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('audit_log_id')->nullable()->constrained('audit_logs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['assessment_id', 'after_sequence'], 'hsra_history_reset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_risk_assessment_history_resets');
    }
};
