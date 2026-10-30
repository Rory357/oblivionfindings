<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PIN-1: resetting another person's witness PIN (they must choose a new one;
 * nobody sees the PIN). Stephan, 29 Sep 2026: house leads and clinical leads
 * can reset — the seeded roles are team_lead and clinical_lead, plus admin.
 *
 * Deploys don't run seeders, so this grants what RbacSeeder seeds.
 */
return new class extends Migration
{
    private const PERMISSION = 'medications.witness_pin.reset';

    private const DESCRIPTION = 'Reset another person\'s witness PIN (they must choose a new one; nobody sees the PIN)';

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
                'group' => 'medications',
                'module' => 'Clinical',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');

            foreach (DB::table('roles')->whereIn('name', ['admin', 'team_lead', 'clinical_lead'])->pluck('id') as $roleId) {
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
