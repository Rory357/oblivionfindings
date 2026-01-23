<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $perms = [
            ['key' => 'timeline.viewAny', 'description' => 'View timelines (staff/client activity)'],
            ['key' => 'timeline.create', 'description' => 'Create timeline events (notes/incidents)'],
            ['key' => 'summaries.viewAny', 'description' => 'View AI summaries'],
            ['key' => 'summaries.generate', 'description' => 'Generate AI summaries'],
            ['key' => 'unifi.manage', 'description' => 'Manage UniFi integration settings'],
        ];

        $permIds = [];
        foreach ($perms as $perm) {
            $id = DB::table('permissions')->where('key', $perm['key'])->value('id');
            if (!$id) {
                $id = DB::table('permissions')->insertGetId([
                    'key' => $perm['key'],
                    'description' => $perm['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $permIds[$perm['key']] = $id;
        }

        // Attach to roles if present
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'provider_manager', 'support_worker'])->pluck('id', 'name');

        $attachMap = [
            'admin' => array_values($permIds),
            'provider_manager' => [
                $permIds['timeline.viewAny'],
                $permIds['timeline.create'],
                $permIds['summaries.viewAny'],
                $permIds['summaries.generate'],
                $permIds['unifi.manage'],
            ],
            'support_worker' => [
                $permIds['timeline.create'],
            ],
        ];

        foreach ($attachMap as $roleName => $permissionIds) {
            $roleId = $roleIds[$roleName] ?? null;
            if (!$roleId) {
                continue;
            }
            foreach ($permissionIds as $id) {
                if (!$id) continue;
                $exists = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $id)
                    ->exists();
                if (!$exists) {
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
        $keys = ['timeline.viewAny', 'timeline.create', 'summaries.viewAny', 'summaries.generate', 'unifi.manage'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        if ($ids->count()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
