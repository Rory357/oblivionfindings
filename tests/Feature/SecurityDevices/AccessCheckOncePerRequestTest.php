<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\SecurityDevices\RecordAccessibleSiteIds;

// MASTER's speed finding (31 Oct 2026): on the .com, one sidebar task count for a manager made 91
// asset checks at 4 queries each, one of them an information_schema lookup for users.site_id.
// The column answer is now kept per process, and each viewer's Sites are read once per service
// object (request-scoped). A freshly loaded viewer reads them again.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->home = Site::factory()->create(['is_active' => true]);
    $this->away = Site::factory()->create(['is_active' => true]);
});

function sdOncePerRequestManager(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager', 'external_clinical_account' => false]);
    $user->roles()->sync([Role::query()->where('name', 'manager')->value('id')]);
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

    return $user->fresh();
}

/** @return array{queries: int, schema: int, profiles: int} */
function sdOncePerRequestCount(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    return [
        'queries' => $log->count(),
        'schema' => $log->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'information_schema'))->count(),
        'profiles' => $log->filter(fn (string $sql): bool => str_contains($sql, 'hr_employee_profiles'))->count(),
    ];
}

it('gives the same Sites and assets as before', function () {
    $manager = sdOncePerRequestManager($this->home);
    $mine = Asset::factory()->create(['site_id' => $this->home->id]);
    $theirs = Asset::factory()->create(['site_id' => $this->away->id]);
    $access = app(SecurityDevicesAccessService::class);

    expect($access->accessibleSiteIds($manager))->toBe([$this->home->id])
        ->and($access->canAccessAsset($manager, $mine))->toBeTrue()
        ->and($access->canAccessAsset($manager, $theirs))->toBeFalse()
        // Asked again on the same object: the same answers.
        ->and($access->accessibleSiteIds($manager))->toBe([$this->home->id])
        ->and($access->canAccessAsset($manager, $theirs))->toBeFalse();
});

it('asks the schema at most once and reads the viewer\'s Sites once, however many assets are checked', function () {
    $manager = sdOncePerRequestManager($this->home);
    $assets = Asset::factory()->count(20)->create(['site_id' => $this->home->id]);

    // Each run is its own "request": the scoped service starts empty, and the asset
    // policy (as the task count uses it) resolves that one service every time.
    $ten = sdOncePerRequestCount(function () use ($manager, $assets): void {
        app()->forgetScopedInstances();
        $assets->take(10)->each(fn (Asset $asset) => expect(Gate::forUser($manager)->allows('view', $asset))->toBeTrue());
    });
    $twenty = sdOncePerRequestCount(function () use ($manager, $assets): void {
        app()->forgetScopedInstances();
        $assets->each(fn (Asset $asset) => expect(Gate::forUser($manager)->allows('view', $asset))->toBeTrue());
    });

    expect($ten['profiles'])->toBe(1)
        ->and($twenty['profiles'])->toBe(1)
        ->and($twenty['schema'])->toBe(0)
        // Each extra asset costs only its own check (it cost 4 queries before).
        ->and(($twenty['queries'] - $ten['queries']) / 10)->toBeLessThanOrEqual(1);
});

it('reads again for a freshly loaded viewer', function () {
    $manager = sdOncePerRequestManager($this->home);
    $access = app(SecurityDevicesAccessService::class);
    expect($access->accessibleSiteIds($manager))->toBe([$this->home->id]);

    HrEmployeeProfile::query()->where('user_id', $manager->id)->update(['primary_site_id' => $this->away->id]);

    expect($access->accessibleSiteIds($manager->fresh()))->toBe([$this->away->id]);
});

it('does not carry a Site change into the next request, even for the same signed-in user object', function () {
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $viewer = User::factory()->create(['approved_at' => now(), 'role' => 'provider_manager', 'external_clinical_account' => false]);
    $viewer->roles()->sync([Role::query()->where('name', 'provider_manager')->value('id')]);
    HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
    $viewer = $viewer->fresh();

    $this->actingAs($viewer)->get(route('security-devices.sites.show', $this->away))->assertNotFound();

    HrEmployeeProfile::query()->where('user_id', $viewer->id)->update(['primary_site_id' => $this->away->id]);

    $this->actingAs($viewer)->get(route('security-devices.sites.show', $this->away))->assertOk();
});

it('starts each queue job with a fresh service, so a Site change between jobs is seen', function () {
    $manager = sdOncePerRequestManager($this->home);
    RecordAccessibleSiteIds::$seen = [];

    RecordAccessibleSiteIds::dispatch($manager->id)->onConnection('database')->onQueue('sd-scope-test');
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'sd-scope-test', '--stop-when-empty' => true])->assertExitCode(0);

    HrEmployeeProfile::query()->where('user_id', $manager->id)->update(['primary_site_id' => $this->away->id]);

    RecordAccessibleSiteIds::dispatch($manager->id)->onConnection('database')->onQueue('sd-scope-test');
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'sd-scope-test', '--stop-when-empty' => true])->assertExitCode(0);

    expect(RecordAccessibleSiteIds::$seen)->toBe([[$this->home->id], [$this->away->id]]);
});
