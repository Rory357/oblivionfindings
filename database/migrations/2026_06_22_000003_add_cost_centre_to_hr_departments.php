<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost centre / GL label on a department — a free-text payroll/reporting
 * cross-reference (deliberately NOT FK'd to the Finance fin_cost_centres
 * domain). Additive + nullable → safe on a populated database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_departments') && ! Schema::hasColumn('hr_departments', 'cost_centre')) {
            Schema::table('hr_departments', function (Blueprint $table) {
                $table->string('cost_centre')->nullable()->after('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_departments', 'cost_centre')) {
            Schema::table('hr_departments', function (Blueprint $table) {
                $table->dropColumn('cost_centre');
            });
        }
    }
};
