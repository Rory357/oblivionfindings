<?php

namespace Tests\Feature\Routing;

use App\Http\Controllers\LegacyRouteRedirectController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyShiftNamesRemovedTest extends TestCase
{
    #[DataProvider('legacyRouteNames')]
    public function test_legacy_shift_and_timesheet_route_names_are_removed(string $legacyName): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName($legacyName),
            "Legacy route name [{$legacyName}] should not resolve after Phase 7 cleanup."
        );
    }

    #[DataProvider('canonicalRouteNames')]
    public function test_canonical_operations_route_names_still_resolve(string $canonicalName): void
    {
        $this->assertNotNull(
            Route::getRoutes()->getByName($canonicalName),
            "Canonical route [{$canonicalName}] is missing."
        );
    }

    #[DataProvider('removedLegacyWriteRoutes')]
    public function test_legacy_write_urls_are_not_mounted(string $method, string $uri): void
    {
        $matches = collect(Route::getRoutes())->filter(function ($route) use ($method, $uri) {
            return $route->uri() === $uri
                && in_array($method, $route->methods(), true);
        });

        $this->assertTrue(
            $matches->isEmpty(),
            "Legacy write URL [{$method} {$uri}] should not be mounted after Phase 7 cleanup."
        );
    }

    public function test_legacy_shift_file_only_keeps_get_redirects_and_attendance_routes(): void
    {
        $legacyRedirectUris = [
            'shifts',
            'shifts/create',
            'shifts/{shift}',
            'shifts/{shift}/edit',
            'timesheets',
            'timesheets/approvals',
            'timesheets/create',
            'timesheets/{timesheet}',
            'timesheets/{timesheet}/edit',
        ];

        $routes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with($route->uri(), 'attendance')
                || in_array($route->uri(), [
                    'shifts',
                    'shifts/create',
                    'shifts/{shift}',
                    'shifts/{shift}/edit',
                    'timesheets',
                    'timesheets/approvals',
                    'timesheets/create',
                    'timesheets/{timesheet}',
                    'timesheets/{timesheet}/edit',
                ], true);
        });

        $unexpectedLegacyMounts = $routes->reject(function ($route) use ($legacyRedirectUris) {
            $uri = $route->uri();

            if (str_starts_with($uri, 'attendance')) {
                return str_starts_with((string) $route->getName(), 'attendance.');
            }

            return in_array($uri, $legacyRedirectUris, true)
                && $route->methods() === ['GET', 'HEAD']
                && ltrim($route->getActionName(), '\\') === LegacyRouteRedirectController::class
                && $route->getName() === null;
        });

        $this->assertTrue(
            $unexpectedLegacyMounts->isEmpty(),
            'Legacy /shifts or /timesheets routes should only exist as unnamed GET redirects: '
            .$unexpectedLegacyMounts->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' ['.($route->getName() ?? 'unnamed').']')->join(', ')
        );
    }

    public static function legacyRouteNames(): array
    {
        return [
            ['shifts.index'],
            ['shifts.show'],
            ['shifts.create'],
            ['shifts.edit'],
            ['shifts.store'],
            ['shifts.series.store'],
            ['shifts.update'],
            ['shifts.assign'],
            ['shifts.unassign'],
            ['shifts.start'],
            ['shifts.complete'],
            ['shifts.cancel'],
            ['shifts.reopen'],
            ['shifts.replacement.request'],
            ['shifts.replacement.cancel'],
            ['shifts.tasks.update'],
            ['timesheets.index'],
            ['timesheets.approvals'],
            ['timesheets.show'],
            ['timesheets.create'],
            ['timesheets.edit'],
            ['timesheets.store'],
            ['timesheets.update'],
            ['timesheets.submit'],
            ['timesheets.resubmit'],
            ['timesheets.approve'],
            ['timesheets.reject'],
            ['timesheets.return'],
            ['timesheets.bulkApprove'],
            ['timesheets.bulkReturn'],
            ['timesheets.bulkReject'],
        ];
    }

    public static function canonicalRouteNames(): array
    {
        return [
            ['operations.shifts.index'],
            ['operations.shifts.show'],
            ['operations.shifts.create'],
            ['operations.shifts.edit'],
            ['operations.shifts.store'],
            ['operations.shifts.series.store'],
            ['operations.shifts.update'],
            ['operations.shifts.assign'],
            ['operations.shifts.unassign'],
            ['operations.shifts.start'],
            ['operations.shifts.complete'],
            ['operations.shifts.cancel'],
            ['operations.shifts.reopen'],
            ['operations.shifts.replacement.request'],
            ['operations.shifts.replacement.cancel'],
            ['operations.shifts.tasks.update'],
            ['operations.timesheets.index'],
            ['operations.timesheets.approvals'],
            ['operations.timesheets.show'],
            ['operations.timesheets.create'],
            ['operations.timesheets.edit'],
            ['operations.timesheets.store'],
            ['operations.timesheets.update'],
            ['operations.timesheets.submit'],
            ['operations.timesheets.resubmit'],
            ['operations.timesheets.approve'],
            ['operations.timesheets.reject'],
            ['operations.timesheets.return'],
            ['operations.timesheets.bulkApprove'],
            ['operations.timesheets.bulkReturn'],
            ['operations.timesheets.bulkReject'],
        ];
    }

    public static function removedLegacyWriteRoutes(): array
    {
        return [
            ['POST', 'shifts'],
            ['POST', 'shifts/series'],
            ['PUT', 'shifts/{shift}'],
            ['POST', 'shifts/{shift}/assign'],
            ['POST', 'shifts/{shift}/unassign'],
            ['PATCH', 'shifts/{shift}/start'],
            ['PATCH', 'shifts/{shift}/complete'],
            ['PATCH', 'shifts/{shift}/cancel'],
            ['PATCH', 'shifts/{shift}/reopen'],
            ['POST', 'shifts/{shift}/replacement-request'],
            ['PATCH', 'shifts/{shift}/replacement-request/cancel'],
            ['PATCH', 'shifts/{shift}/tasks/{task}'],
            ['POST', 'timesheets'],
            ['PUT', 'timesheets/{timesheet}'],
            ['POST', 'timesheets/{timesheet}/submit'],
            ['POST', 'timesheets/{timesheet}/resubmit'],
            ['POST', 'timesheets/{timesheet}/approve'],
            ['POST', 'timesheets/{timesheet}/reject'],
            ['POST', 'timesheets/{timesheet}/return'],
            ['POST', 'timesheets/bulk-approve'],
            ['POST', 'timesheets/bulk-return'],
            ['POST', 'timesheets/bulk-reject'],
        ];
    }
}
