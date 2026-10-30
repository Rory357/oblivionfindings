<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'medications.breakglass.end'], [
            'description' => 'End another person’s medication emergency access',
            'group' => 'medications', 'module' => 'Clinical',
        ]);
        Role::whereIn('name', ['admin', 'provider_manager', 'coordinator', 'clinical_lead'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // A permission may have since been deliberately assigned; never remove those grants on rollback.
    }
};
