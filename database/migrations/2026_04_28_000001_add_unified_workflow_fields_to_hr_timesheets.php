<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_timesheets', 'submitted_by')) {
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_timesheets', 'decision_notes')) {
                $table->text('decision_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('hr_timesheets', 'submitted_by')) {
                $table->dropConstrainedForeignId('submitted_by');
            }

            if (Schema::hasColumn('hr_timesheets', 'decision_notes')) {
                $table->dropColumn('decision_notes');
            }
        });
    }
};
