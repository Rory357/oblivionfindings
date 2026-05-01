<?php

namespace Tests\Feature\ControlRoom;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControlRoomRouteReadinessTest extends TestCase
{
    public function test_control_room_routes_do_not_use_inline_closures(): void
    {
        $closureRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'control-room'))
            ->filter(fn ($route) => $route->getAction('uses') instanceof \Closure)
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $closureRoutes);
    }

    public function test_alert_detail_sub_routes_constrain_alert_to_numeric_ids(): void
    {
        $unconstrainedRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'control-room/alerts/{alert}'))
            ->filter(fn ($route) => ($route->wheres['alert'] ?? null) !== '[0-9]+')
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unconstrainedRoutes);
    }
}
