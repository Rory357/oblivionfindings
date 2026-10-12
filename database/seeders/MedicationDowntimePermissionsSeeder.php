<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * eMAR Lane C1: medications.downtime.manage on fresh seeds, matching the
 * 2026_10_09_230200 grant migration (deploys). Runs after RbacSeeder and
 * only adds (syncWithoutDetaching), like the other module permission seeders.
 */
class MedicationDowntimePermissionsSeeder extends Seeder
{
    public const KEY = 'medications.downtime.manage';

    public const ROLES = ['admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead'];

    public function run(): void
    {
        $permission = Permission::query()->firstOrCreate(['key' => self::KEY], [
            'description' => 'Run medication downtimes: declare them, make the downtime pack, review and finish paper records',
            'group' => 'medications',
            'module' => 'Clinical',
        ]);
        Role::query()->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }
}
