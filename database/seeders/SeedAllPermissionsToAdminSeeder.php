<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SeedAllPermissionsToAdminSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();

        if (!$adminRole) {
            $this->command->warn('No admin role found.');
            return;
        }

        $allPermissionIds = Permission::pluck('id')->all();
        $existing = $adminRole->permissions()->pluck('permissions.id')->all();
        $toAttach = array_diff($allPermissionIds, $existing);

        if (!empty($toAttach)) {
            $adminRole->permissions()->attach($toAttach);
        }

        $this->command->info('Attached ' . count($toAttach) . ' permissions to admin role. Total: ' . count($allPermissionIds));
    }
}
