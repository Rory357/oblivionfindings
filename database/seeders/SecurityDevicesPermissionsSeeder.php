<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds Security & Devices permission keys and attaches them to roles.
 *
 * Permission keys align exactly with DevicePolicy method names:
 *   DevicePolicy::viewAny()     → securityDevices.viewAny
 *   DevicePolicy::view()        → securityDevices.devices.view
 *   DevicePolicy::create()      → securityDevices.devices.create
 *   etc.
 *
 * Idempotent: safe to run multiple times (firstOrCreate + syncWithoutDetaching).
 */
class SecurityDevicesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'securityDevices.viewAny', 'description' => 'Access the Security & Devices module', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.view', 'description' => 'View device inventory', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.viewAllSites', 'description' => 'View devices across all operational Sites', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.viewUnassigned', 'description' => 'View unassigned device stock', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.create', 'description' => 'Register new devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.update', 'description' => 'Edit device records', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.delete', 'description' => 'Decommission or delete devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.assign', 'description' => 'Assign, reassign, or release devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.groups.manage', 'description' => 'Manage device groups', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.events.view', 'description' => 'View device events and alerts', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.cctv.media.view', 'description' => 'Open authorised CCTV media links', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.accessControl.view', 'description' => 'View physical access credentials, schedules, and history', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.accessControl.manage', 'description' => 'Issue and revoke physical access credentials and manage schedules', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.maintenance.view', 'description' => 'View device maintenance records', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.maintenance.manage', 'description' => 'Create and manage device maintenance', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.integrations.view', 'description' => 'View integration status and synced devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.integrations.manage', 'description' => 'Manage device sync and discovery', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.reports.view', 'description' => 'View hardware and compliance reports', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.observe', 'description' => 'View authorised device command history', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.operate', 'description' => 'Run approved low-risk device diagnostics and actions', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.manage', 'description' => 'Run standard state-changing device management', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.control', 'description' => 'Request safety, security, privacy, or availability-affecting controls', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.approve', 'description' => 'Independently approve or reject governed device commands', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.admin', 'description' => 'Administer command policy, adapters, and secret references', 'group' => 'security_devices', 'module' => 'Security & Devices'],
        ];

        // ── 1. Create permission records ──────────────────────────

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                [
                    'description' => $perm['description'],
                    'group' => $perm['group'],
                    'module' => $perm['module'] ?? null,
                ],
            );
        }

        $allPermIds = Permission::where('key', 'like', 'securityDevices.%')->pluck('id');

        // ── 2. Admin gets everything (already via sync in RbacSeeder,
        //       but also attach here for standalone seeder use) ─────

        $admin = Role::where('name', 'admin')->first();
        $admin?->permissions()->syncWithoutDetaching($allPermIds);

        // ── 3. Role assignments ───────────────────────────────────

        $this->attachToRole('it_manager', $allPermIds->toArray());

        $this->attachToRole('facilities_manager', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.viewUnassigned',
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.devices.assign',
            'securityDevices.accessControl.view',
            'securityDevices.accessControl.manage',
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
            'securityDevices.integrations.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
        ]);

        $this->attachToRole('provider_manager', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.assign',
            'securityDevices.events.view',
            'securityDevices.accessControl.view',
            'securityDevices.maintenance.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
        ]);

        $this->attachToRole('fleet_manager', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.viewUnassigned',
            'securityDevices.devices.assign',
            'securityDevices.events.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
        ]);

        $this->attachToRole('coordinator', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.commands.observe',
        ]);

        $this->attachToRole('maintenance_coordinator', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
        ]);

        $this->attachToRole('health_safety_officer', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.events.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
        ]);

        $this->attachToRole('team_lead', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
        ]);

        $this->attachToRole('support_worker', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
        ]);

        $this->attachToRole('auditor', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.events.view',
            'securityDevices.accessControl.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
        ]);
    }

    /**
     * Attach permissions to a role by key names (idempotent).
     */
    private function attachToRole(string $roleName, array $permissionKeysOrIds): void
    {
        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return;
        }

        // Accept either an array of permission IDs (ints) or key strings.
        if (! empty($permissionKeysOrIds) && is_string($permissionKeysOrIds[0])) {
            $ids = Permission::whereIn('key', $permissionKeysOrIds)->pluck('id')->toArray();
        } else {
            $ids = $permissionKeysOrIds;
        }

        $role->permissions()->syncWithoutDetaching($ids);
    }
}
