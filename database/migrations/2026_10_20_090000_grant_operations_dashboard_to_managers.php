<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operations dashboard is for schedulers, managers and admins
 * (routes/operations.php), but only admin was ever granted it, so provider
 * managers, the CEO and the COO opened a Manager overview of "Permission
 * required" cards (found building the Business support overview; Stephan:
 * "please complete", 20 Oct).
 *
 * - provider_manager → operations.dashboard.view: their own sites. They
 *   already read every shift, timesheet and client behind it.
 * - ceo, coo → operations.dashboard.view and operations.dashboard.viewAllSites.
 *
 * Read only. Deploys run migrations and skip seeders, so this is the grant;
 * OperationsPermissionsSeeder::DASHBOARD_ROLE_GRANTS names the same keys. It
 * only adds missing rows, a second run changes nothing, and a grant an admin
 * later removes in Settings › Roles stays removed.
 */
return new class extends Migration
{
    private const GRANTS = [
        'provider_manager' => ['operations.dashboard.view'],
        'ceo' => ['operations.dashboard.view', 'operations.dashboard.viewAllSites'],
        'coo' => ['operations.dashboard.view', 'operations.dashboard.viewAllSites'],
    ];

    public function up(): void
    {
        $this->each(fn (int $roleId, int $permissionId) => DB::table('role_permission')
            ->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]));
    }

    /**
     * Removes these rows only. role_permission has no provenance, so a grant
     * made by hand before this ran can't be told apart; none of these roles
     * was seeded with these keys when it was written.
     */
    public function down(): void
    {
        $this->each(fn (int $roleId, int $permissionId) => DB::table('role_permission')
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete());
    }

    private function each(callable $apply): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function () use ($apply): void {
            foreach (self::GRANTS as $role => $keys) {
                $roleId = DB::table('roles')->where('name', $role)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                    $apply((int) $roleId, (int) $permissionId);
                }
            }
        });
    }
};
