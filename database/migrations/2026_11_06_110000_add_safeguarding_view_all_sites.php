<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Readiness audit P6 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Remove it"). Safeguarding concerns at every Site
 * used to open to anyone holding "View reports" (reports.viewAny), so HR, provider managers and auditors saw every
 * Site's concerns. Now every-Site safeguarding needs its own key, safeguarding.viewAllSites. Everyone else sees
 * concerns at their own Sites (their HR profile Sites) only.
 *
 * Granted to admin, provider_manager and auditor, which already saw every Site (Stephan, 7 Nov 2026, for provider
 * managers and auditors: "Keep their every-Site view"). Who loses every-Site safeguarding (they keep their own Sites):
 * hr, plus anyone given reports.viewAny directly. Give the key in Settings › Roles or to a named person if someone
 * needs every Site.
 *
 * Deploys don't run seeders, so this creates the key RbacSeeder seeds.
 */
return new class extends Migration
{
    private const KEY = 'safeguarding.viewAllSites';

    private const DESCRIPTION = 'See safeguarding concerns at every Site, when paired with safeguarding access';

    private const ROLES = ['admin', 'provider_manager', 'auditor'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->insertOrIgnore([
                'key' => self::KEY,
                'description' => self::DESCRIPTION,
                'group' => 'safeguarding',
                'module' => 'Compliance',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
            if ($permissionId === null) {
                return;
            }
            foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
            if ($permissionId === null) {
                return;
            }
            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            }
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('id', $permissionId)->delete();
        });
    }
};
