<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use App\Services\ShiftOrphanDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftSafetyInvariantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_timesheet_with_zero_duration_is_blocked(): void
    {
        $this->expectException(ValidationException::class);

        $staff = User::factory()->create();
        $client = Client::factory()->create();

        Timesheet::query()->create([
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(9, 30),
            'break_minutes' => 30,
            'status' => 'draft',
            'created_by' => $staff->id,
        ]);
    }

    public function test_overlapping_attendance_sessions_are_blocked(): void
    {
        $staff = User::factory()->create();

        HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'clock_in_at' => now()->setTime(9, 0),
            'clock_out_at' => now()->setTime(12, 0),
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $this->expectException(ValidationException::class);

        HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'clock_in_at' => now()->setTime(11, 0),
            'clock_out_at' => now()->setTime(14, 0),
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);
    }

    public function test_acknowledged_handover_must_match_current_incoming_assignee(): void
    {
        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);
        $outgoingStaff = User::factory()->create();
        $incomingStaff = User::factory()->create();
        $wrongStaff = User::factory()->create();

        $outgoingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $outgoingStaff->id,
            'status' => 'in_progress',
            'created_by' => $outgoingStaff->id,
        ]);

        $incomingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $incomingStaff->id,
            'status' => 'scheduled',
            'created_by' => $incomingStaff->id,
        ]);

        $this->expectException(ValidationException::class);

        ShiftHandover::query()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoingStaff->id,
            'incoming_staff_id' => $wrongStaff->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'handover_notes' => 'Client settled.',
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $outgoingStaff->id,
            'acknowledged_at' => now(),
            'acknowledged_by' => $wrongStaff->id,
        ]);
    }

    public function test_orphan_detection_queries_return_expected_records(): void
    {
        $staff = User::factory()->create();
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();

        $timesheet = new Timesheet([
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'break_minutes' => 30,
            'status' => 'draft',
            'created_by' => $staff->id,
        ]);
        $timesheet->saveQuietly();

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'clock_in_at' => now()->subHours(8),
            'clock_out_at' => now()->subHour(),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $completedShift = Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->subHours(9),
            'ends_at' => now()->subHours(1),
            'actual_starts_at' => now()->subHours(9),
            'actual_ends_at' => now()->subHours(1),
            'status' => 'completed',
            'created_by' => $staff->id,
        ]);

        $service = app(ShiftOrphanDetectionService::class);

        $this->assertTrue($service->timesheetsWithoutValidShiftOrAttendance()->contains(fn (Timesheet $candidate) => $candidate->id === $timesheet->id));
        $this->assertTrue($service->attendanceWithoutTimesheet()->contains(fn (HrAttendanceSession $candidate) => $candidate->id === $attendance->id));
        $this->assertTrue($service->completedShiftsWithoutTimesheets()->contains(fn (Shift $candidate) => $candidate->id === $completedShift->id));
    }
}
