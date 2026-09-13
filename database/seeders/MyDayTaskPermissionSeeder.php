<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Add the narrow worker capability to existing installs without resyncing RBAC. */
class MyDayTaskPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'shifts.tasks.createSelf'], [
            'description' => 'Add support tasks to own current shifts', 'group' => 'shifts', 'module' => 'Operations',
        ]);
        Role::where('name', 'support_worker')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
