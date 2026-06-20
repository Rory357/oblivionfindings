<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Frontline workers (support_worker) read the safe work procedures applicable to
 * their role on /hr/my and acknowledge them. The earlier procedures-permissions
 * migration only granted view to managers/auditor/maintenance; this adds the
 * frontline read grant. Idempotent (deploys run migrations, not seeders).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'procedures.view')->value('id');
        if (! $permId) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('name', ['support_worker'])->pluck('id');
        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permId)
                ->exists();
            if (! $exists) {
                DB::table('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'procedures.view')->value('id');
        $roleIds = DB::table('roles')->whereIn('name', ['support_worker'])->pluck('id');
        if ($permId && $roleIds->isNotEmpty()) {
            DB::table('role_permission')->where('permission_id', $permId)->whereIn('role_id', $roleIds)->delete();
        }
    }
};
