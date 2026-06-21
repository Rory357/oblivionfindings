<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The peer-recognition / Community Feed (/hr/feed) route is guarded by
 * hr.recognition.view, and posting kudos by hr.recognition.give. Those keys
 * are defined and granted only in SeedHrPermissionsSeeder. Deploys run
 * migrations and skip seeders, so on deployed environments no role holds the
 * permission and the feed 403s for everyone — including admin.
 *
 * This grants them via migration, mirroring the seeder's role map. Recognition
 * is "for everyone": every staff role gets view + give; auditor is view-only.
 *
 * Mirrors the established grant_clinical_hc_permissions / grant_procedures
 * patterns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasGroup = Schema::hasColumn('permissions', 'group');
        $hasModule = Schema::hasColumn('permissions', 'module');

        $newPerms = [
            ['key' => 'hr.recognition.view', 'description' => 'View the recognition feed'],
            ['key' => 'hr.recognition.give', 'description' => 'Give kudos and post to the recognition feed'],
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
                    $row['group'] = 'hr';
                }
                if ($hasModule) {
                    $row['module'] = 'Human Resources';
                }
                $id = DB::table('permissions')->insertGetId($row);
            }
            $permIds[$perm['key']] = $id;
        }

        $view = $permIds['hr.recognition.view'];
        $give = $permIds['hr.recognition.give'];
        $both = [$view, $give];

        // Mirror SeedHrPermissionsSeeder: admin + hr get everything; every staff
        // role gets view + give (peer recognition is for everyone); auditor is
        // view-only.
        $attachMap = [
            'admin' => $both,
            'hr' => $both,
            'support_worker' => $both,
            'team_lead' => $both,
            'coordinator' => $both,
            'provider_manager' => $both,
            'clinical_lead' => $both,
            'finance' => $both,
            'auditor' => [$view],
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
        // Only detach the grants; leave the permission rows in place since they
        // are owned by SeedHrPermissionsSeeder and may pre-date this migration.
        $ids = DB::table('permissions')
            ->whereIn('key', ['hr.recognition.view', 'hr.recognition.give'])
            ->pluck('id');

        if ($ids->count()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        }
    }
};
