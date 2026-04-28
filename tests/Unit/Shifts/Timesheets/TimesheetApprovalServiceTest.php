<?php

namespace Tests\Unit\Shifts\Timesheets;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class TimesheetApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $approver;

    protected User $staff;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));

        $this->site = Site::factory()->create([
            'name' => 'Matai House',
        ]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->approver = User::factory()->create([
            'role' => 'finance',
            'approved_at' => now(),
        ]);

        foreach ([$this->staff, $this->approver] as $user) {
            HrEmployeeProfile::query()->create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'employee_number' => 'EMP-SVC-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    public function test_approve_transitions_submitted_timesheet_and_runs_approval_side_effects(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $this->mockApprovalSideEffects(1);

        $result = app(TimesheetApprovalService::class)
            ->approve($timesheet, $this->approver, 'Looks correct.');

        $fresh = $timesheet->fresh();

        $this->assertTrue($result->changed);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->approver->id, $fresh->approved_by);
        $this->assertSame('Looks correct.', $fresh->decision_notes);
        $this->assertSame($this->client->first_name.' '.$this->client->last_name, $fresh->client_name_snapshot);
        $this->assertSame($this->staff->name, $fresh->staff_name_snapshot);
        $this->assertSame('standard', $fresh->shift_type_snapshot);
    }

    public function test_approve_is_idempotent_for_already_approved_timesheets(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheet->forceFill([
            'status' => 'approved',
            'approved_by' => $this->approver->id,
            'approved_at' => now()->subMinute(),
            'decision_notes' => 'Original decision.',
        ])->saveQuietly();

        $this->mockApprovalSideEffects(0);

        $result = app(TimesheetApprovalService::class)
            ->approve($timesheet, $this->approver, 'New decision ignored.');

        $fresh = $timesheet->fresh();

        $this->assertFalse($result->changed);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('Original decision.', $fresh->decision_notes);
    }

    public function test_return_for_changes_clears_prior_decision_fields(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff, [
            'approved_by' => $this->approver->id,
            'approved_at' => now()->subHour(),
            'decision_notes' => 'Earlier review.',
        ]);

        $result = app(TimesheetApprovalService::class)
            ->returnForChanges($timesheet, $this->approver, 'Please confirm mileage.');

        $fresh = $timesheet->fresh();

        $this->assertTrue($result->changed);
        $this->assertSame('returned', $fresh->status);
        $this->assertSame($this->approver->id, $fresh->returned_by);
        $this->assertSame('Please confirm mileage.', $fresh->returned_notes);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->decision_notes);
    }

    public function test_submit_clears_prior_return_fields(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff, [
            'status' => 'returned',
            'returned_by' => $this->approver->id,
            'returned_at' => now()->subHour(),
            'returned_notes' => 'Fix break minutes.',
        ]);

        $result = app(TimesheetApprovalService::class)
            ->submit($timesheet, $this->staff);

        $fresh = $timesheet->fresh();

        $this->assertTrue($result->changed);
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame($this->staff->id, $fresh->submitted_by);
        $this->assertNull($fresh->returned_by);
        $this->assertNull($fresh->returned_at);
        $this->assertNull($fresh->returned_notes);
    }

    protected function mockApprovalSideEffects(int $times): void
    {
        $this->mock(TimesheetHrSyncService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('syncToHr')
                ->times($times)
                ->with(Mockery::type(Timesheet::class));
        });

        $this->mock(BillingService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('generateFromTimesheet')
                ->times($times)
                ->with(Mockery::type(Timesheet::class))
                ->andReturnNull();
        });
    }

    protected function makeDraftTimesheet(User $staff, array $overrides = []): Timesheet
    {
        $shift = $this->makeCompletedShiftWithAttendance($staff)[0];

        return Timesheet::query()->create(array_merge([
            'user_id' => $staff->id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'shift_site_id' => $shift->site_id,
            'shift_service_context_id' => $shift->service_context_id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 0,
            'notes' => 'Draft notes',
            'status' => 'draft',
            'created_by' => $staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => $this->serviceContext->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], $overrides));
    }

    protected function makeSubmittedTimesheet(User $staff, array $overrides = []): Timesheet
    {
        [$shift, $attendance] = $this->makeCompletedShiftWithAttendance($staff);

        return Timesheet::query()->create(array_merge([
            'user_id' => $staff->id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'shift_site_id' => $shift->site_id,
            'shift_service_context_id' => $shift->service_context_id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 0,
            'notes' => 'Submitted notes',
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'submitted_by' => $staff->id,
            'created_by' => $staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => $this->serviceContext->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], $overrides));
    }

    /**
     * @return array{0: Shift, 1: HrAttendanceSession}
     */
    protected function makeCompletedShiftWithAttendance(User $staff): array
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'actual_ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'expected_break_minutes' => 0,
            'status' => 'completed',
            'created_by' => $staff->id,
            'started_by' => $staff->id,
            'completed_by' => $staff->id,
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => $shift->actual_ends_at,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        return [$shift, $attendance];
    }
}
