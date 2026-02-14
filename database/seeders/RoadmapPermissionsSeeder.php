<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoadmapPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roadmapRoles = [
            ['name' => 'roadmap_manager', 'label' => 'Roadmap Manager'],
            ['name' => 'it_manager', 'label' => 'IT Manager'],
            ['name' => 'facilities_manager', 'label' => 'Facilities Manager'],
            ['name' => 'ceo', 'label' => 'CEO'],
            ['name' => 'cfo', 'label' => 'CFO'],
            ['name' => 'coo', 'label' => 'COO'],
            ['name' => 'compliance_lead', 'label' => 'Compliance Lead'],
            ['name' => 'risk_lead', 'label' => 'Risk Lead'],
        ];

        foreach ($roadmapRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name']],
                ['label' => $roleData['label']]
            );
        }

        $permissions = [
            ['key' => 'roadmap.view', 'description' => 'View roadmap module'],
            ['key' => 'roadmap.manage', 'description' => 'Create and manage initiatives, suggestions, and plans'],
            ['key' => 'roadmap.approve', 'description' => 'Approve and publish quarterly roadmap plans'],
            ['key' => 'roadmap.budget.manage', 'description' => 'Manage roadmap budget envelopes and replans'],
            ['key' => 'roadmap.decisions.view', 'description' => 'View roadmap decision requests'],
            ['key' => 'roadmap.decisions.manage', 'description' => 'Resolve roadmap decision requests'],
            ['key' => 'roadmap.reports.export', 'description' => 'Export roadmap snapshot reports'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['key' => $permission['key']],
                ['description' => $permission['description']]
            );
        }

        $rolePermissionMap = [
            'admin' => array_column($permissions, 'key'),
            'provider_manager' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.approve',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.decisions.manage',
                'roadmap.reports.export',
            ],
            'finance' => [
                'roadmap.view',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'maintenance_coordinator' => [
                'roadmap.view',
                'roadmap.manage',
            ],
            'roadmap_manager' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'it_manager' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'facilities_manager' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'board_chair' => [
                'roadmap.view',
                'roadmap.approve',
                'roadmap.decisions.view',
                'roadmap.decisions.manage',
                'roadmap.reports.export',
            ],
            'board_member' => [
                'roadmap.view',
                'roadmap.decisions.view',
            ],
            'board_observer' => [
                'roadmap.view',
            ],
            'auditor' => [
                'roadmap.view',
                'roadmap.reports.export',
            ],
            'ceo' => [
                'roadmap.view',
                'roadmap.approve',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.decisions.manage',
                'roadmap.reports.export',
            ],
            'cfo' => [
                'roadmap.view',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'coo' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.approve',
                'roadmap.budget.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'compliance_lead' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
            'risk_lead' => [
                'roadmap.view',
                'roadmap.manage',
                'roadmap.decisions.view',
                'roadmap.reports.export',
            ],
        ];

        foreach ($rolePermissionMap as $roleName => $permissionKeys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('key', $permissionKeys)
                ->pluck('id')
                ->all();

            if (! empty($permissionIds)) {
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }
        }

        $this->command->info('Roadmap permissions seeded successfully.');
    }
}
