<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dateTime('submitted_at')->nullable()->after('status');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();

            // Notes captured during approval/rejection
            $table->text('decision_notes')->nullable()->after('approved_at');

            // Returned for changes
            $table->dateTime('returned_at')->nullable()->after('decision_notes');
            $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->text('returned_notes')->nullable()->after('returned_by');

            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');
            $table->dropColumn('decision_notes');
            $table->dropColumn('returned_at');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn('returned_notes');
        });
    }
};
