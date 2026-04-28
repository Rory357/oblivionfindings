<?php

namespace Tests\Feature\Routing;

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LegacyRouteRedirectController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceCanonicalTest extends TestCase
{
    #[DataProvider('attendanceRoutes')]
    public function test_attendance_routes_remain_canonical(string $name, string $method, string $uri, string $action): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Attendance route [{$name}] is missing.");
        $this->assertContains($method, $route->methods());
        $this->assertSame($uri, $route->uri());
        $this->assertSame(AttendanceController::class.'@'.$action, $route->getActionName());
        $this->assertNotSame(LegacyRouteRedirectController::class, ltrim($route->getActionName(), '\\'));
    }

    public static function attendanceRoutes(): array
    {
        return [
            ['attendance.index', 'GET', 'attendance', 'index'],
            ['attendance.clockIn', 'POST', 'attendance/clock-in', 'clockIn'],
            ['attendance.clockOut', 'POST', 'attendance/clock-out', 'clockOut'],
            ['attendance.break.start', 'POST', 'attendance/break/start', 'startBreak'],
            ['attendance.break.end', 'POST', 'attendance/break/end', 'endBreak'],
        ];
    }
}
