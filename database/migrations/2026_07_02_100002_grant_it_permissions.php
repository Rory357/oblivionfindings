<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IT & Provisioning surface (/it) is guarded by the new it.view /
 * it.manage keys. Those keys are defined and granted in RbacSeeder, but
 * deploys run migrations and skip seeders — so on deployed environments no
 * role would hold the permission and /it would 403 for everyone.
 *
 * This grants them via migration, mirroring the established
 * grant_hr_recognition_permissions pattern. Grantees mirror the roles that
 * hold hr.onboarding.manage (admin gets everything in the seeder; here it is
 * granted explicitly): admin, provider_manager, hr.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasGroup = Schema::hasColumn('permissions', 'group');
        $hasModule = Schema::hasColumn('permissions', 'module');

        $newPerms = [
            ['key' => 'it.view', 'description' => 'View the IT & Provisioning queues'],
            ['key' => 'it.manage', 'description' => 'Work IT provisioning requests and helpdesk tickets'],
        ];

        $permIds = [];
        foreach ($newPerms as $perm) {
            $id = DB::table('permissions')->where('key', $perm['key'])->value('id');
            if (! $id) {
                $row = [
                    'key' => $perm['key'],
                    'description' => $perm['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($hasGroup) {
                    $row['group'] = 'it';
                }
                if ($hasModule) {
                    $row['module'] = 'Operations';
                }
                $id = DB::table('permissions')->insertGetId($row);
            }
            $permIds[$perm['key']] = $id;
        }

        $both = [$permIds['it.view'], $permIds['it.manage']];

        // Same roles that hold hr.onboarding.manage, plus admin.
        $attachMap = [
            'admin' => $both,
            'provider_manager' => $both,
            'hr' => $both,
        ];

        $roleIds = DB::table('roles')->whereIn('name', array_keys($attachMap))->pluck('id', 'name');

        foreach ($attachMap as $roleName => $permissionIds) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }
            foreach ($permissionIds as $id) {
                if (! $id) {
                    continue;
                }
                $exists = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $id)
                    ->exists();
                if (! $exists) {
                    DB::table('role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Only detach the grants; the permission rows are owned by RbacSeeder
        // and may pre-date this migration.
        $ids = DB::table('permissions')
            ->whereIn('key', ['it.view', 'it.manage'])
            ->pluck('id');

        if ($ids->count()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        }
    }
};
