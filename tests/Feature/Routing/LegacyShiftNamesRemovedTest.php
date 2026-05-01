<?php

namespace Tests\Feature\Routing;

use App\Http\Controllers\LegacyRouteRedirectController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyShiftNamesRemovedTest extends TestCase
{
    #[DataProvider('legacyRouteNames')]
    public function test_legacy_shift_timesheet_and_rostering_route_names_are_removed(string $legacyName): void
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

    #[DataProvider('legacyWriteRoutes')]
    public function test_legacy_write_urls_are_redirect_only(string $method, string $uri, string $canonical): void
    {
        $matches = collect(Route::getRoutes())->filter(function ($route) use ($method, $uri) {
            return $route->uri() === $uri
                && in_array($method, $route->methods(), true);
        });

        $this->assertCount(
            1,
            $matches,
            "Expected exactly one redirect mount for [{$method} {$uri}], found {$matches->count()}."
        );

        $route = $matches->first();

        $this->assertSame(
            LegacyRouteRedirectController::class,
            ltrim($route->getActionName(), '\\'),
            "Legacy write URL [{$method} {$uri}] must be a redirect via LegacyRouteRedirectController, not a controller mount."
        );
        $this->assertNull(
            $route->getName(),
            "Legacy write URL [{$method} {$uri}] must remain unnamed (legacy names stay removed)."
        );
        $this->assertSame(
            308,
            (int) ($route->defaults['status'] ?? 0),
            "Legacy write URL [{$method} {$uri}] must redirect with HTTP 308 to preserve method+body."
        );
        $this->assertSame(
            $canonical,
            $route->defaults['canonical'] ?? null,
            "Legacy write URL [{$method} {$uri}] must redirect to canonical [{$canonical}]."
        );
    }

    public function test_legacy_shift_file_only_keeps_redirects_rostering_bridge_and_attendance_routes(): void
    {
        $legacyRedirectUris = [
            // GET-only deep-link redirects
            'shifts',
            'shifts/create',
            'shifts/{shift}',
            'shifts/{shift}/edit',
            'timesheets',
            'timesheets/approvals',
            'timesheets/create',
            'timesheets/{timesheet}',
            'timesheets/{timesheet}/edit',
            // POST/PATCH/PUT 308 redirects
            'shifts/series',
            'shifts/{shift}/assign',
            'shifts/{shift}/unassign',
            'shifts/{shift}/start',
            'shifts/{shift}/complete',
            'shifts/{shift}/cancel',
            'shifts/{shift}/reopen',
            'shifts/{shift}/replacement-request',
            'shifts/{shift}/replacement-request/cancel',
            'shifts/{shift}/tasks/{task}',
            'timesheets/{timesheet}/submit',
            'timesheets/{timesheet}/resubmit',
            'timesheets/{timesheet}/approve',
            'timesheets/{timesheet}/reject',
            'timesheets/{timesheet}/return',
            'timesheets/bulk-approve',
            'timesheets/bulk-return',
            'timesheets/bulk-reject',
            'rostering/time-off',
            'rostering/time-off/{staffTimeOff}',
        ];

        $routes = collect(Route::getRoutes())->filter(function ($route) use ($legacyRedirectUris) {
            return str_starts_with($route->uri(), 'attendance')
                || in_array($route->uri(), $legacyRedirectUris, true);
        });

        $unexpectedLegacyMounts = $routes->reject(function ($route) use ($legacyRedirectUris) {
            $uri = $route->uri();

            if (str_starts_with($uri, 'attendance')) {
                return str_starts_with((string) $route->getName(), 'attendance.');
            }

            return in_array($uri, $legacyRedirectUris, true)
                && ltrim($route->getActionName(), '\\') === LegacyRouteRedirectController::class
                && $route->getName() === null;
        });

        $this->assertTrue(
            $unexpectedLegacyMounts->isEmpty(),
            'Legacy /shifts, /timesheets, or rostering write routes must only exist as unnamed LegacyRouteRedirectController mounts: '
            .$unexpectedLegacyMounts->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' ['.($route->getName() ?? 'unnamed').' → '.$route->getActionName().']')->join(', ')
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
            ['rostering.index'],
            ['rostering.time_off.store'],
            ['rostering.time_off.destroy'],
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
            ['operations.rostering.index'],
            ['operations.rostering.time_off.store'],
            ['operations.rostering.time_off.destroy'],
        ];
    }

    public static function legacyWriteRoutes(): array
    {
        return [
            ['POST', 'shifts', 'operations.shifts.store'],
            ['POST', 'shifts/series', 'operations.shifts.series.store'],
            ['PUT', 'shifts/{shift}', 'operations.shifts.update'],
            ['POST', 'shifts/{shift}/assign', 'operations.shifts.assign'],
            ['POST', 'shifts/{shift}/unassign', 'operations.shifts.unassign'],
            ['PATCH', 'shifts/{shift}/start', 'operations.shifts.start'],
            ['PATCH', 'shifts/{shift}/complete', 'operations.shifts.complete'],
            ['PATCH', 'shifts/{shift}/cancel', 'operations.shifts.cancel'],
            ['PATCH', 'shifts/{shift}/reopen', 'operations.shifts.reopen'],
            ['POST', 'shifts/{shift}/replacement-request', 'operations.shifts.replacement.request'],
            ['PATCH', 'shifts/{shift}/replacement-request/cancel', 'operations.shifts.replacement.cancel'],
            ['PATCH', 'shifts/{shift}/tasks/{task}', 'operations.shifts.tasks.update'],
            ['POST', 'timesheets', 'operations.timesheets.store'],
            ['PUT', 'timesheets/{timesheet}', 'operations.timesheets.update'],
            ['POST', 'timesheets/{timesheet}/submit', 'operations.timesheets.submit'],
            ['POST', 'timesheets/{timesheet}/resubmit', 'operations.timesheets.resubmit'],
            ['POST', 'timesheets/{timesheet}/approve', 'operations.timesheets.approve'],
            ['POST', 'timesheets/{timesheet}/reject', 'operations.timesheets.reject'],
            ['POST', 'timesheets/{timesheet}/return', 'operations.timesheets.return'],
            ['POST', 'timesheets/bulk-approve', 'operations.timesheets.bulkApprove'],
            ['POST', 'timesheets/bulk-return', 'operations.timesheets.bulkReturn'],
            ['POST', 'timesheets/bulk-reject', 'operations.timesheets.bulkReject'],
            ['POST', 'rostering/time-off', 'operations.rostering.time_off.store'],
            ['DELETE', 'rostering/time-off/{staffTimeOff}', 'operations.rostering.time_off.destroy'],
        ];
    }
}
