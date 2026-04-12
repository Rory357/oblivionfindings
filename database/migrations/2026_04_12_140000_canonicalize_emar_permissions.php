<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $canonicalPermissions = [
            'medications.view' => [
                'description' => 'View medications / eMAR module',
                'group' => 'medications',
                'module' => 'Clinical',
            ],
            'medications.breakglass' => [
                'description' => 'Use break-glass emergency access',
                'group' => 'medications',
                'module' => 'Clinical',
            ],
        ];

        foreach ($canonicalPermissions as $key => $attributes) {
            $permission = DB::table('permissions')->where('key', $key)->first();

            if (! $permission) {
                DB::table('permissions')->insert(array_merge(
                    ['key' => $key],
                    $attributes,
                    ['created_at' => now(), 'updated_at' => now()],
                ));
                continue;
            }

            DB::table('permissions')
                ->where('id', $permission->id)
                ->update(array_filter([
                    'description' => $permission->description ?: $attributes['description'],
                    'group' => $permission->group ?? $attributes['group'],
                    'module' => $permission->module ?? $attributes['module'],
                    'updated_at' => now(),
                ]));
        }

        $this->mergePermissionInto('emar.viewAny', 'medications.view');
        $this->mergePermissionInto('emar.dashboard.view', 'medications.view');
        $this->mergePermissionInto('medications.breakGlass', 'medications.breakglass');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $legacyPermissions = [
            'emar.viewAny' => 'View eMAR Module',
            'emar.dashboard.view' => 'View eMAR Dashboard',
            'medications.breakGlass' => 'Use break-glass emergency access',
        ];

        foreach ($legacyPermissions as $key => $description) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'key' => $key,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function mergePermissionInto(string $legacyKey, string $canonicalKey): void
    {
        $legacy = DB::table('permissions')->where('key', $legacyKey)->first();
        $canonical = DB::table('permissions')->where('key', $canonicalKey)->first();

        if (! $legacy || ! $canonical) {
            return;
        }

        if (Schema::hasTable('role_permission')) {
            $roleIds = DB::table('role_permission')
                ->where('permission_id', $legacy->id)
                ->pluck('role_id')
                ->unique()
                ->values();

            foreach ($roleIds as $roleId) {
                $exists = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $canonical->id)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $canonical->id,
                    ]);
                }
            }

            DB::table('role_permission')
                ->where('permission_id', $legacy->id)
                ->delete();
        }

        DB::table('permissions')
            ->where('id', $legacy->id)
            ->delete();
    }
};
