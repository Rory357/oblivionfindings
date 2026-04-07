<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'payroll_segments_exported')) {
                $table->json('payroll_segments_exported')->nullable()->after('payroll_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'payroll_segments_exported')) {
                $table->dropColumn('payroll_segments_exported');
            }
        });
    }
};
