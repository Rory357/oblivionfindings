<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Health & Clinical redesign added five new permission keys that are
 * defined in RbacSeeder but not granted via any previous migration.
 * Deploys run migrations and skip seeders, so without this the redesigned
 * routes 403 for everyone until a manual --force reseed.
 *
 * Mirrors the established grant_procedures_permissions pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasGroup = \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'group');
        $hasModule = \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'module');

        $newPerms = [
            ['key' => 'clinical.events.escalate',    'description' => 'Escalate clinical events to on-call clinical leadership'],
            ['key' => 'clinical.behaviour.viewAny',  'description' => 'View the cross-client behaviour (ABC) register'],
            ['key' => 'clinical.monitoring.viewAny', 'description' => 'View the cross-client health-monitoring rollup'],
            ['key' => 'clinical.assessments.viewAny','description' => 'View the clinical risk-assessments register'],
            ['key' => 'clinical.assessments.record', 'description' => 'Record clinical risk assessments (FRAT, Braden, MUST, IDDSI)'],
        ];

        $permIds = [];
        foreach ($newPerms as $perm) {
            $id = DB::table('permissions')->where('key', $perm['key'])->value('id');
            if (! $id) {
                $row = [
                    'key'         => $perm['key'],
                    'description' => $perm['description'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
                if ($hasGroup) {
                    $row['group'] = 'clinical';
                }
                if ($hasModule) {
                    $row['module'] = 'Health & Clinical';
                }
                $id = DB::table('permissions')->insertGetId($row);
            }
            $permIds[$perm['key']] = $id;
        }

        $all     = array_values($permIds);
        $noEscalate = [
            $permIds['clinical.behaviour.viewAny'],
            $permIds['clinical.monitoring.viewAny'],
            $permIds['clinical.assessments.viewAny'],
            $permIds['clinical.assessments.record'],
        ];

        // Mirror the RbacSeeder grants (roles that already have other clinical.* perms).
        $attachMap = [
            'admin'          => $all,
            'coordinator'    => $all,
            'clinical_lead'  => $all,
            'team_lead'      => $noEscalate,
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
                        'role_id'       => $roleId,
                        'permission_id' => $id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'clinical.events.escalate',
            'clinical.behaviour.viewAny',
            'clinical.monitoring.viewAny',
            'clinical.assessments.viewAny',
            'clinical.assessments.record',
        ];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        if ($ids->count()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
