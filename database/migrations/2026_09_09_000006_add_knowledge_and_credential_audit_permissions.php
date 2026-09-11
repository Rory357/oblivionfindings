<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = collect([
            ['key' => 'it.knowledge.author', 'description' => 'Author and submit scoped IT knowledge drafts', 'group' => 'it'],
            ['key' => 'it.knowledge.review', 'description' => 'Publish and retire scoped IT knowledge after review', 'group' => 'it'],
            ['key' => 'credentials.audit', 'description' => 'Review scoped credential activity without revealing secrets', 'group' => 'credentials'],
        ])->map(fn (array $definition): int => (int) Permission::query()->firstOrCreate(
            ['key' => $definition['key']],
            ['description' => $definition['description'], 'group' => $definition['group'], 'module' => 'Operations'],
        )->getKey())->all();

        Role::query()->where('name', 'admin')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        // Keep explicit security grants intact during schema rollback. Any
        // revocation or operational role assignment uses the existing RBAC UI.
    }
};
