<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Amendment tracking on time entries
        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->foreignId('amended_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('amended_at')->nullable()->after('amended_by');
            $table->text('amendment_reason')->nullable()->after('amended_at');
            $table->json('original_values')->nullable()->after('amendment_reason');
        });

        // Return-for-changes on timesheets
        Schema::table('hr_timesheets', function (Blueprint $table) {
            $table->foreignId('returned_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('returned_notes')->nullable()->after('returned_at');
        });

        // Granular amendment audit log
        Schema::create('hr_time_entry_amendments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hr_time_entry_id')->constrained('hr_time_entries')->cascadeOnDelete();
            $table->foreignId('amended_by')->constrained('users')->cascadeOnDelete();
            $table->string('field_name', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['hr_time_entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_time_entry_amendments');

        Schema::table('hr_timesheets', function (Blueprint $table) {
            $table->dropColumn(['returned_notes', 'returned_at']);
            $table->dropConstrainedForeignId('returned_by');
        });

        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->dropColumn(['original_values', 'amendment_reason', 'amended_at']);
            $table->dropConstrainedForeignId('amended_by');
        });
    }
};
