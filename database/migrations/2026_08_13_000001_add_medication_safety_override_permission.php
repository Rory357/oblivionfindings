<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'medications.administer.override_safety'],
            [
                'description' => 'Authorise a blocked medication safety-check override',
                'group' => 'medications',
                'module' => 'Clinical',
            ],
        );

        Role::query()
            ->whereIn('name', ['admin', 'provider_manager', 'clinical_lead'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Deliberately non-destructive. Removing elevated medication authority
        // is an explicit access-control decision, not a schema rollback side effect.
    }
};
