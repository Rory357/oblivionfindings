<?php

namespace Tests\Feature\Database;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/**
 * 2026_08_23_000140 creates the funding Site-bypass and posting-retry
 * permissions and grants them to the admin and finance roles through
 * Role::permissions() (the role_permission pivot). Its rollback named a
 * non-existent permission_role table, so down() failed before revoking
 * anything; it must revoke through the same pivot the grant used.
 */
class FundingClaimDeliveryMonetisationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_08_23_000140_bind_funding_claim_delivery_monetisation.php';

    private const KEYS = ['funding.viewAllSites', 'funding.claims.retryPosting'];

    public function test_000140_grants_funding_authority_to_admin_and_finance_and_rollback_revokes_it_through_role_permission(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        // Rows the schema bootstrap committed when 000140 ran as a pending
        // migration; they are put back verbatim so the next test reuses the
        // schema instead of rebuilding it.
        $baselinePermissions = $this->rows(DB::table('permissions')->whereIn('key', self::KEYS));
        $baselineIds = array_column($baselinePermissions, 'id');
        $baselineRoleGrants = $this->rows(DB::table('role_permission')->whereIn('permission_id', $baselineIds));
        $baselineUserGrants = $this->rows(DB::table('permission_user')->whereIn('permission_id', $baselineIds));

        $permissionIds = collect(self::KEYS)
            ->map(fn (string $key): int => Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'funding', 'module' => 'Finance'],
            )->id)
            ->all();
        $unrelated = Permission::query()->firstOrCreate(
            ['key' => 'funding.viewAny'],
            ['description' => 'View funding', 'group' => 'funding', 'module' => 'Finance'],
        );
        $admin = Role::query()->firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $finance = Role::query()->firstOrCreate(['name' => 'finance'], ['label' => 'Finance']);
        $supportWorker = Role::query()->firstOrCreate(['name' => 'support_worker'], ['label' => 'Support Worker']);
        $admin->permissions()->syncWithoutDetaching([...$permissionIds, $unrelated->id]);
        $finance->permissions()->syncWithoutDetaching($permissionIds);
        $holder = User::factory()->create(['approved_at' => now()]);
        $holder->permissionOverrides()->syncWithoutDetaching([
            $permissionIds[0] => ['allowed' => true],
        ]);

        $path = database_path(self::MIGRATION);
        // The migration's DDL commits implicitly, so its data is committed too.
        $connection->commit();

        try {
            (require $path)->down();

            $this->assertFalse(Schema::hasColumn('funding_claims', 'integrity_state'));
            $this->assertSame(0, DB::table('permissions')->whereIn('key', self::KEYS)->count());
            $this->assertSame(0, DB::table('role_permission')->whereIn('permission_id', $permissionIds)->count());
            $this->assertSame(0, DB::table('permission_user')->whereIn('permission_id', $permissionIds)->count());
            $this->assertTrue(DB::table('role_permission')
                ->where('role_id', $admin->id)
                ->where('permission_id', $unrelated->id)
                ->exists());

            (require $path)->up();

            $this->assertTrue(Schema::hasColumn('funding_claims', 'integrity_state'));
            $this->assertEqualsCanonicalizing(self::KEYS, $this->fundingKeysGrantedTo($admin));
            $this->assertEqualsCanonicalizing(self::KEYS, $this->fundingKeysGrantedTo($finance));
            $this->assertSame([], $this->fundingKeysGrantedTo($supportWorker));
            $this->assertTrue(DB::table('role_permission')
                ->where('role_id', $admin->id)
                ->where('permission_id', $unrelated->id)
                ->exists());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if (! Schema::hasColumn('funding_claims', 'integrity_state')) {
                (require $path)->up();
            }
            // Deleting the recreated rows cascades their grants away.
            DB::table('permissions')->whereIn('key', self::KEYS)->delete();
            DB::table('permissions')->insert($baselinePermissions);
            DB::table('role_permission')->insertOrIgnore($baselineRoleGrants);
            DB::table('permission_user')->insertOrIgnore($baselineUserGrants);
            $connection->beginTransaction();
        }
    }

    /** @return list<string> */
    private function fundingKeysGrantedTo(Role $role): array
    {
        return DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $role->id)
            ->whereIn('permissions.key', self::KEYS)
            ->pluck('permissions.key')
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function rows(Builder $query): array
    {
        return $query->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
