<?php

namespace Tests\Feature\Emar;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedAllPermissionsToAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectedMedicationPermissionSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITIES = [
        'medications.pharmacy.connect.manage',
        'medications.pharmacy.send',
        'medications.external.manage',
        'medications.transfers.manage',
        'medications.catalogue.manage',
        'medications.backups.manage',
    ];

    public function test_connected_capabilities_are_registered_without_implicit_builtin_role_grants(): void
    {
        $this->seed(RbacSeeder::class);
        $this->assertSame(6, Permission::query()->whereIn('key', self::CAPABILITIES)->count());
        foreach (self::CAPABILITIES as $key) {
            $this->assertContains($key, RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY);
        }

        // Each shared-constant backfill must retain the no-blanket-grant boundary.
        foreach (range(1, 2) as $pass) {
            $this->seed([
                ClinicalPermissionsSeeder::class,
                OperationsPermissionsSeeder::class,
                SeedAllPermissionsToAdminSeeder::class,
            ]);
            $this->assertSame(0, Role::query()->where('type', 'system')
                ->whereHas('permissions', fn ($query) => $query->whereIn('key', self::CAPABILITIES))->count());
            $admin = Role::query()->where('name', 'admin')->firstOrFail();
            $this->assertTrue($admin->permissions()->where('key', 'medications.view')->exists());
            $this->assertTrue($admin->permissions()->where('key', 'sites.viewAll')->exists());
            $this->assertTrue(Role::query()->where('name', 'team_lead')->firstOrFail()
                ->permissions()->where('key', 'medications.controlled.manage')->exists());
        }
    }

    public function test_reseeding_removes_blanket_grants_but_preserves_explicit_overrides_and_assigned_custom_roles(): void
    {
        $this->seed(RbacSeeder::class);
        $ids = Permission::query()->whereIn('key', self::CAPABILITIES)->pluck('id', 'key');
        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        // Reproduce the former blanket grant; it has no independent approval provenance.
        $admin->permissions()->syncWithoutDetaching($ids->values()->all());
        $this->assertSame(6, $admin->permissions()->whereIn('key', self::CAPABILITIES)->count());

        $explicit = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $explicit->roles()->attach($admin);
        $explicit->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $custom = Role::query()->create(['name' => 'fictional_connected_reviewer', 'label' => 'Fictional connected reviewer', 'level' => 40, 'type' => 'custom']);
        $custom->permissions()->attach($ids['medications.transfers.manage']);
        $scoped = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $scoped->roles()->attach($custom);
        $denied = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $denied->roles()->attach($custom);
        $denied->permissionOverrides()->attach($ids['medications.transfers.manage'], ['allowed' => false]);

        $overrideRows = $this->rawRows('permission_user', 'user_id', [$explicit->id, $denied->id]);
        $customRows = $this->rawRows('role_permission', 'role_id', [$custom->id]);
        foreach (range(1, 2) as $pass) {
            $this->seed([RbacSeeder::class, OperationsPermissionsSeeder::class, SeedAllPermissionsToAdminSeeder::class]);
            $this->assertSame(0, $admin->permissions()->whereIn('key', self::CAPABILITIES)->count());
            $this->assertSame($overrideRows, $this->rawRows('permission_user', 'user_id', [$explicit->id, $denied->id]));
            $this->assertSame($customRows, $this->rawRows('role_permission', 'role_id', [$custom->id]));
            foreach (self::CAPABILITIES as $key) {
                $this->assertTrue($explicit->fresh()->canDo($key), 'The explicit user assignment remains '.$key);
            }
            $this->assertTrue($scoped->fresh()->canDo('medications.transfers.manage'));
            $this->assertFalse($scoped->fresh()->canDo('medications.backups.manage'));
            $this->assertFalse($denied->fresh()->canDo('medications.transfers.manage'));
        }
    }

    public function test_permission_registration_replay_and_rollback_preserve_explicit_grants_without_assigning_others(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $explicit = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $explicit->roles()->attach($admin);
        $permission = Permission::query()->where('key', 'medications.backups.manage')->firstOrFail();
        $explicit->permissionOverrides()->attach($permission, ['allowed' => true]);
        $beforePermissions = Permission::query()->whereIn('key', self::CAPABILITIES)->orderBy('key')->pluck('id', 'key')->all();
        $beforeOverrides = $this->rawRows('permission_user', 'user_id', [$explicit->id]);
        $beforeRoleGrants = DB::table('role_permission')->whereIn('permission_id', array_values($beforePermissions))
            ->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();

        $migration = require base_path('database/migrations/2026_10_07_095000_register_connected_medication_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame($beforePermissions, Permission::query()->whereIn('key', self::CAPABILITIES)->orderBy('key')->pluck('id', 'key')->all());
        $this->assertSame($beforeOverrides, $this->rawRows('permission_user', 'user_id', [$explicit->id]));
        $this->assertSame($beforeRoleGrants, DB::table('role_permission')->whereIn('permission_id', array_values($beforePermissions))
            ->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertTrue($explicit->fresh()->canDo('medications.backups.manage'));
        $this->assertFalse($explicit->fresh()->canDo('medications.pharmacy.send'));
    }

    private function rawRows(string $table, string $column, array $ids): array
    {
        return DB::table($table)->whereIn($column, $ids)->orderBy($column)->orderBy('permission_id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }
}
