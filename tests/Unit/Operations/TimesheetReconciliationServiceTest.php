<?php

namespace Tests\Unit\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\TimesheetReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_severe_duration_mismatch_is_detected_and_persisted(): void
    {
        $service = app(TimesheetReconciliationService::class);
        [$timesheet] = $this->makeTimesheetWithAttendance(
            attendanceEnd: now()->setTime(15, 0),
            timesheetEnd: now()->setTime(17, 0),
        );

        $result = $service->reconcile($timesheet);

        $this->assertSame(TimesheetReconciliationService::STATUS_BLOCKED, $result['status']);
        $this->assertSame(TimesheetReconciliationService::SEVERITY_HIGH, $result['severity']);

        $timesheet->refresh();
        $this->assertSame('blocked', $timesheet->reconciliation_status);
        $this->assertContains(
            'attendance_vs_timesheet_duration_mismatch',
            collect($timesheet->reconciliation_findings)->pluck('type')->all(),
        );
    }

    public function test_attendance_without_timesheet_is_discoverable(): void
    {
        $staff = User::factory()->create();
        $session = HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'clock_in_at' => now()->subHours(6),
            'clock_out_at' => now()->subHours(1),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $sessions = app(TimesheetReconciliationService::class)->attendanceWithoutTimesheets();

        $this->assertTrue($sessions->contains(fn (HrAttendanceSession $candidate) => $candidate->id === $session->id));
    }

    public function test_completed_shift_without_timesheet_is_discoverable(): void
    {
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $staff = User::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHour(),
            'status' => 'completed',
            'created_by' => $staff->id,
        ]);

        $shifts = app(TimesheetReconciliationService::class)->completedShiftsWithoutTimesheets();

        $this->assertTrue($shifts->contains(fn (Shift $candidate) => $candidate->id === $shift->id));
    }

    public function test_ambiguous_attendance_match_is_handled_conservatively(): void
    {
        $service = app(TimesheetReconciliationService::class);
        [$timesheet, $shift, $staff] = $this->makeTimesheetWithAttendance(
            attendanceEnd: now()->setTime(12, 0),
            attachAttendanceToTimesheet: false,
        );

        HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'clock_in_at' => now()->setTime(13, 0),
            'clock_out_at' => now()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $result = $service->evaluate($timesheet->fresh());

        $this->assertSame(TimesheetReconciliationService::STATUS_BLOCKED, $result['status']);
        $this->assertContains(
            'ambiguous_attendance_match',
            collect($result['findings'])->pluck('type')->all(),
        );
    }

    public function test_tolerances_do_not_raise_false_positive_findings(): void
    {
        $service = app(TimesheetReconciliationService::class);
        [$timesheet] = $this->makeTimesheetWithAttendance(
            attendanceEnd: now()->setTime(16, 55),
            timesheetEnd: now()->setTime(17, 0),
        );

        $result = $service->evaluate($timesheet);

        $this->assertSame(TimesheetReconciliationService::STATUS_CLEAR, $result['status']);
        $this->assertSame(TimesheetReconciliationService::SEVERITY_NONE, $result['severity']);
        $this->assertSame([], $result['findings']);
    }

    public function test_reconciliation_review_queries_return_persisted_findings(): void
    {
        $service = app(TimesheetReconciliationService::class);
        [$timesheet] = $this->makeTimesheetWithAttendance(
            attendanceEnd: now()->setTime(16, 40),
            timesheetEnd: now()->setTime(17, 0),
        );

        $service->reconcile($timesheet);

        $reviewTimesheets = $service->timesheetsNeedingReconciliationReview();

        $this->assertTrue($reviewTimesheets->contains(fn (Timesheet $candidate) => $candidate->id === $timesheet->id));

        $timesheet->refresh();
        $this->assertSame('review', $timesheet->reconciliation_status);
        $this->assertNotEmpty($timesheet->reconciliation_findings);
        $this->assertNotNull($timesheet->reconciliation_detected_at);
    }

    /**
     * @return array{0: Timesheet, 1: Shift, 2: User}
     */
    protected function makeTimesheetWithAttendance(
        ?\Carbon\Carbon $attendanceStart = null,
        ?\Carbon\Carbon $attendanceEnd = null,
        ?\Carbon\Carbon $timesheetStart = null,
        ?\Carbon\Carbon $timesheetEnd = null,
        bool $attachAttendanceToTimesheet = true,
    ): array {
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $staff = User::factory()->create();
        $attendanceStart = $attendanceStart ?: now()->setTime(9, 0);
        $attendanceEnd = $attendanceEnd ?: now()->setTime(17, 0);
        $timesheetStart = $timesheetStart ?: now()->setTime(9, 0);
        $timesheetEnd = $timesheetEnd ?: now()->setTime(17, 0);

        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'actual_starts_at' => $attendanceStart,
            'actual_ends_at' => $attendanceEnd,
            'status' => 'completed',
            'created_by' => $staff->id,
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'clock_in_at' => $attendanceStart,
            'clock_out_at' => $attendanceEnd,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $timesheet = Timesheet::query()->create([
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attachAttendanceToTimesheet ? $attendance->id : null,
            'work_date' => now()->toDateString(),
            'starts_at' => $timesheetStart,
            'ends_at' => $timesheetEnd,
            'break_minutes' => 0,
            'status' => 'draft',
            'created_by' => $staff->id,
        ]);

        return [$timesheet, $shift, $staff];
    }
}
