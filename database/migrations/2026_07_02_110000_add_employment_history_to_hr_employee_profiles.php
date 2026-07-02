<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-hire support: prior stints are archived into an append-only JSON log on
 * the profile ({start_date, end_date, position_title, position_role,
 * employment_type, archived_at} per stint) so the live columns can hold the
 * current engagement while the record keeps the full employment history
 * (NZ employment records: 7-year retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->json('employment_history')->nullable()->after('termination_reason');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropColumn('employment_history');
        });
    }
};
