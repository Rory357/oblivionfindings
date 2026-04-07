<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('timesheets')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected

            // What changed
            $table->json('original_values');   // snapshot of fields before correction
            $table->json('proposed_values');    // requested new values
            $table->text('reason');             // why the correction is needed

            // Who requested
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');

            // Who reviewed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Payroll tracking
            $table->boolean('payroll_adjustment_required')->default(false);
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['timesheet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_amendments');
    }
};
