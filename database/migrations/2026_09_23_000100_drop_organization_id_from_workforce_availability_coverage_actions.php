<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The application is single-tenant. 2026_08_23_000230 (deployed, pinned by
 * exact hash in ItSecuritySingleTenantBoundaryTest) gave this table an inert
 * organisation column that ownership and access never read, so drop it here
 * instead of editing the historical migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workforce_availability_coverage_actions')
            || ! Schema::hasColumn('workforce_availability_coverage_actions', 'organization_id')
        ) {
            return;
        }

        $hasIndex = Schema::hasIndex('workforce_availability_coverage_actions', ['organization_id']);

        Schema::table('workforce_availability_coverage_actions', function (Blueprint $table) use ($hasIndex): void {
            if ($hasIndex) {
                $table->dropIndex(['organization_id']);
            }

            $table->dropColumn('organization_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workforce_availability_coverage_actions')
            || Schema::hasColumn('workforce_availability_coverage_actions', 'organization_id')
        ) {
            return;
        }

        Schema::table('workforce_availability_coverage_actions', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
        });
    }
};
