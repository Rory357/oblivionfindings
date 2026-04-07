<?php

namespace Tests\Unit\Operations;

use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ShiftSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftSignalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
    }

    public function test_emit_for_shift_is_idempotent_per_shift_event_window(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:20:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => User::factory()->create()->id,
            'starts_at' => now()->subMinutes(20),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
        ]);

        $service = app(ShiftSignalService::class);

        $first = $service->emitForShift(
            $shift,
            ShiftSignalService::TYPE_NO_SHOW,
            'medium',
            $shift->starts_at->copy()->addMinutes(15),
            ['threshold_minutes' => 15],
            'threshold:15',
        );

        $second = $service->emitForShift(
            $shift,
            ShiftSignalService::TYPE_NO_SHOW,
            'medium',
            $shift->starts_at->copy()->addMinutes(15),
            ['threshold_minutes' => 15],
            'threshold:15',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShiftSignal::query()->count());
        $this->assertDatabaseCount('shift_signal_outbox', 1);
        $this->assertDatabaseHas('control_room_signals', [
            'signal_type_code' => 'shift_no_show',
        ]);
    }
}
