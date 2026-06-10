<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\FinancePermissionsSeeder;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\RoadmapPermissionsSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SeedAllPermissionsToAdminSeeder;
use Database\Seeders\SeedCalendarPermissionsSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Route;

test('database seeder schedules HR permission backfills before admin permission sync', function () {
    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    $hrPosition = strpos($databaseSeeder, 'SeedHrPermissionsSeeder::class');
    $adminBackfillPosition = strpos($databaseSeeder, 'SeedAllPermissionsToAdminSeeder::class');

    expect($hrPosition)->not()->toBeFalse()
        ->and($adminBackfillPosition)->not()->toBeFalse()
        ->and($hrPosition)->toBeLessThan($adminBackfillPosition);
});

test('all route permission middleware keys are seeded for production', function () {
    foreach (productionPermissionSeeders() as $seeder) {
        $this->seed($seeder);
    }

    $definedPermissions = Permission::query()->pluck('key')->all();
    $routePermissions = routedPermissionKeys();

    expect(array_values(array_diff($routePermissions, $definedPermissions)))->toBe([]);
});

test('admin receives every seeded permission after production permission seeders run', function () {
    foreach (productionPermissionSeeders() as $seeder) {
        $this->seed($seeder);
    }

    $admin = Role::query()->where('name', 'admin')->firstOrFail();

    expect($admin->permissions()->count())->toBe(Permission::query()->count());
});

test('payslip routes use dedicated payslip permissions', function () {
    expect(routePermissionKeysForName('hr.payslips.index'))->toContain('hr.payslips.view')
        ->and(routePermissionKeysForName('hr.payslips.show'))->toContain('hr.payslips.view')
        ->and(routePermissionKeysForName('hr.payslips.download'))->toContain('hr.payslips.view')
        ->and(routePermissionKeysForName('hr.payslips.generate'))->toContain('hr.payslips.generate');
});

test('exit interview access is not granted through onboarding permissions', function () {
    expect(routePermissionKeysForName('hr.exit-interviews.index'))->not()->toContain('hr.onboarding.view')
        ->and(routePermissionKeysForName('hr.exit-interviews.index'))->not()->toContain('hr.onboarding.manage')
        ->and(routePermissionKeysForName('hr.exit-interviews.store'))->not()->toContain('hr.onboarding.manage');

    $controller = file_get_contents(app_path('Http/Controllers/Hr/ExitInterviewController.php'));

    expect($controller)->not()->toContain('hr.onboarding.view')
        ->and($controller)->not()->toContain('hr.onboarding.manage');
});

test('retired duplicate HR permission keys are not seeded', function () {
    foreach (productionPermissionSeeders() as $seeder) {
        $this->seed($seeder);
    }

    expect(Permission::query()
        ->whereIn('key', [
            'hr.disciplinary.view',
            'hr.vetting.view_disclosures',
            'hr.reports.builder',
        ])
        ->pluck('key')
        ->all())->toBe([]);
});

/**
 * @return array<int, class-string>
 */
function productionPermissionSeeders(): array
{
    return [
        RbacSeeder::class,
        OperationsPermissionsSeeder::class,
        FinancePermissionsSeeder::class,
        SeedCalendarPermissionsSeeder::class,
        GovernancePermissionsSeeder::class,
        SecurityDevicesPermissionsSeeder::class,
        RoadmapPermissionsSeeder::class,
        CateringPermissionsSeeder::class,
        SeedHrPermissionsSeeder::class,
        SeedAllPermissionsToAdminSeeder::class,
    ];
}

/**
 * @return array<int, string>
 */
function routedPermissionKeys(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect($route->gatherMiddleware()))
        ->filter(fn (string $middleware) => str_starts_with($middleware, 'permission:'))
        ->flatMap(fn (string $middleware) => explode('|', substr($middleware, strlen('permission:'))))
        ->map(fn (string $permission) => trim($permission))
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function routePermissionKeysForName(string $routeName): array
{
    $route = Route::getRoutes()->getByName($routeName);

    return collect($route?->gatherMiddleware() ?? [])
        ->filter(fn (string $middleware) => str_starts_with($middleware, 'permission:'))
        ->flatMap(fn (string $middleware) => explode('|', substr($middleware, strlen('permission:'))))
        ->map(fn (string $permission) => trim($permission))
        ->filter()
        ->values()
        ->all();
}
