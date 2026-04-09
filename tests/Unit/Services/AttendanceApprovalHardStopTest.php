<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\TimesheetReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceApprovalHardStopTest extends TestCase
{
    use RefreshDatabase;

    protected TimesheetReconciliationService $reconciliation;
    protected Site $site;
    protected Client $client;
    protected ServiceContext $serviceContext;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciliation = app(TimesheetReconciliationService::class);
        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staff = User::factory()->create(['approved_at' => now()]);
    }

    // ── Completed shift + no attendance → blocked ───────────────────────

    public function test_completed_shift_without_attendance_blocks_approval(): void
    {
        $shift = $this->makeCompletedShift();
        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        // No attendance sessions exist.

        $result = $this->reconciliation->reconcile($timesheet);

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('high', $result['severity']);

        // The finding should be attendance_missing with HIGH severity.
        $finding = collect($result['findings'])->firstWhere('type', 'attendance_missing');
        $this->assertNotNull($finding);
        $this->assertEquals('high', $finding['severity']);
        $this->assertStringContainsString('no attendance evidence', $finding['message']);
    }

    public function test_completed_shift_without_attendance_blocks_workflow(): void
    {
        $shift = $this->makeCompletedShift();
        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be approved');

        $this->reconciliation->assertWorkflowAllowed($timesheet, 'approved');
    }

    // ── Completed shift + valid attendance → allowed ────────────────────

    public function test_completed_shift_with_valid_attendance_allows_approval(): void
    {
        $shift = $this->makeCompletedShift();
        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        // Create valid attendance session matching shift times.
        HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => $shift->actual_ends_at,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $result = $this->reconciliation->reconcile($timesheet);

        // Should not be blocked (may have medium findings for other reasons, but not attendance_missing).
        $this->assertNotEquals('blocked', $result['status']);

        $missingFinding = collect($result['findings'])->firstWhere('type', 'attendance_missing');
        $this->assertNull($missingFinding, 'Should not have attendance_missing finding when attendance exists');
    }

    // ── Completed shift + major duration mismatch → blocked ─────────────

    public function test_major_attendance_vs_timesheet_mismatch_blocks_approval(): void
    {
        $shiftStart = now()->subHours(8)->startOfMinute();
        $shiftEnd = now()->subHours(4)->startOfMinute();

        $shift = $this->makeCompletedShift([
            'starts_at' => $shiftStart,
            'ends_at' => $shiftEnd,
            'actual_starts_at' => $shiftStart,
            'actual_ends_at' => $shiftEnd,
        ]);

        // Timesheet claims 8 hours but attendance shows only 4.
        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => $shiftStart->copy()->subHours(4),
            'ends_at' => $shiftEnd,
            'status' => 'submitted',
            'submitted_at' => now(),
            'break_minutes' => 0,
        ]);

        HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => $shiftStart,
            'clock_out_at' => $shiftEnd,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $result = $this->reconciliation->reconcile($timesheet);

        $this->assertEquals('blocked', $result['status']);

        $mismatchFinding = collect($result['findings'])->firstWhere('type', 'attendance_vs_timesheet_duration_mismatch');
        $this->assertNotNull($mismatchFinding);
        $this->assertEquals('high', $mismatchFinding['severity']);
        $this->assertStringContainsString('materially inconsistent', $mismatchFinding['message']);
    }

    // ── Draft timesheet still creatable with missing attendance ──────────

    public function test_draft_timesheet_not_blocked_by_missing_attendance(): void
    {
        $shift = $this->makeCompletedShift();
        $timesheet = $this->makeTimesheet($shift, ['status' => 'draft']);

        // No attendance. Draft should still reconcile (may flag findings but not crash).
        $result = $this->reconciliation->reconcile($timesheet);

        // Draft timesheet should still exist and be processable.
        $this->assertEquals('draft', $timesheet->fresh()->status);

        // The finding exists but draft creation is not blocked by assertWorkflowAllowed.
        // assertWorkflowAllowed is only called on submit/approve, not on create.
        $this->assertNotNull($result);
    }

    // ── Uncompleted shift does not trigger same hard-stop ────────────────

    public function test_in_progress_shift_without_attendance_uses_medium_severity(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(4),
            'started_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ]);

        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        $result = $this->reconciliation->reconcile($timesheet);

        $missingFinding = collect($result['findings'])->firstWhere('type', 'attendance_missing');
        $this->assertNotNull($missingFinding);
        // In-progress should be MEDIUM, not HIGH.
        $this->assertEquals('medium', $missingFinding['severity']);
    }

    public function test_scheduled_shift_without_attendance_no_finding(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'status' => 'scheduled',
            'created_by' => $this->staff->id,
        ]);

        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        $result = $this->reconciliation->reconcile($timesheet);

        $missingFinding = collect($result['findings'])->firstWhere('type', 'attendance_missing');
        // Scheduled shift should NOT expect attendance evidence.
        $this->assertNull($missingFinding);
    }

    // ── Existing protections still work ──────────────────────────────────

    public function test_cancelled_shift_still_blocks_approval(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'status' => 'cancelled',
            'created_by' => $this->staff->id,
        ]);

        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        $result = $this->reconciliation->reconcile($timesheet);

        $this->assertEquals('blocked', $result['status']);

        $cancelledFinding = collect($result['findings'])->firstWhere('type', 'shift_cancelled');
        $this->assertNotNull($cancelledFinding);
        $this->assertEquals('high', $cancelledFinding['severity']);
    }

    public function test_incomplete_attendance_session_still_blocks(): void
    {
        $shift = $this->makeCompletedShift();
        $timesheet = $this->makeTimesheet($shift, ['status' => 'submitted', 'submitted_at' => now()]);

        // Open attendance session (no clock-out).
        $session = HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => null,
            'break_minutes' => 0,
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $this->staff->id,
        ]);

        // Link the session to the timesheet.
        $timesheet->update(['attendance_session_id' => $session->id]);

        $result = $this->reconciliation->reconcile($timesheet);

        $this->assertEquals('blocked', $result['status']);

        $incompleteFinding = collect($result['findings'])->firstWhere('type', 'attendance_incomplete');
        $this->assertNotNull($incompleteFinding);
        $this->assertEquals('high', $incompleteFinding['severity']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function makeCompletedShift(array $overrides = []): Shift
    {
        $start = now()->subHours(8)->startOfMinute();
        $end = now()->subHours(4)->startOfMinute();

        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_starts_at' => $start,
            'actual_ends_at' => $end,
            'status' => 'completed',
            'started_by' => $this->staff->id,
            'completed_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ], $overrides));
    }

    protected function makeTimesheet(Shift $shift, array $overrides = []): Timesheet
    {
        // Extract target status — create as draft first to avoid model event
        // firing reconciliation on create (which would block submitted timesheets
        // linked to completed shifts without attendance).
        $targetStatus = $overrides['status'] ?? 'draft';
        $submittedAt = $overrides['submitted_at'] ?? null;
        $approvedAt = $overrides['approved_at'] ?? null;
        $approvedBy = $overrides['approved_by'] ?? null;
        unset($overrides['status'], $overrides['submitted_at'], $overrides['approved_at'], $overrides['approved_by']);

        $timesheet = Timesheet::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
            'client_id' => $shift->client_id,
            'work_date' => ($shift->actual_starts_at ?? $shift->starts_at)?->toDateString(),
            'starts_at' => $shift->actual_starts_at ?? $shift->starts_at,
            'ends_at' => $shift->actual_ends_at ?? $shift->ends_at,
            'break_minutes' => $shift->expected_break_minutes ?? 0,
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ], $overrides));

        // Transition to target status quietly (bypasses model events).
        if ($targetStatus !== 'draft') {
            $timesheet->forceFill(array_filter([
                'status' => $targetStatus,
                'submitted_at' => $submittedAt ?? ($targetStatus !== 'draft' ? now() : null),
                'approved_at' => $approvedAt,
                'approved_by' => $approvedBy,
            ]))->saveQuietly();
        }

        return $timesheet->fresh();
    }
}
