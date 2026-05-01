<?php

namespace Tests\Feature\ControlRoom;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomDbDriverTest extends TestCase
{
    public function test_control_room_test_environment_uses_mysql(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }
}
