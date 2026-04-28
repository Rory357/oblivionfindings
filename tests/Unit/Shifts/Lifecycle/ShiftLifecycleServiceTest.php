<?php

namespace Tests\Unit\Shifts\Lifecycle;

use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_moves_scheduled_shift_to_in_progress_once(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'scheduled']);
        $startedAt = now()->subMinutes(5)->startOfMinute();

        $service = app(ShiftLifecycleService::class);
        $started = $service->start($shift, $actor, $startedAt, ShiftLifecycleSource::Manual);
        $again = $service->start($started, $actor, $startedAt->copy()->addMinute(), ShiftLifecycleSource::Manual);

        $this->assertSame('in_progress', $started->status);
        $this->assertSame('in_progress', $again->status);
        $this->assertTrue($again->actual_starts_at->equalTo($startedAt));
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::STARTED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->count());
    }

    public function test_clock_in_source_can_start_draft_shift_to_preserve_attendance_flow(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, ['status' => 'draft']);

        $started = app(ShiftLifecycleService::class)->start($shift, $actor, now(), ShiftLifecycleSource::ClockIn);

        $this->assertSame('in_progress', $started->status);
        $this->assertSame($actor->id, $started->started_by);
    }

    public function test_complete_is_idempotent_and_does_not_duplicate_timeline_or_timesheet(): void
    {
        $actor = User::factory()->create();
        $shift = $this->shiftFor($actor, [
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(4)->startOfMinute(),
            'started_by' => $actor->id,
            'ends_at' => now()->subMinute(),
            'expected_break_minutes' => 15,
        ]);

        $service = app(ShiftLifecycleService::class);
        $data = new CompleteShiftData(
            finalNoteBody: 'Completed with the shared lifecycle service.',
            source: ShiftLifecycleSource::Manual,
        );

        $completed = $service->complete($shift, $actor, $data);
        $again = $service->complete($completed, $actor, $data);

        $this->assertSame('completed', $again->status);
        $this->assertSame(1, Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $actor->id)
            ->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::COMPLETED_EVENT_TYPE)
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function shiftFor(User $staff, array $overrides = []): Shift
    {
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create();

        return Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'created_by' => $staff->id,
            ...$overrides,
        ]);
    }
}
