<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = Permission::query()
            ->whereIn('key', ['it.view', 'it.manage'])
            ->pluck('id');

        Role::query()
            ->where('name', 'it_manager')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        // Deliberately non-destructive. Removing operational access from an
        // IT Manager is an explicit security administration decision, not a
        // schema rollback side effect.
    }
};
