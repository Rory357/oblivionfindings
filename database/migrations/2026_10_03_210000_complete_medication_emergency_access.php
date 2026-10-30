<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('client_break_glass_accesses', function (Blueprint $table): void {
            $table->json('policy_snapshot')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ended_at')->nullable()->index();
            $table->string('ended_how')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('end_reason')->nullable();
            $table->timestamp('review_due_at')->nullable()->index();
        });
        Schema::table('break_glass_flag_dismissals', function (Blueprint $table): void {
            $table->unsignedBigInteger('dismissed_through_access_id')->nullable();
        });
        Schema::create('medication_emergency_access_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_id')->constrained('client_break_glass_accesses');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('previous_expires_at');
            $table->timestamp('expires_at');
            $table->text('reason');
            $table->timestamp('created_at');
        });
        Schema::create('medication_emergency_access_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_id')->constrained('client_break_glass_accesses');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome');
            $table->text('notes')->nullable();
            $table->foreignId('incident_report_id')->nullable()->constrained('client_incidents')->nullOnDelete();
            $table->foreignId('medication_error_id')->nullable()->constrained('medication_errors')->nullOnDelete();
            $table->foreignId('corrects_review_id')->nullable()->constrained('medication_emergency_access_reviews');
            $table->text('correction_reason')->nullable();
            $table->boolean('legacy')->default(false);
            $table->timestamp('created_at');
        });
        // Preserve the actual previous review and its timestamp; do not invent missing history.
        DB::table('client_break_glass_accesses')
            ->whereNotNull('review_outcome')->whereNotNull('reviewed_at')
            ->orderBy('id')->each(function ($grant): void {
                DB::table('medication_emergency_access_reviews')->insert([
                    'access_id' => $grant->id, 'user_id' => $grant->reviewed_by,
                    'outcome' => $grant->review_outcome, 'notes' => $grant->review_notes,
                    'incident_report_id' => $grant->incident_report_id,
                    'legacy' => true, 'created_at' => $grant->reviewed_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('break_glass_flag_dismissals', fn (Blueprint $table) => $table->dropColumn('dismissed_through_access_id'));
        Schema::dropIfExists('medication_emergency_access_reviews');
        Schema::dropIfExists('medication_emergency_access_extensions');
        Schema::table('client_break_glass_accesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn(['policy_snapshot', 'confirmed_at', 'ended_at', 'ended_how', 'end_reason', 'review_due_at']);
        });

    }
};
