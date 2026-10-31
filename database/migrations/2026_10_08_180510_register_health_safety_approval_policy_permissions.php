<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'healthSafety.settings.manage' => ['description' => 'Configure the organisation approval workflow with an attributable change history', 'group' => 'hazards'],
            'restraints.approve' => ['description' => 'Explicitly designated behaviour support plan approval authority, subject to current source access and policy', 'group' => 'restraints'],
        ];
        foreach ($permissions as $key => $details) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, ...$details, 'module' => 'Compliance', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Configuration authority never supplies specialist approval or source access.
        $permissionId = DB::table('permissions')->where('key', 'healthSafety.settings.manage')->value('id');
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($permissionId !== null && $adminId !== null) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $permissionId]);
        }
        // Approval is individually designated. Do not infer a specialist grant
        // from administrator, clinical-lead, reviewer or H&S-manager role names.
    }

    public function down(): void
    {
        // Retain live access decisions on code rollback. Removal requires a
        // separately reviewed grant migration, as with incident ownership.
    }
};
