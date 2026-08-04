<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'securityDevices.commands.observe' => 'View authorised device command history',
        'securityDevices.commands.operate' => 'Run approved low-risk device diagnostics and actions',
        'securityDevices.commands.manage' => 'Run standard state-changing device management',
        'securityDevices.commands.control' => 'Request safety, security, privacy, or availability-affecting controls',
        'securityDevices.commands.approve' => 'Independently approve or reject governed device commands',
        'securityDevices.commands.admin' => 'Administer command policy, adapters, and secret references',
    ];

    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'admin' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
            'securityDevices.commands.manage', 'securityDevices.commands.control',
            'securityDevices.commands.approve', 'securityDevices.commands.admin',
        ],
        'it_manager' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
            'securityDevices.commands.manage', 'securityDevices.commands.control',
            'securityDevices.commands.approve', 'securityDevices.commands.admin',
        ],
        'facilities_manager' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
            'securityDevices.commands.manage',
        ],
        'maintenance_coordinator' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
            'securityDevices.commands.manage',
        ],
        'provider_manager' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
        ],
        'fleet_manager' => [
            'securityDevices.commands.observe', 'securityDevices.commands.operate',
        ],
        'coordinator' => ['securityDevices.commands.observe'],
        'health_safety_officer' => ['securityDevices.commands.observe'],
        'auditor' => ['securityDevices.commands.observe'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::PERMISSIONS as $key => $description) {
                DB::table('permissions')->insertOrIgnore([
                    'key' => $key,
                    'description' => $description,
                    'group' => 'security_devices',
                    'module' => 'Security & Devices',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('key', array_keys(self::PERMISSIONS))
                ->pluck('id', 'key');
            $roleIds = DB::table('roles')
                ->whereIn('name', array_keys(self::ROLE_GRANTS))
                ->pluck('id', 'name');

            foreach (self::ROLE_GRANTS as $role => $keys) {
                $roleId = $roleIds[$role] ?? null;
                if ($roleId === null) {
                    continue;
                }

                foreach ($keys as $key) {
                    $permissionId = $permissionIds[$key] ?? null;
                    if ($permissionId !== null) {
                        DB::table('role_permission')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $permissionIds = DB::table('permissions')
                ->whereIn('key', array_keys(self::PERMISSIONS))
                ->pluck('id');

            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        });
    }
};
