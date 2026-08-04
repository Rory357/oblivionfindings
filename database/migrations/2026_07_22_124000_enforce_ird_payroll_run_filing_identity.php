<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_INDEX = 'fin_ird_filings_payroll_run_id_index';

    private const UNIQUE_INDEX = 'fin_ird_filings_payroll_run_id_uq';

    public function up(): void
    {
        if (! Schema::hasIndex('fin_ird_filings', self::UNIQUE_INDEX)) {
            $duplicate = DB::table('fin_ird_filings')
                ->select('payroll_run_id', DB::raw('COUNT(*) as filing_count'))
                ->whereNotNull('payroll_run_id')
                ->groupBy('payroll_run_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('payroll_run_id')
                ->first();

            if ($duplicate !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce payday filing identity: payroll run %d already has %d IRD filings.',
                    $duplicate->payroll_run_id,
                    $duplicate->filing_count,
                ));
            }

            // Install the stronger constraint first so a rolling deploy never
            // has a window with no payroll-run lookup/identity index.
            Schema::table('fin_ird_filings', function (Blueprint $table): void {
                $table->unique('payroll_run_id', self::UNIQUE_INDEX);
            });
        }

        if (Schema::hasIndex('fin_ird_filings', self::LEGACY_INDEX)) {
            Schema::table('fin_ird_filings', function (Blueprint $table): void {
                $table->dropIndex(self::LEGACY_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Restore the legacy lookup index before removing the stronger identity.
        if (! Schema::hasIndex('fin_ird_filings', self::LEGACY_INDEX)) {
            Schema::table('fin_ird_filings', function (Blueprint $table): void {
                $table->index('payroll_run_id', self::LEGACY_INDEX);
            });
        }

        if (Schema::hasIndex('fin_ird_filings', self::UNIQUE_INDEX)) {
            Schema::table('fin_ird_filings', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }
    }
};
