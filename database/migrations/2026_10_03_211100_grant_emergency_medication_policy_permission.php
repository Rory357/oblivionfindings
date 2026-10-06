<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }
        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore(['key' => 'medications.emergency_policy.manage', 'description' => 'Change the application emergency medication access policy', 'group' => 'medications', 'module' => 'Clinical', 'created_at' => now(), 'updated_at' => now()]);
            $permission = DB::table('permissions')->where('key', 'medications.emergency_policy.manage')->value('id');
            foreach (DB::table('roles')->whereIn('name', ['admin', 'provider_manager'])->pluck('id') as $role) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        });
    }

    public function down(): void
    {
        // Preserve role and individual grants, including changes made after deployment.
    }
};
