<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = collect([
            [
                'key' => 'it.organisationWide',
                'description' => 'Access explicitly organisation-wide IT work',
            ],
            [
                'key' => 'it.viewSensitive',
                'description' => 'Access sensitive IT work within an approved scope',
            ],
        ])->map(function (array $definition): int {
            return (int) Permission::query()->firstOrCreate(
                ['key' => $definition['key']],
                [
                    'description' => $definition['description'],
                    'group' => 'it',
                    'module' => 'Operations',
                ],
            )->getKey();
        })->all();

        Role::query()
            ->where('name', 'admin')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        // Deliberately non-destructive. Revoking restricted access is an
        // explicit security administration action, not a schema rollback.
    }
};
