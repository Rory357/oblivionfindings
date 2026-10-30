<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CompanyHubPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'view' => 'Read the Company Hub',
        'write' => 'Create and edit owned hub news and pages',
        'edit_all' => 'Edit and assign ownership of all permitted hub pages',
        'publish' => 'Approve, publish, schedule and archive permitted hub pages',
        'layout' => 'Customise the company homepage and navigation',
        'moderate' => 'Moderate hub comments and reactions',
        'celebrations' => 'Enable individual staff birthday celebrations',
    ];

    public function run(): void
    {
        $ids = [];
        foreach (self::PERMISSIONS as $key => $description) {
            $ids[$key] = Permission::firstOrCreate(['key' => 'company_hub.'.$key], [
                'description' => $description, 'group' => 'company_hub', 'module' => 'Company Hub',
            ])->id;
        }
        // Defaults initialise roles; every request still checks capabilities and explicit denials.
        foreach (Role::whereNotIn('name', ['client', 'next_of_kin', 'external_clinician'])->get() as $role) {
            $role->permissions()->syncWithoutDetaching(
                in_array($role->name, ['admin', 'hr'], true) ? array_values($ids) : [$ids['view']],
            );
        }
    }
}
