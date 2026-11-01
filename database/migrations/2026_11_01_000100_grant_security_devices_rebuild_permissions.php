<?php

use App\Domain\SecurityDevices\Support\SecurityDevicesRebuildPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The S&D rebuild's new permission keys (plan v10, "New permissions and their grant migrations").
 *
 * Deploys skip seeders, so each key is granted here to exactly the roles that hold the key it
 * comes from today, and personal allow or deny overrides are copied the same way. Nobody loses
 * access on deploy and nobody gains more.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (SecurityDevicesRebuildPermissions::KEYS as $key => $definition) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'key' => $key,
                    'description' => $definition['description'],
                    'group' => $definition['group'],
                    'module' => $definition['module'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $fromId = DB::table('permissions')->where('key', $definition['from'])->value('id');
            if (! $fromId) {
                continue;
            }

            $roleIds = DB::table('role_permission')->where('permission_id', $fromId)->pluck('role_id');
            $alreadyRoles = DB::table('role_permission')->where('permission_id', $permissionId)->pluck('role_id');
            foreach ($roleIds->diff($alreadyRoles) as $roleId) {
                DB::table('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }

            $overrides = DB::table('permission_user')->where('permission_id', $fromId)->get(['user_id', 'allowed']);
            $alreadyUsers = DB::table('permission_user')->where('permission_id', $permissionId)->pluck('user_id');
            foreach ($overrides as $override) {
                if ($alreadyUsers->contains($override->user_id)) {
                    continue;
                }
                DB::table('permission_user')->insert([
                    'user_id' => $override->user_id,
                    'permission_id' => $permissionId,
                    'allowed' => $override->allowed,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: removing access mid-release is an
        // administrative decision, not a rollback side effect.
    }
};
