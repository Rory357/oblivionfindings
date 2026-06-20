<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safe Work Procedures redesign moves the procedures routes off the piggybacked
 * `hazards.*` permissions onto dedicated `procedures.{view,create,manage,approve}`.
 *
 * Permissions are normally SEEDED (RbacSeeder), but deploys run migrations and skip
 * seeders — so without this the redesigned routes would 403 for everyone (admin
 * included) until a manual reseed. This migration idempotently creates the four
 * permissions and grants them to the same roles RbacSeeder does, mirroring the
 * established add_timeline_summaries_unifi_permissions migration pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perms = [
            ['key' => 'procedures.view', 'description' => 'View safe work procedures'],
            ['key' => 'procedures.create', 'description' => 'Create safe work procedures (draft)'],
            ['key' => 'procedures.manage', 'description' => 'Edit, submit, archive, review & attach documents to safe work procedures'],
            ['key' => 'procedures.approve', 'description' => 'Approve safe work procedures'],
        ];

        $hasGroup = \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'group');
        $hasModule = \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'module');

        $permIds = [];
        foreach ($perms as $perm) {
            $id = DB::table('permissions')->where('key', $perm['key'])->value('id');
            if (! $id) {
                $row = [
                    'key' => $perm['key'],
                    'description' => $perm['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($hasGroup) {
                    $row['group'] = 'procedures';
                }
                if ($hasModule) {
                    $row['module'] = 'Compliance';
                }
                $id = DB::table('permissions')->insertGetId($row);
            }
            $permIds[$perm['key']] = $id;
        }

        $all = array_values($permIds);
        $viewOnly = [$permIds['procedures.view']];

        // Mirror the RbacSeeder role grants. admin must be granted explicitly here
        // because its seeder sync() does not run on deploy.
        $attachMap = [
            'admin' => $all,
            'provider_manager' => $all,
            'coordinator' => $all,
            'health_safety_officer' => $all,
            'team_lead' => $all,
            'auditor' => $viewOnly,
            'maintenance_coordinator' => $viewOnly,
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
        $keys = ['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        if ($ids->count()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
