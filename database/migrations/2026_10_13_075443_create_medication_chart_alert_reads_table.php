<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR audit Lane A5 (EA-005): a chart alert set to "Prompt staff" is shown
 * where a dose is recorded, and each worker's reading of it is kept once per
 * NZ day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medication_chart_alert_reads')) {
            return;
        }

        Schema::create('medication_chart_alert_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_alert_id')->constrained('client_medication_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // The worker's NZ calendar day the alert was read on.
            $table->date('read_on');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['client_medication_alert_id', 'user_id', 'read_on'], 'mcar_alert_user_day_unique');
            $table->index(['user_id', 'read_on'], 'mcar_user_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_chart_alert_reads');
    }
};
