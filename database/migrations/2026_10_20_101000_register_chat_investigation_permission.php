<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private staff chats may be inspected only by specifically authorised
 * investigators. The key is registered without any role grant: it is
 * assigned to named people (Settings › Roles or a per-user override).
 * It is listed in RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY, and this
 * migration also removes it from the admin role in case an older admin
 * backfill seeder handed it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => 'chat.investigate',
            'description' => 'Inspect private staff chats for an authorised investigation and place preservation holds',
            'group' => 'chat',
            'module' => 'System',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Deliberately no role grants.
        $adminId = Schema::hasTable('roles') ? DB::table('roles')->where('name', 'admin')->value('id') : null;
        $permissionId = DB::table('permissions')->where('key', 'chat.investigate')->value('id');
        if ($adminId && $permissionId && Schema::hasTable('role_permission')) {
            DB::table('role_permission')->where('role_id', $adminId)->where('permission_id', $permissionId)->delete();
        }
    }

    public function down(): void
    {
        // Preserve the permission identity and any explicit assignments.
    }
};
