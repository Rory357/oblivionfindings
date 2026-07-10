<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll statutory + surfacing gaps (finance↔HR seam):
 * - hr_payslips.esct — ESCT on the employer KiwiSaver contribution was not
 *   tracked, so the IRD payday filing hardcoded total_esct '0.00' and the
 *   payroll journal remitted the employer's GROSS contribution to the fund.
 * - hr_payroll_runs.gl_error — a failed GL post (PostPayrollJournalJob,
 *   tries=1) only reached failed_jobs; the run showed an un-posted journal
 *   with no reason and no retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('esct', 12, 2)->default(0)->after('kiwisaver_employer');
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->text('gl_error')->nullable()->after('gl_posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('esct');
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('gl_error');
        });
    }
};
