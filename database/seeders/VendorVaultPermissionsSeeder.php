<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Sites\VendorCommercialAccess;
use Illuminate\Database\Seeder;

class VendorVaultPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Explicit opt-in deployment step; migration never grants live access.
        foreach (['view' => 'Read restricted vendor agreements and files', 'manage' => 'Maintain restricted vendor agreements and files'] as $action => $description) {
            $permission = Permission::firstOrCreate(['key' => 'vendors.contracts.'.$action], ['description' => $description, 'group' => 'vendors', 'module' => 'Finance']);
            foreach (Role::whereIn('name', VendorCommercialAccess::ROLES)->get() as $role) $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        Permission::firstOrCreate(['key' => 'credentials.copy'], ['description' => 'Copy shared credential values after step-up', 'group' => 'credentials', 'module' => 'Operations']);
        // Copy is independent; do not backfill it from reveal permission.
    }
}
