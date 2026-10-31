<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Q4 (Stephan, 31 Oct): provider managers take on and act on H&S items at their sites, H&S items only, with no restraint
 * access. The seeder and the grant migration agree: hazards.manage, no restraint keys, no all-Sites key.
 */
class ProviderManagerHazardsManageGrantTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_31_220000_grant_provider_manager_hazards_manage.php';

    public function test_the_seeder_gives_provider_managers_hs_manage_and_no_restraint_keys(): void
    {
        $this->seed(RbacSeeder::class);
        $keys = $this->keys('provider_manager');

        $this->assertContains('hazards.view', $keys);
        $this->assertContains('hazards.manage', $keys);
        $this->assertSame([], array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'restraints.'))), 'No restraint keys.');
        $this->assertNotContains('healthSafety.viewAllSites', $keys, 'Their own Sites only.');
        // The copy rule still serves every other role that holds hazards.manage.
        $this->assertContains('restraints.manage', $this->keys('team_lead'));
    }

    public function test_the_migration_adds_only_hazards_manage_is_idempotent_and_rolls_back_only_that(): void
    {
        $this->seed(RbacSeeder::class);
        $role = Role::where('name', 'provider_manager')->sole();
        $manage = Permission::where('key', 'hazards.manage')->sole();
        $role->permissions()->detach($manage->id); // live before Q4: hazards.view only
        $before = $this->keys('provider_manager');
        $others = $this->otherRoles();

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('role_permission')->where('role_id', $role->id)->where('permission_id', $manage->id)->count());
        $this->assertEqualsCanonicalizing([...$before, 'hazards.manage'], $this->keys('provider_manager'));
        $this->assertSame([], array_values(array_filter($this->keys('provider_manager'), fn (string $key) => str_starts_with($key, 'restraints.'))));
        $this->assertSame($others, $this->otherRoles(), 'No other role changes.');

        $migration->down();
        $this->assertEqualsCanonicalizing($before, $this->keys('provider_manager'));
        $this->assertSame($others, $this->otherRoles());
    }

    /** @return list<string> */
    private function keys(string $role): array
    {
        return $this->sorted(Role::where('name', $role)->sole()->permissions()->pluck('key')->all());
    }

    /** @return array<string, list<string>> */
    private function otherRoles(): array
    {
        return Role::where('name', '!=', 'provider_manager')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $this->sorted($role->permissions()->pluck('key')->all())])->all();
    }

    /** @param  list<string>  $keys
     * @return list<string> */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
