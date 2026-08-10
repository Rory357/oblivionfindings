<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'assets.telemetry.export';

    private const ROLE_GRANTS = ['admin', 'provider_manager', 'coordinator'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore([
                'key' => self::PERMISSION,
                'description' => 'Export authorised personal location telemetry with a recorded purpose',
                'group' => 'assets',
                'module' => 'Resources',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                return;
            }

            DB::table('roles')
                ->whereIn('name', self::ROLE_GRANTS)
                ->pluck('id')
                ->each(function ($roleId) use ($permissionId): void {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                });
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                return;
            }

            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            }

            DB::table('permissions')->where('id', $permissionId)->delete();
        });
    }
};
