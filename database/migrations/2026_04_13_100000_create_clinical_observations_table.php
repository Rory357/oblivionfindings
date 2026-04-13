<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();

            $table->string('observation_type');        // ObservationType enum value
            $table->timestamp('recorded_at');
            $table->json('data');                      // Schema varies by observation_type
            $table->text('notes')->nullable();

            // Protocol linkage
            $table->foreignId('protocol_schedule_id')->nullable();

            // Flagging
            $table->boolean('is_flagged')->default(false);
            $table->string('flagged_reason')->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();

            // Correction workflow (mirrors eMAR pattern)
            $table->foreignId('correction_of_id')->nullable()->constrained('clinical_observations')->nullOnDelete();
            $table->string('correction_status')->nullable();    // pending, approved, rejected
            $table->text('correction_reason')->nullable();
            $table->foreignId('correction_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('correction_approved_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Query indexes (explicit names to stay within MySQL 64-char limit)
            $table->index(['client_id', 'observation_type', 'recorded_at'], 'clin_obs_client_type_recorded');
            $table->index(['shift_id', 'recorded_at'], 'clin_obs_shift_recorded');
            $table->index(['site_id', 'recorded_at'], 'clin_obs_site_recorded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_observations');
    }
};
