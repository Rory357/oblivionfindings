<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Readiness audit B4 (Stephan, 6 Nov 2026: "Remove it"): maintenance coordinators read hazards for their work but never
 * the restraint register or behaviour support plans. The seeder no longer copies the restraint keys onto them, and the
 * revoke migration takes them off a database that already has them.
 */
final class MaintenanceCoordinatorRestraintAccessTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['restraints.view', 'restraints.create', 'restraints.manage', 'restraints.review', 'restraints.approve'];

    public function test_the_seeded_role_keeps_hazards_but_has_no_restraint_or_plan_key(): void
    {
        $this->seed(RbacSeeder::class);
        $role = Role::where('name', 'maintenance_coordinator')->sole();
        $keys = $role->permissions()->pluck('key')->all();

        $this->assertContains('hazards.view', $keys);
        foreach (self::KEYS as $key) {
            $this->assertNotContains($key, $keys, "maintenance_coordinator must not hold {$key}.");
        }
        // Other hazards readers still get the mirrored restraint read.
        $this->assertContains('restraints.view', Role::where('name', 'health_safety_officer')->sole()->permissions()->pluck('key')->all());
    }

    public function test_a_maintenance_coordinator_cannot_open_the_restraint_register_or_a_plan(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role' => 'maintenance_coordinator', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'maintenance_coordinator')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        $this->actingAs($user)->get('/health-safety/restraints')->assertForbidden();
        $this->assertContains($this->actingAs($user)->get('/health-safety/restraints/plans/1')->status(), [403, 404]);
    }

    public function test_the_revoke_migration_removes_the_copied_keys_and_down_gives_back_only_the_read(): void
    {
        $this->seed(RbacSeeder::class);
        $role = Role::where('name', 'maintenance_coordinator')->sole();
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('key', [...self::KEYS, 'hazards.view'])->pluck('id')->all());

        $migration = require database_path('migrations/2026_11_06_100000_revoke_maintenance_coordinator_restraint_access.php');
        $migration->up();
        $keys = DB::table('role_permission')->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_id', $role->id)->pluck('permissions.key')->all();
        $this->assertContains('hazards.view', $keys);
        $this->assertSame([], array_values(array_intersect(self::KEYS, $keys)));

        $migration->down();
        $this->assertTrue($role->permissions()->where('key', 'restraints.view')->exists());
        $this->assertFalse($role->permissions()->where('key', 'restraints.manage')->exists());
    }
}
