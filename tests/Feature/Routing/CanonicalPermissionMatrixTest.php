<?php

namespace Tests\Feature\Routing;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CanonicalPermissionMatrixTest extends TestCase
{
    public function test_canonical_shift_and_timesheet_routes_keep_their_permission_requirements(): void
    {
        $matrix = require base_path('tests/fixtures/routing/shift-permission-matrix.php');

        foreach ($matrix as $routeName => $expectedPermissions) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Canonical route [{$routeName}] is missing.");
            $this->assertSame(
                $expectedPermissions,
                $this->permissionMiddleware($route),
                "Permission middleware drifted for [{$routeName}]."
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissionMiddleware(RoutingRoute $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn (string $middleware) => str_starts_with($middleware, 'permission:'))
            ->values()
            ->all();
    }

}
