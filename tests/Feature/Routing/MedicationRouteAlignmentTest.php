<?php

namespace Tests\Feature\Routing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MedicationRouteAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_medication_administration_write_routes_use_the_same_permission_or_list(): void
    {
        $expected = 'permission:medications.administer.record|clients.update|medications.orders.manage';

        foreach ([
            'meds.round.administer',
            'api.medications.administrations.record',
            'operations.clients.medical.medications.administrations.store',
            'clients.medical.medications.administrations.store',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] was not registered.");
            $this->assertContains($expected, $route->gatherMiddleware());
        }
    }

    public function test_orphaned_medication_redirect_routes_are_not_registered(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('medications.dashboard'));
        $this->assertNull(Route::getRoutes()->getByName('medications.enhanced-mar'));
    }
}
