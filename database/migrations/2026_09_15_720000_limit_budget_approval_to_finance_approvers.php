<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner decision (15 September 2026): approving or declining budgets and
     * budget changes belongs to finance approvers, not every board member.
     * Deploys run migrations but never seeders, so the role change ships here
     * (mirroring GovernancePermissionsSeeder). The chair keeps it (all
     * governance permissions), as do admins; treasurers are granted it.
     */
    private const PERMISSION = 'governance.budgets.approve';

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $permission = Permission::query()->where('key', self::PERMISSION)->first();
        if ($permission === null) {
            return;
        }

        Role::query()->where('name', 'board_member')
            ->each(fn (Role $role) => $role->permissions()->detach($permission->getKey()));

        Role::query()->whereIn('name', ['board_treasurer', 'treasurer'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $permission = Permission::query()->where('key', self::PERMISSION)->first();
        if ($permission === null) {
            return;
        }

        Role::query()->where('name', 'board_member')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
    }
};
