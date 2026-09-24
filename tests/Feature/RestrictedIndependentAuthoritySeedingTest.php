<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemUsersSeeder;

const REVOKE_ADMIN_AUTHORITY_MIGRATION = 'database/migrations/2026_09_24_000600_revoke_restricted_independent_authority_from_admin.php';

/** @return list<string> */
function restrictedAuthorityRoleKeys(string $role): array
{
    return Role::query()->where('name', $role)->firstOrFail()->permissions()->orderBy('key')->pluck('key')->all();
}

test('revoke migration removes restricted independent authority from an already-seeded admin role', function () {
    $this->seed(RbacSeeder::class);
    $restricted = RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY;

    // What the pre-fix admin backfill seeders left behind.
    Role::query()->where('name', 'admin')->firstOrFail()->permissions()->syncWithoutDetaching(
        Permission::query()->whereIn('key', $restricted)->pluck('id'),
    );
    $policyRoles = ['team_lead', 'health_safety_officer', 'compliance_lead'];
    $policyGrantsBefore = collect($policyRoles)->mapWithKeys(fn (string $role) => [$role => restrictedAuthorityRoleKeys($role)])->all();

    $migration = require base_path(REVOKE_ADMIN_AUTHORITY_MIGRATION);
    $migration->up();
    $migration->up();
    $migration->down();

    $adminKeys = restrictedAuthorityRoleKeys('admin');
    $policyGrantsAfter = collect($policyRoles)->mapWithKeys(fn (string $role) => [$role => restrictedAuthorityRoleKeys($role)])->all();

    expect(array_values(array_intersect($restricted, $adminKeys)))->toBe([])
        ->and(count($adminKeys))->toBe(Permission::query()->whereNotIn('key', $restricted)->count())
        ->and($policyGrantsAfter)->toBe($policyGrantsBefore);
});

test('demo seeding gives independent decisions to role accounts instead of the demo admin', function () {
    $this->seed(RbacSeeder::class);
    Site::factory()->count(2)->create();
    $this->seed(SystemUsersSeeder::class);
    // Playwright global setup re-runs RbacSeeder over existing demo users.
    $this->seed(RbacSeeder::class);

    $admin = User::query()->where('email', 'admin@demo.test')->firstOrFail();
    $safety = User::query()->where('email', 'safety@demo.test')->firstOrFail();
    $compliance = User::query()->where('email', 'compliance@demo.test')->firstOrFail();
    $activeSiteIds = Site::query()->active()->notArchived()->orderBy('id')->pluck('id')->all();

    foreach (RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY as $key) {
        expect($admin->canDo($key))->toBeFalse("admin@demo.test must not hold {$key}");
    }

    expect($safety->roles()->pluck('name')->all())->toBe(['health_safety_officer'])
        ->and($safety->canDo('healthSafety.events.close'))->toBeTrue()
        ->and($safety->canDo('healthSafety.events.closeAny'))->toBeTrue()
        ->and($safety->canDo('healthSafety.closureExceptions.request'))->toBeTrue()
        ->and($safety->canDo('healthSafety.closureExceptions.approve'))->toBeFalse()
        ->and($compliance->roles()->pluck('name')->all())->toBe(['compliance_lead'])
        ->and($compliance->canDo('healthSafety.closureExceptions.approve'))->toBeTrue()
        ->and($compliance->canDo('safeguarding.declassification.approve'))->toBeTrue()
        ->and($compliance->canDo('healthSafety.events.close'))->toBeFalse()
        ->and(app(UserSiteAccessService::class)->accessibleSiteIds($compliance))->toEqualCanonicalizing($activeSiteIds);
});
