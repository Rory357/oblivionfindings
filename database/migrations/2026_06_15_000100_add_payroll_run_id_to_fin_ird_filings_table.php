<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ird_filings', function (Blueprint $table) {
            // Link a payday filing back to the HR payroll run it was built from.
            // No cross-domain FK constraint (HR table) — just a nullable indexed
            // column so the dashboard can find the run's filing and the index can
            // exclude runs that already have one (filing_data is encrypted, so it
            // can't be queried for this).
            $table->unsignedBigInteger('payroll_run_id')->nullable()->after('gst_return_id');
            $table->index('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ird_filings', function (Blueprint $table) {
            $table->dropIndex(['payroll_run_id']);
            $table->dropColumn('payroll_run_id');
        });
    }
};
