<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * operations.dashboard.viewAllSites has only ever been created by
 * OperationsPermissionsSeeder, and deploys skip seeders, so sites set up
 * before it existed (the local dev database among them) never had it:
 * 2026_10_20_090000 could grant the CEO and COO only the dashboard itself,
 * scoped to sites they are not staffed at. This creates the permission and
 * grants it to admin (who holds every non-restricted key), the CEO and the
 * COO, as the seeders do. Read only; a second run changes nothing.
 */
return new class extends Migration
{
    private const KEY = 'operations.dashboard.viewAllSites';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            // Same labels as the dashboard permission it widens.
            $dashboard = DB::table('permissions')->where('key', 'operations.dashboard.view')->first(['group', 'module']);
            DB::table('permissions')->insertOrIgnore([
                'key' => self::KEY, 'description' => 'View Operations Dashboard Across All Sites',
                'group' => $dashboard->group ?? 'general', 'module' => $dashboard->module ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
            foreach (DB::table('roles')->whereIn('name', ['admin', 'ceo', 'coo'])->pluck('id') as $role) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        });
    }

    public function down(): void
    {
        // Keep the permission and its grants, including changes made after deployment.
    }
};
