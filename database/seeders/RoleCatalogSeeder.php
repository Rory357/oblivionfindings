<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Role;

/**
 * Optional: seeds a catalogue of organisation job-title roles.
 *
 * - name: slug (stable identifier)
 * - label: human-readable title (what you show in the UI)
 */
class RoleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $roleLabels = [
            'Admin',
            'Admin Team Leader',
            'Auditor',
            'Business Manager',
            'Cafe Team Leader',
            'CE',
            'Chief Executive Officer',
            'Coach Team Leader',
            'Coordinator',
            'Environment Manager',
            'HR',
            'IT Manager',
            'Leadership Group',
            'Local Admin',
            'Maintenance Crew',
            'Manager of Services',
            'Occupational Therapist',
            'On Site Coord',
            'Onsite Service Manager',
            'Onsite Team Leader',
            'Operations Manager',
            'OT',
            'Outcome Leads',
            'Outcomes Manager',
            'Outcomes Manager 900',
            'Outcomes Manager ACC',
            'Recources Manager',
            'Scheduler',
            'Senior Support',
            'Sensitive Diary',
            'Sensitive Diary - Read',
            'Service Manager',
            'Support Planner',
            'Support Planner Admin',
            'Support Staff',
            'Systems Manager',
            'Wayfinder Lead',
            'Wharepoa Team Leader',
        ];

        foreach ($roleLabels as $label) {
            $name = Str::slug($label, '_');
            Role::firstOrCreate(
                ['name' => $name],
                ['label' => $label]
            );
        }
    }
}
