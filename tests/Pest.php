<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(DuskTestCase::class)
//  ->use(Illuminate\Foundation\Testing\DatabaseMigrations::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Attach the minimum canonical current-staff Site provenance needed by HR
 * feature tests. Product code must never infer staff eligibility from a legacy
 * storage marker, so focused tests use this helper instead of setting one.
 */
function ensureCanonicalHrStaffProfile(
    User $user,
    ?Site $site = null,
    array $overrides = [],
): Site {
    $site ??= Site::factory()->create([
        'name' => 'Canonical HR test Site '.$user->id,
        'is_active' => true,
        'archived' => false,
    ]);

    $profile = HrEmployeeProfile::withTrashed()
        ->where('user_id', $user->id)
        ->first();
    $attributes = [
        'employee_number' => 'TEST-HR-'.$user->id,
        'work_email' => $user->email,
        'position_role' => $user->role ?: 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ];

    if ($profile) {
        if ($profile->trashed()) {
            $profile->restore();
        }
        $profile->forceFill($attributes)->save();
    } else {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    return $site;
}
