<?php

use App\Domain\Referrals\ReferralPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deploys run migrations and skip seeders, so this grants the referral role presets
 * (ReferralPermissions::rolePresets, specification §10) that RbacSeeder applies on a fresh seed.
 *
 * Only adds rows: other roles and per-user overrides are left alone, a second run changes
 * nothing, and a role that does not exist is skipped. settings.governance goes to the CEO, admin,
 * provider manager and compliance lead (Stephan, 21 Oct 2026); admin receives no other independent key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (ReferralPermissions::definitions() as $definition) {
                DB::table('permissions')->insertOrIgnore([...$definition, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (ReferralPermissions::rolePresets() as $role => $keys) {
                $roleId = DB::table('roles')->where('name', $role)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep role grants on rollback: an administrator may have customised them, and
        // role_permission has no provenance to tell this migration's rows apart.
    }
};
