<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Insert permission if missing
        $permId = DB::table('permissions')->where('key', 'settings.branding.manage')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId([
                'key' => 'settings.branding.manage',
                'description' => 'Manage organisation branding (colors, logo)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Attach to admin role if present
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminRoleId && $permId) {
            $exists = DB::table('role_permission')
                ->where('role_id', $adminRoleId)
                ->where('permission_id', $permId)
                ->exists();
            if (!$exists) {
                DB::table('role_permission')->insert([
                    'role_id' => $adminRoleId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'settings.branding.manage')->value('id');
        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
