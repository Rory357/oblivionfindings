<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR Lane C1 (EA-027, EA-090): who runs a downtime and its paper records
 * is a permission, not a hard-coded list of role names. The new key
 * medications.downtime.manage goes to exactly the roles DowntimeAccess
 * named before (admin, provider_manager, coordinator, clinical_lead,
 * team_lead), so nobody gains or loses anything on deploy; an organisation
 * can now give it to another role (an on-site team leader) in
 * Settings › Roles. Holders also make the downtime pack (approved P10:
 * house leads and clinical leads).
 *
 * Fresh seeds get the same grants from MedicationDowntimePermissionsSeeder
 * (RbacSeeder is not edited). Runs once; a grant later removed stays removed.
 */
return new class extends Migration
{
    private const KEY = 'medications.downtime.manage';

    private const ROLES = ['admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore([
                'key' => self::KEY,
                'description' => 'Run medication downtimes: declare them, make the downtime pack, review and finish paper records',
                'group' => 'medications',
                'module' => 'Clinical',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
            foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', self::KEY)->delete();
        }
    }
};
