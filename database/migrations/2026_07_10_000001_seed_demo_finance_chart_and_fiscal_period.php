<?php

use Database\Seeders\FinanceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The demo server's finance module was left completely unseeded after a data
 * reset (0 GL accounts, 0 fiscal periods), so every observer-dispatched GL
 * posting — fuel, asset maintenance, house-ledger groceries, funding claims —
 * throws "account not found" and writes no journal, masking the now-fixed
 * ProcessFinancialEventJob dispatch bug.
 *
 * Deploys run migrations and skip seeders (house rule), so this mirrors the
 * grant-migration pattern: re-seed the canonical chart of accounts, tax rates
 * and currencies for the system org (0) and the operational demo org (1) via
 * FinanceSeeder — pure updateOrInsert, no factories/faker, safe under
 * --no-dev — and ensure an OPEN fiscal period covering today so
 * JournalPostingService accepts postings.
 *
 * Guarded on the demo-admin marker account so a non-demo deployment is left
 * untouched. Idempotent throughout: an existing chart is repaired in place and
 * existing fiscal periods are never modified.
 */
return new class extends Migration
{
    private const ORG_IDS = [0, 1];

    public function up(): void
    {
        foreach (['fin_accounts', 'fin_tax_rates', 'fin_currencies', 'fin_fiscal_periods', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        // Demo/test environments are marked by the seeded demo admin. Real
        // deployments (no demo admin) must not have opinionated finance data
        // injected by a migration.
        $isDemo = DB::table('users')->where('email', 'admin@demo.test')->exists();
        if (! $isDemo) {
            return;
        }

        $seeder = new FinanceSeeder;

        foreach (self::ORG_IDS as $orgId) {
            $seeder->run($orgId);
            $this->ensureOpenFiscalPeriod($orgId);
        }
    }

    /**
     * Ensure the org has an open fiscal period covering today. Never touches
     * existing periods (a deliberately closed period stays closed) — only
     * inserts the calendar-year period when today is entirely uncovered.
     */
    private function ensureOpenFiscalPeriod(int $orgId): void
    {
        $today = Carbon::now()->toDateString();

        $covering = DB::table('fin_fiscal_periods')
            ->where('organization_id', $orgId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        if ($covering) {
            return;
        }

        $name = 'FY'.Carbon::now()->year;

        // (organization_id, name) is unique — bail rather than collide if a
        // same-named period exists but doesn't cover today (odd manual setup).
        $nameTaken = DB::table('fin_fiscal_periods')
            ->where('organization_id', $orgId)
            ->where('name', $name)
            ->exists();

        if ($nameTaken) {
            return;
        }

        DB::table('fin_fiscal_periods')->insert([
            'organization_id' => $orgId,
            'name' => $name,
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date' => Carbon::now()->endOfYear()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Never delete a chart of accounts or fiscal periods on rollback —
        // journals may already reference them.
    }
};
