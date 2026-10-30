<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet-wide settings: a vehicle checklist used beyond one vehicle, and the
 * driving score policy, apply at every Site. Changing them needs central
 * authority rather than a Site manager's fleet access.
 *
 * Deploys don't run seeders, so this grants what RbacSeeder seeds: admin and
 * the Fleet Manager role (created by 2026_09_24_000200 when missing).
 */
return new class extends Migration
{
    private const PERMISSION = 'fleet.settings.manage';

    private const DESCRIPTION = 'Change fleet-wide settings every Site shares: vehicle checklists used beyond one vehicle and the driving score policy';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->insertOrIgnore([
                'key' => self::PERMISSION,
                'description' => self::DESCRIPTION,
                'group' => 'fleet',
                'module' => 'Resources',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');

            foreach (DB::table('roles')->whereIn('name', ['admin', 'fleet_manager'])->pluck('id') as $roleId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
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
