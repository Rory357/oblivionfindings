<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Make application-wide Financial Insights visibility an explicit capability.
 *
 * Existing finance and auditor roles previously received this effective access
 * incidentally through reports.viewAny. Preserve their intended read coverage
 * while severing that unrelated permission coupling. Admin remains explicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'finance.insights.viewAllSites'],
            [
                'description' => 'View Financial Insights across all active Sites',
                'group' => 'finance',
                'module' => 'Finance',
            ],
        );

        Role::query()
            ->whereIn('name', ['admin', 'finance', 'auditor'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Deliberately non-destructive. Revoking application-wide financial
        // visibility is an explicit access-control decision, not rollback glue.
    }
};
