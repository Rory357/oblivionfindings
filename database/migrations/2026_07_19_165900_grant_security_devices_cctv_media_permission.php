<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['key' => 'securityDevices.cctv.media.view'],
            [
                'description' => 'Open authorised CCTV media links',
                'group' => 'security_devices',
                'module' => 'Security & Devices',
            ],
        );

        Role::query()
            ->whereIn('name', ['admin', 'it_manager'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Deliberately non-destructive: revoking media access mid-release could
        // strand existing role assignments. Permission removal is a governed
        // administrative decision, not a rollback side effect.
    }
};
