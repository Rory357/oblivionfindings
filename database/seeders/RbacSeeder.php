<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Roles
        |--------------------------------------------------------------------------
        */
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator']
        );

        $providerManager = Role::firstOrCreate(
            ['name' => 'provider_manager'],
            ['label' => 'Provider Manager']
        );

        $coordinator = Role::firstOrCreate(
            ['name' => 'coordinator'],
            ['label' => 'Coordinator']
        );

        $supportWorker = Role::firstOrCreate(
            ['name' => 'support_worker'],
            ['label' => 'Support Worker']
        );

        $finance = Role::firstOrCreate(
            ['name' => 'finance'],
            ['label' => 'Finance']
        );

        $hr = Role::firstOrCreate(
            ['name' => 'hr'],
            ['label' => 'HR']
        );

        $auditor = Role::firstOrCreate(
            ['name' => 'auditor'],
            ['label' => 'Auditor (Read only)']
        );

        $clientRole = Role::firstOrCreate(
            ['name' => 'client'],
            ['label' => 'Client (Portal)']
        );

        $nextOfKinRole = Role::firstOrCreate(
            ['name' => 'next_of_kin'],
            ['label' => 'Next of Kin / Guardian (Portal)']
        );

        // Remove any roles we are not using right now (but only if they are not assigned).
        // This keeps the Access Control UI role list clean.
        $activeRoleNames = [
            'admin',
            'provider_manager',
            'coordinator',
            'support_worker',
            'finance',
            'hr',
            'next_of_kin',
            'client',
            'auditor',
        ];
        Role::query()
            ->whereNotIn('name', $activeRoleNames)
            ->doesntHave('users')
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 2. Permissions
        |--------------------------------------------------------------------------
        */
        $permissions = [
            // Sites
            ['key' => 'sites.viewAny', 'description' => 'View sites'],
            ['key' => 'sites.create', 'description' => 'Create sites'],
            ['key' => 'sites.update', 'description' => 'Update sites'],

            // Staff / workers
            ['key' => 'staff.viewAny', 'description' => 'View staff'],
            ['key' => 'staff.create', 'description' => 'Create staff'],
            ['key' => 'staff.update', 'description' => 'Update staff'],
            ['key' => 'staff.invite', 'description' => 'Invite staff'],
            ['key' => 'staff.assignments.update', 'description' => 'Assign clients to staff'],
            ['key' => 'staff.credentials.viewAny', 'description' => 'View staff credentials'],
            ['key' => 'staff.credentials.updateAny', 'description' => 'Manage staff credentials'],
            ['key' => 'staff.credentials.updateSelf', 'description' => 'Manage own credentials'],
            ['key' => 'staff.availability.updateAny', 'description' => 'Manage staff availability'],
            ['key' => 'staff.availability.updateSelf', 'description' => 'Manage own availability'],

            // Workers / modules
            ['key' => 'workers.viewAny', 'description' => 'View workers'],
            ['key' => 'reports.viewAny', 'description' => 'View reports'],
            ['key' => 'rostering.viewAny', 'description' => 'View rostering'],
            ['key' => 'fleet.viewAny', 'description' => 'View fleet management'],
            ['key' => 'calendar.viewAny', 'description' => 'View calendar'],
            // Shifts (appointments)
            ['key' => 'shifts.viewAny', 'description' => 'View shifts'],
            ['key' => 'shifts.viewAssigned', 'description' => 'View assigned shifts only'],
            ['key' => 'shifts.create', 'description' => 'Create shifts'],
            ['key' => 'shifts.update', 'description' => 'Update shifts'],
            ['key' => 'shifts.manageAny', 'description' => 'Manage any staff shifts'],

            // Timesheets
            ['key' => 'timesheets.viewAny', 'description' => 'View timesheets'],
            ['key' => 'timesheets.viewAssigned', 'description' => 'View assigned timesheets only'],
            ['key' => 'timesheets.create', 'description' => 'Create timesheets'],
            ['key' => 'timesheets.update', 'description' => 'Update timesheets'],
            ['key' => 'timesheets.approve', 'description' => 'Approve/reject timesheets'],
            ['key' => 'timesheets.manageAny', 'description' => 'Manage any staff timesheets'],

            // Clients
            ['key' => 'clients.viewAny', 'description' => 'View clients'],
            ['key' => 'clients.viewAssigned', 'description' => 'View assigned clients only'],
            ['key' => 'clients.create', 'description' => 'Create clients'],
            ['key' => 'clients.update', 'description' => 'Update clients'],
            ['key' => 'clients.assignments.update', 'description' => 'Manage client assignments'],

            // Audit logs
            ['key' => 'audit.viewAny', 'description' => 'View audit logs'],

            // Timeline / notes
            ['key' => 'timeline.viewAny', 'description' => 'View timelines (staff/client activity)'],
            ['key' => 'timeline.create', 'description' => 'Create timeline events (notes/incidents)'],

            // AI summaries
            ['key' => 'summaries.viewAny', 'description' => 'View AI summaries'],
            ['key' => 'summaries.generate', 'description' => 'Generate AI summaries'],

            // Integrations
            ['key' => 'unifi.manage', 'description' => 'Manage UniFi integration settings'],

            // Settings
            ['key' => 'settings.access.manage', 'description' => 'Manage user access (roles & overrides)'],
            ['key' => 'settings.terminology.manage', 'description' => 'Manage UI terminology (labels)'],
            ['key' => 'settings.branding.manage', 'description' => 'Manage organisation branding (colors, logo)'],

            // RAG / AI Query
            ['key' => 'rag.ask.any', 'description' => 'Ask AI about any client (within view permissions)'],
            ['key' => 'rag.ask.assigned', 'description' => 'Ask AI about assigned clients'],
            ['key' => 'rag.ask.self', 'description' => 'Ask AI about own / linked client (portal)'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                ['description' => $perm['description']]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Attach permissions to roles
        |--------------------------------------------------------------------------
        */

        // Admin gets EVERYTHING
        $admin->permissions()->sync(Permission::pluck('id'));

        // Provider Manager
        $providerManager->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'sites.create',
                'sites.update',

                'staff.viewAny',
                'staff.create',
                'staff.update',
                'staff.invite',
                'staff.assignments.update',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',

                'workers.viewAny',
                'reports.viewAny',
                'rostering.viewAny',
                'fleet.viewAny',
                'calendar.viewAny',

                'timeline.viewAny',
                'timeline.create',
                'summaries.viewAny',
                'summaries.generate',
                'unifi.manage',

                'shifts.viewAny',
                'shifts.create',
                'shifts.update',
                'shifts.manageAny',

                'timesheets.viewAny',
                'timesheets.create',
                'timesheets.update',
                'timesheets.approve',
                'timesheets.manageAny',

                'clients.viewAny',
                'clients.create',
                'clients.update',
                'clients.assignments.update',

                // Settings (adjust to taste)
                'settings.terminology.manage',

                // Audit
                'audit.viewAny',

                // RAG
                'rag.ask.any',
            ])->pluck('id')
        );

        // Coordinator (global view, limited settings)
        $coordinator->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'staff.viewAny',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',
                'clients.viewAny',
                'clients.assignments.update',
                'shifts.viewAny',
                'shifts.create',
                'shifts.update',
                'timesheets.viewAny',
                'timesheets.approve',
                'timeline.viewAny',
                'timeline.create',
                'summaries.viewAny',
                'summaries.generate',
                'calendar.viewAny',
                'rag.ask.any',
            ])->pluck('id')
        );

        // Support Worker
        $supportWorker->permissions()->sync(
            Permission::whereIn('key', [
                'clients.viewAssigned',
                'timeline.create',
                'shifts.viewAssigned',
                'timesheets.viewAssigned',
                'timesheets.create',
                'timesheets.update',

                'staff.credentials.updateSelf',
                'staff.availability.updateSelf',

                'timeline.create',

                // RAG
                'rag.ask.assigned',
            ])->pluck('id')
        );

        // Finance (timesheets + reports)
        $finance->permissions()->sync(
            Permission::whereIn('key', [
                'timesheets.viewAny',
                'timesheets.approve',
                'reports.viewAny',
                'audit.viewAny',
            ])->pluck('id')
        );

        // HR (staff + compliance)
        $hr->permissions()->sync(
            Permission::whereIn('key', [
                'staff.viewAny',
                'staff.update',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',
                'reports.viewAny',
                'audit.viewAny',
            ])->pluck('id')
        );

        // Auditor (read-only, audit + reporting + view)
        $auditor->permissions()->sync(
            Permission::whereIn('key', [
                'clients.viewAny',
                'shifts.viewAny',
                'timesheets.viewAny',
                'reports.viewAny',
                'timeline.viewAny',
                'summaries.viewAny',
                'audit.viewAny',
            ])->pluck('id')
        );

        // Client / Next-of-kin portal users
        // (Most access control is enforced via ClientPolicy + client_portal_users links.)
        $clientRole->permissions()->sync(
            Permission::whereIn('key', [
                'rag.ask.self',
            ])->pluck('id')
        );

        $nextOfKinRole->permissions()->sync(
            Permission::whereIn('key', [
                'rag.ask.self',
            ])->pluck('id')
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Migrate existing users.role → RBAC roles
        |--------------------------------------------------------------------------
        | Keeps your current system working while you transition UI & routes.
        */
        User::query()
            ->select('id', 'role')
            ->chunk(200, function ($users) use ($admin, $providerManager, $coordinator, $supportWorker, $finance, $hr, $auditor) {
                foreach ($users as $user) {
                    $roleName = $user->role ?? 'support_worker';

                    $role = match ($roleName) {
                        'admin' => $admin,
                        'provider_manager' => $providerManager,
                        'coordinator' => $coordinator,
                        'finance' => $finance,
                        'hr' => $hr,
                        'auditor' => $auditor,
                        default => $supportWorker,
                    };

                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            });
    }
}
