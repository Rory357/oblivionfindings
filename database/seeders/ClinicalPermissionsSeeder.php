<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class ClinicalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Clinical permissions
        |--------------------------------------------------------------------------
        */
        $permissionDefinitions = [
            // Observations
            ['key' => 'clinical.observations.viewAny', 'description' => 'View all clinical observations', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.viewAssigned', 'description' => 'View observations for assigned clients', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.record', 'description' => 'Record clinical observations (basic types)', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.recordClinical', 'description' => 'Record clinical observations (vitals, pain)', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.correct', 'description' => 'Submit observation corrections', 'group' => 'clinical', 'module' => 'Health & Clinical'],

            // Events
            ['key' => 'clinical.events.viewAny', 'description' => 'View all clinical events', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.viewAssigned', 'description' => 'View clinical events for assigned clients', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.record', 'description' => 'Record clinical events', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.review', 'description' => 'Review and close clinical events', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.escalate', 'description' => 'Escalate clinical events to on-call clinical leadership', 'group' => 'clinical', 'module' => 'Health & Clinical'],

            // Protocols
            ['key' => 'clinical.protocols.viewAny', 'description' => 'View clinical protocols', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.protocols.manage', 'description' => 'Create, edit, and deactivate clinical protocols', 'group' => 'clinical', 'module' => 'Health & Clinical'],

            // Module access
            ['key' => 'clinical.dashboard', 'description' => 'Access the Health & Clinical dashboard', 'group' => 'clinical', 'module' => 'Health & Clinical'],

            // Medication verification and administration-rule governance
            ['key' => 'medications.orders.verify', 'description' => 'Verify medication orders before administration', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.settings.manage', 'description' => 'Manage facility medication administration rules', 'group' => 'medications', 'module' => 'Clinical'],
        ];

        $allPermissions = [];

        foreach ($permissionDefinitions as $def) {
            $allPermissions[] = Permission::firstOrCreate(
                ['key' => $def['key']],
                [
                    'description' => $def['description'],
                    'group' => $def['group'],
                    'module' => $def['module'],
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Assign to roles
        |--------------------------------------------------------------------------
        */
        $syncPermissions = function ($role, $keys) {
            if (! $role) {
                return;
            }
            $ids = Permission::whereIn('key', $keys)->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        };

        // Support Worker: basic observation recording + event reporting
        $syncPermissions(Role::where('name', 'support_worker')->first(), [
            'clinical.observations.viewAssigned',
            'clinical.observations.record',
            'clinical.events.viewAssigned',
            'clinical.events.record',
        ]);

        // Team Lead: site-level view + basic recording
        $syncPermissions(Role::where('name', 'team_lead')->first(), [
            'clinical.observations.viewAny',
            'clinical.observations.record',
            'clinical.observations.recordClinical',
            'clinical.events.viewAny',
            'clinical.events.record',
            'clinical.protocols.viewAny',
            'clinical.dashboard',
            'medications.orders.verify',
        ]);

        // Clinical Lead: full clinical access
        $syncPermissions(Role::where('name', 'clinical_lead')->first(), [
            'clinical.observations.viewAny',
            'clinical.observations.record',
            'clinical.observations.recordClinical',
            'clinical.observations.correct',
            'clinical.events.viewAny',
            'clinical.events.record',
            'clinical.events.review',
            'clinical.events.escalate',
            'clinical.protocols.viewAny',
            'clinical.protocols.manage',
            'clinical.dashboard',
            'medications.orders.verify',
            'medications.settings.manage',
        ]);

        // Coordinator: same as clinical lead
        $syncPermissions(Role::where('name', 'coordinator')->first(), [
            'clinical.observations.viewAny',
            'clinical.observations.record',
            'clinical.observations.recordClinical',
            'clinical.observations.correct',
            'clinical.events.viewAny',
            'clinical.events.record',
            'clinical.events.review',
            'clinical.events.escalate',
            'clinical.protocols.viewAny',
            'clinical.protocols.manage',
            'clinical.dashboard',
            'medications.orders.verify',
            'medications.settings.manage',
        ]);

        // Provider Manager: full access
        $syncPermissions(Role::where('name', 'provider_manager')->first(), [
            'clinical.observations.viewAny',
            'clinical.observations.record',
            'clinical.observations.recordClinical',
            'clinical.observations.correct',
            'clinical.events.viewAny',
            'clinical.events.record',
            'clinical.events.review',
            'clinical.events.escalate',
            'clinical.protocols.viewAny',
            'clinical.protocols.manage',
            'clinical.dashboard',
            'medications.orders.verify',
            'medications.settings.manage',
        ]);
    }
}
