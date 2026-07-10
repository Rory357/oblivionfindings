<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several demo seeders (FleetManagementSeeder's "Main Site",
 * MedicationRoundsDemoSeeder's houses) create sites without a tenant_id, and
 * `sites.tenant_id` defaults to NULL. The finance capture observers
 * (FleetFuelLogObserver, AssetMaintenanceLogObserver, HouseLedgerEntryObserver)
 * resolve their posting organisation from the site's tenant_id and silently
 * skip when it is falsy — so fuel, maintenance and house-ledger costs logged
 * against those sites never reach the GL even with a seeded chart.
 *
 * This app is single-tenant: users.organization_id defaults to 1 and
 * SiteFactory sets tenant_id = 1. NULL-tenant sites are seeder gaps, not a
 * second tenant. Backfill them to 1 — only filling NULLs, never overwriting —
 * and only on demo/test environments (marker: the seeded demo admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('users')) {
            return;
        }

        $isDemo = DB::table('users')->where('email', 'admin@demo.test')->exists();
        if (! $isDemo) {
            return;
        }

        DB::table('sites')->whereNull('tenant_id')->update(['tenant_id' => 1]);
    }

    public function down(): void
    {
        // Irreversible by design: we cannot distinguish backfilled rows from
        // sites that legitimately carried tenant_id 1 before this migration.
    }
};
