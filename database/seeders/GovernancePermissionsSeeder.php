<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class GovernancePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['key' => 'governance.view', 'description' => 'View Governance Dashboard'],

            // Meetings
            ['key' => 'governance.meetings.view', 'description' => 'View Board Meetings'],
            ['key' => 'governance.meetings.manage', 'description' => 'Manage Board Meetings'],

            // Resolutions
            ['key' => 'governance.resolutions.view', 'description' => 'View Resolutions'],
            ['key' => 'governance.resolutions.vote', 'description' => 'Vote on Resolutions'],
            ['key' => 'governance.resolutions.manage', 'description' => 'Manage Resolutions'],

            // Risks
            ['key' => 'governance.risks.view', 'description' => 'View Risk Register'],
            ['key' => 'governance.risks.manage', 'description' => 'Manage Risk Register'],

            // Compliance
            ['key' => 'governance.compliance.view', 'description' => 'View Compliance'],
            ['key' => 'governance.compliance.manage', 'description' => 'Manage Compliance'],

            // Performance
            ['key' => 'governance.performance.view', 'description' => 'View Performance Reviews'],
            ['key' => 'governance.performance.manage', 'description' => 'Manage Performance Reviews'],

            // Strategy
            ['key' => 'governance.strategy.view', 'description' => 'View Strategic Plans'],
            ['key' => 'governance.strategy.manage', 'description' => 'Manage Strategic Plans'],

            // Budgets
            ['key' => 'governance.budgets.view', 'description' => 'View Budgets'],
            ['key' => 'governance.budgets.create', 'description' => 'Create & Edit Budgets'],
            ['key' => 'governance.budgets.submit', 'description' => 'Submit Budgets for Board Approval'],
            ['key' => 'governance.budgets.approve', 'description' => 'Approve Budgets on behalf of the Board'],

            // Board Packs
            ['key' => 'governance.packs.view', 'description' => 'View Board Packs'],
            ['key' => 'governance.packs.manage', 'description' => 'Manage Board Packs'],

            // Action Items
            ['key' => 'governance.actions.view', 'description' => 'View Action Items'],
            ['key' => 'governance.actions.manage', 'description' => 'Manage Action Items'],

            // Governance Policies
            ['key' => 'governance.policies.view', 'description' => 'View Governance Policies'],
            ['key' => 'governance.policies.manage', 'description' => 'Manage Governance Policies'],

            // CEO Board Reports
            ['key' => 'governance.ceo-reports.view', 'description' => 'View CEO Board Reports'],
            ['key' => 'governance.ceo-reports.manage', 'description' => 'Manage CEO Board Reports'],

            // Board Interests Register
            ['key' => 'governance.interests.view', 'description' => 'View Board Interests Register'],
            ['key' => 'governance.interests.manage', 'description' => 'Manage Board Interests Register'],

            // Board Evaluations
            ['key' => 'governance.evaluations.view', 'description' => 'View Board Evaluations'],
            ['key' => 'governance.evaluations.manage', 'description' => 'Manage Board Evaluations'],

            // Governance Documents
            ['key' => 'governance.documents.view', 'description' => 'View Governance Documents'],
            ['key' => 'governance.documents.manage', 'description' => 'Manage Governance Documents'],

            // Clinical Governance
            ['key' => 'governance.clinical.view', 'description' => 'View Clinical Governance'],
            ['key' => 'governance.clinical.manage', 'description' => 'Manage Clinical Governance'],

            // Te Tiriti o Waitangi
            ['key' => 'governance.te-tiriti.view', 'description' => 'View Te Tiriti Framework'],
            ['key' => 'governance.te-tiriti.manage', 'description' => 'Manage Te Tiriti Framework'],

            // Evidence Library
            ['key' => 'governance.evidence.view', 'description' => 'View Evidence Library'],
            ['key' => 'governance.evidence.manage', 'description' => 'Manage Evidence Library'],

            // Audit Log
            ['key' => 'governance.audit.view', 'description' => 'View Governance Audit Log'],

            // Spend Approvals
            ['key' => 'governance.spend.view', 'description' => 'View Spend Approvals'],
            ['key' => 'governance.spend.request', 'description' => 'Request Spend Approval'],
            ['key' => 'governance.spend.approve', 'description' => 'Approve Spend Requests'],
            ['key' => 'governance.spend.manageAny', 'description' => 'Manage Any Spend Approval Draft'],
            ['key' => 'governance.spend.viewAllSites', 'description' => 'Access Spend Approvals Across All Sites'],

            // Settings (governance configuration)
            ['key' => 'governance.settings.view', 'description' => 'View Governance Settings'],
            ['key' => 'governance.settings.manage', 'description' => 'Manage Governance Settings'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                ['description' => $perm['description']]
            );
        }

        // Assign to admin role
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            foreach ($permissions as $perm) {
                $permission = Permission::where('key', $perm['key'])->first();
                if ($permission && ! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
                    $adminRole->permissions()->attach($permission->id);
                }
            }
        }

        // Assign governance view to board roles
        $boardRoles = Role::whereIn('name', ['board_chair', 'board_secretary', 'board_member', 'board_observer'])->get();
        $viewPerm = Permission::where('key', 'governance.view')->first();

        if ($viewPerm) {
            foreach ($boardRoles as $role) {
                if (! $role->permissions()->where('permissions.id', $viewPerm->id)->exists()) {
                    $role->permissions()->attach($viewPerm->id);
                }
            }
        }

        // Assign full governance permissions to board_chair
        $boardChair = Role::where('name', 'board_chair')->first();
        if ($boardChair) {
            $allGovernancePerms = Permission::where('key', 'like', 'governance.%')->get();
            foreach ($allGovernancePerms as $perm) {
                if (! $boardChair->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $boardChair->permissions()->attach($perm->id);
                }
            }
        }

        // Assign meeting management permissions to board_secretary
        $boardSecretary = Role::where('name', 'board_secretary')->first();
        if ($boardSecretary) {
            $secretaryPerms = [
                'governance.view',
                'governance.meetings.view',
                'governance.meetings.manage',
                'governance.packs.view',
                'governance.packs.manage',
                'governance.resolutions.view',
                'governance.resolutions.manage',
                'governance.actions.view',
                'governance.actions.manage',
                'governance.policies.view',
                'governance.policies.manage',
                'governance.ceo-reports.view',
                'governance.interests.view',
                'governance.interests.manage',
                'governance.evaluations.view',
                'governance.evaluations.manage',
                'governance.documents.view',
                'governance.documents.manage',
                'governance.budgets.view',
                'governance.budgets.create',
                'governance.budgets.submit',
                'governance.audit.view',
                'governance.spend.view',
                'governance.spend.request',
                'governance.settings.view',
                'governance.settings.manage',
            ];
            foreach ($secretaryPerms as $key) {
                $perm = Permission::where('key', $key)->first();
                if ($perm && ! $boardSecretary->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $boardSecretary->permissions()->attach($perm->id);
                }
            }
        }

        // Assign view + vote permissions to board_member
        $boardMember = Role::where('name', 'board_member')->first();
        if ($boardMember) {
            $memberPerms = [
                'governance.view',
                'governance.meetings.view',
                'governance.resolutions.view',
                'governance.resolutions.vote',
                'governance.risks.view',
                'governance.compliance.view',
                'governance.performance.view',
                'governance.strategy.view',
                'governance.budgets.view',
                'governance.budgets.approve',
                'governance.packs.view',
                'governance.actions.view',
                'governance.policies.view',
                'governance.ceo-reports.view',
                'governance.interests.view',
                'governance.interests.manage',
                'governance.evaluations.view',
                'governance.documents.view',
                'governance.clinical.view',
                'governance.te-tiriti.view',
                'governance.evidence.view',
                'governance.audit.view',
                'governance.spend.view',
            ];
            foreach ($memberPerms as $key) {
                $perm = Permission::where('key', $key)->first();
                if ($perm && ! $boardMember->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $boardMember->permissions()->attach($perm->id);
                }
            }
        }

        // Assign view-only permissions to board_observer
        $boardObserver = Role::where('name', 'board_observer')->first();
        if ($boardObserver) {
            $observerPerms = [
                'governance.view',
                'governance.meetings.view',
                'governance.resolutions.view',
                'governance.risks.view',
                'governance.compliance.view',
                'governance.performance.view',
                'governance.strategy.view',
                'governance.budgets.view',
                'governance.packs.view',
                'governance.actions.view',
                'governance.policies.view',
                'governance.ceo-reports.view',
                'governance.interests.view',
                'governance.documents.view',
                'governance.clinical.view',
                'governance.te-tiriti.view',
                'governance.spend.view',
            ];
            foreach ($observerPerms as $key) {
                $perm = Permission::where('key', $key)->first();
                if ($perm && ! $boardObserver->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $boardObserver->permissions()->attach($perm->id);
                }
            }
        }

        $this->command->info('Governance permissions seeded successfully!');
    }
}
