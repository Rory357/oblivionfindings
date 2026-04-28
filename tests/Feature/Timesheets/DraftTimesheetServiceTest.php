<?php

namespace Tests\Feature\Timesheets;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DraftTimesheetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_shift_preserves_manual_completion_payload_semantics(): void
    {
        [$shift, $worker] = $this->completedShift([
            'expected_break_minutes' => 45,
            'is_sleepover' => true,
            'is_on_call' => false,
            'shift_type' => 'sleepover',
            'coverage_roles' => ['awake_night'],
        ]);

        $attendance = $this->closedAttendance($shift, $worker, 15);

        $result = app(DraftTimesheetService::class)->fromShift($shift, $worker->id);

        $this->assertTrue($result['success']);
        $timesheet = $result['timesheet'];

        $this->assertNotNull($timesheet);
        $this->assertSame($shift->id, (int) $timesheet->shift_id);
        $this->assertSame($attendance->id, (int) $timesheet->attendance_session_id);
        $this->assertSame(45, (int) $timesheet->break_minutes);
        $this->assertTrue((bool) $timesheet->sleepover);
        $this->assertFalse((bool) $timesheet->on_call);
        $this->assertSame('sleepover', $timesheet->shift_type_snapshot);
        $this->assertSame(['awake_night'], $timesheet->coverage_roles_snapshot);
    }

    public function test_from_attendance_session_preserves_clock_out_payload_semantics(): void
    {
        [$shift, $worker] = $this->completedShift([
            'expected_break_minutes' => 45,
            'is_sleepover' => false,
            'is_on_call' => true,
            'shift_type' => 'on_call',
            'coverage_roles' => ['on_call'],
        ]);

        $attendance = $this->closedAttendance($shift, $worker, 15);

        $timesheet = app(DraftTimesheetService::class)
            ->fromAttendanceSession($attendance->fresh(['shift']), $worker->id);

        $this->assertNotNull($timesheet);
        $this->assertSame($shift->id, (int) $timesheet->shift_id);
        $this->assertSame($attendance->id, (int) $timesheet->attendance_session_id);
        $this->assertSame(15, (int) $timesheet->break_minutes);
        $this->assertFalse((bool) $timesheet->sleepover);
        $this->assertTrue((bool) $timesheet->on_call);
        $this->assertSame('on_call', $timesheet->shift_type_snapshot);
        $this->assertSame(['on_call'], $timesheet->coverage_roles_snapshot);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Shift, 1: User}
     */
    private function completedShift(array $overrides = []): array
    {
        $site = Site::factory()->create(['name' => 'Matai House']);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'first_name' => 'Ari',
            'last_name' => 'Kauri',
            'status' => 'active',
        ]);
        $worker = User::factory()->create(['name' => 'Sam Worker']);

        $startsAt = Carbon::parse('2026-04-20 09:00:00');
        $endsAt = Carbon::parse('2026-04-20 17:00:00');

        $shift = Shift::factory()->create(array_merge([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'status' => 'completed',
            'started_by' => $worker->id,
            'completed_by' => $worker->id,
            'created_by' => $worker->id,
        ], $overrides));

        return [$shift, $worker];
    }

    private function closedAttendance(Shift $shift, User $worker, int $breakMinutes): HrAttendanceSession
    {
        return HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => $shift->actual_ends_at,
            'break_minutes' => $breakMinutes,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $worker->id,
            'closed_by' => $worker->id,
        ]);
    }
}
