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
            ['key' => 'securityDevices.devices.viewUnassigned', 'description' => 'View unassigned stock at authorised Sites; unknown or quarantined stock also requires all-Sites access', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.create', 'description' => 'Register new devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.update', 'description' => 'Edit device records', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.delete', 'description' => 'Decommission or delete devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.devices.assign', 'description' => 'Assign, reassign, or release devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.groups.manage', 'description' => 'Manage device groups', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.events.view', 'description' => 'View device events and alerts', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.cctv.recordingReferences.view', 'description' => 'Read authorised immutable CCTV recording references, without footage or preservation authority', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.cctv.recordingReferences.create', 'description' => 'Create bounded CCTV recording references separately from live viewing', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.cctv.media.view', 'description' => 'Open authorised CCTV media links', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.accessControl.view', 'description' => 'View physical access credentials, schedules, and history', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.accessControl.manage', 'description' => 'Manage provider-backed physical access credential requests and schedules', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.maintenance.view', 'description' => 'View device maintenance records', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.maintenance.manage', 'description' => 'Create and manage device maintenance', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.integrations.view', 'description' => 'View integration status and synced devices', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.integrations.manage', 'description' => 'Manage device sync and discovery', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.monitoring.manage', 'description' => 'Author monitoring profiles, coverage, dependencies, maintenance windows, and retention', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.reports.view', 'description' => 'View hardware and compliance reports', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.observe', 'description' => 'View authorised device command history', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.operate', 'description' => 'Run approved low-risk device diagnostics and actions', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.manage', 'description' => 'Run standard state-changing device management', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.control', 'description' => 'Request safety, security, privacy, or availability-affecting controls', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.commands.approve', 'description' => 'Independently approve or reject governed device commands', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.diagnostics', 'description' => 'Read authorised computer diagnostic snapshots', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.obli.assist', 'description' => 'Use Obli read-and-suggest investigation for authorised computers', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.remote.view', 'description' => 'Request viewing of an authorised computer with remote-support policy', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.remote.control', 'description' => 'Request mouse and keyboard control separately from viewing', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.remote.unattended', 'description' => 'Use remote support without attended consent on explicitly approved equipment', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.softwareUpload', 'description' => 'Upload immutable reviewed MSI releases', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.softwareReview', 'description' => 'Independently publish or withdraw reviewed MSI releases', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.softwarePolicyManage', 'description' => 'Configure governed machine software policy', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.softwareInstall', 'description' => 'Request approved native machine MSI installation', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.softwareUnattended', 'description' => 'Authorize unattended native machine MSI installation', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.restartPolicyManage', 'description' => 'Configure governed Windows restart policy', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.restartSchedule', 'description' => 'Request independently approved Windows restart schedules', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.restartUnattended', 'description' => 'Authorise unattended governed Windows restart schedules', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.manageActionPolicy', 'description' => 'Configure governed computer service action policy', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.serviceManage', 'description' => 'Request independently approved computer service actions', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.endpoints.serviceUnattended', 'description' => 'Authorise unattended governed computer service actions', 'group' => 'security_devices', 'module' => 'Security & Devices'],
            ['key' => 'securityDevices.remote.managePolicy', 'description' => 'Configure remote-support access and consent policy for approved computers', 'group' => 'security_devices', 'module' => 'Security & Devices'],
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

        // Historical reference grants are explicit; an IT role or live-view grant alone is insufficient.
        $this->attachToRole('it_manager', Permission::where('key', 'like', 'securityDevices.%')->whereNotIn('key', ['securityDevices.cctv.recordingReferences.view', 'securityDevices.cctv.recordingReferences.create'])->pluck('id')->toArray());

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
