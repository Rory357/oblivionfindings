<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Restraints & Behaviour Support redesign — dedicated permission scheme.
 *
 * The register previously gated entirely on `hazards.*`. This introduces a
 * dedicated `restraints.{view,create,manage,review}` scheme (route + controller +
 * sidebar are reconciled to it in the same redesign).
 *
 * To avoid regressing access on an existing deployment (where seeders are NOT
 * re-run on deploy), this migration creates the permissions and grants them to
 * every role that currently holds the equivalent hazards permission:
 *
 *   hazards.view   → restraints.view
 *   hazards.create → restraints.view, restraints.create
 *   hazards.manage → restraints.view, restraints.create, restraints.manage, restraints.review
 *
 * Idempotent via firstOrCreate + syncWithoutDetaching. RbacSeeder carries the
 * same grants for fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defs = [
            ['key' => 'restraints.view', 'description' => 'View the restraint register & behaviour support plans', 'group' => 'restraints', 'module' => 'Compliance'],
            ['key' => 'restraints.create', 'description' => 'Record restraint events & create behaviour support plans', 'group' => 'restraints', 'module' => 'Compliance'],
            ['key' => 'restraints.manage', 'description' => 'Manage restraint events & behaviour support plan lifecycle', 'group' => 'restraints', 'module' => 'Compliance'],
            ['key' => 'restraints.review', 'description' => 'Review restraint events & sign off plan reviews', 'group' => 'restraints', 'module' => 'Compliance'],
        ];

        foreach ($defs as $d) {
            Permission::firstOrCreate(
                ['key' => $d['key']],
                ['description' => $d['description'], 'group' => $d['group'], 'module' => $d['module']],
            );
        }

        $map = [
            'hazards.view' => ['restraints.view'],
            'hazards.create' => ['restraints.view', 'restraints.create'],
            'hazards.manage' => ['restraints.view', 'restraints.create', 'restraints.manage', 'restraints.review'],
        ];

        foreach ($map as $hazardKey => $grantKeys) {
            $hazard = Permission::where('key', $hazardKey)->first();
            if (! $hazard) {
                continue;
            }

            $grantIds = Permission::whereIn('key', $grantKeys)->pluck('id')->all();

            foreach ($hazard->roles()->pluck('roles.id')->all() as $roleId) {
                Role::find($roleId)?->permissions()->syncWithoutDetaching($grantIds);
            }
        }

        // Belt-and-braces: the system admin role holds every permission.
        Role::where('name', 'admin')->first()
            ?->permissions()->syncWithoutDetaching(
                Permission::whereIn('key', array_column($defs, 'key'))->pluck('id')->all(),
            );
    }

    public function down(): void
    {
        // Deliberately non-destructive: revoking mid-release would risk locking
        // operators out. The permissions themselves are harmless if left in place.
    }
};
