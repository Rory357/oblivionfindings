<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\Sites\VendorCommercialAccess;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Deploys run migrations but never seeders, so the approved commercial
     * contract grants ship here (mirroring VendorVaultPermissionsSeeder).
     * credentials.copy is defined but deliberately granted to no role.
     */
    public function up(): void
    {
        $contractIds = collect([
            ['key' => 'vendors.contracts.view', 'description' => 'Read restricted vendor agreements and files'],
            ['key' => 'vendors.contracts.manage', 'description' => 'Maintain restricted vendor agreements and files'],
        ])->map(fn (array $definition): int => (int) Permission::query()->firstOrCreate(
            ['key' => $definition['key']],
            ['description' => $definition['description'], 'group' => 'vendors', 'module' => 'Finance'],
        )->getKey())->all();

        Role::query()->whereIn('name', VendorCommercialAccess::ROLES)
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($contractIds));

        Permission::query()->firstOrCreate(
            ['key' => 'credentials.copy'],
            ['description' => 'Copy shared credential values after step-up', 'group' => 'credentials', 'module' => 'Operations'],
        );
    }

    public function down(): void
    {
        // Keep explicit security grants intact during schema rollback. Any
        // revocation or operational role assignment uses the existing RBAC UI.
    }
};
