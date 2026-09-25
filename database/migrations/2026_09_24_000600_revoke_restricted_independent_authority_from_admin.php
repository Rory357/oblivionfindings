<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Independent safety/privacy decisions belong to the roles RbacSeeder's
 * explicit product policy names (Team Lead, H&S Officer, Compliance Lead).
 * Before this fix, OperationsPermissionsSeeder and
 * SeedAllPermissionsToAdminSeeder handed every permission to admin, so a full
 * db:seed or a routine re-run of either seeder gave admin these decisions.
 *
 * Deploys don't run seeders, so this removes them from the admin role on
 * environments that were already seeded. Other roles and per-user overrides
 * are left alone. The keys match RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY
 * on 2026-09-24; a key added to that list later needs its own migration.
 */
return new class extends Migration
{
    private const RESTRICTED_INDEPENDENT_AUTHORITY = [
        'healthSafety.events.close',
        'healthSafety.events.closeAny',
        'healthSafety.closureExceptions.request',
        'healthSafety.closureExceptions.approve',
        'safeguarding.declassification.approve',
        'fleet.maintenance.release',
        'fleet.maintenance.configure',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::RESTRICTED_INDEPENDENT_AUTHORITY)
            ->pluck('id');
        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permission')
            ->where('role_id', $adminId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }

    public function down(): void
    {
        // Rolling back must never hand independent decisions back to admin.
    }
};
