<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\TimesheetReconciliationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportRole = Role::where('name', 'support_worker')->firstOrFail();
        $this->staff->roles()->attach($supportRole);

        $submitPermissionId = Permission::query()
            ->where('key', 'timesheets.submit')
            ->value('id');
        if ($submitPermissionId) {
            $supportRole->permissions()->syncWithoutDetaching([$submitPermissionId]);
        }

        $approvePermissionId = Permission::query()
            ->where('key', 'timesheets.approve')
            ->value('id');
        if ($approvePermissionId) {
            $this->admin->permissionOverrides()->syncWithoutDetaching([
                $approvePermissionId => ['allowed' => true],
            ]);
        }

        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        foreach ([$this->admin, $this->staff] as $user) {
            HrEmployeeProfile::query()->create([
                'user_id' => $user->id,
                'employee_number' => 'EMP-REC-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    public function test_submit_is_blocked_and_reconciliation_is_persisted_for_severe_duration_mismatch(): void
    {
        [$shift, $attendance] = $this->makeShiftWithAttendance(
            clockIn: now()->setTime(9, 0),
            clockOut: now()->setTime(15, 0),
            shiftStatus: 'completed',
        );

        $timesheet = Timesheet::query()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ]);

        $editUrl = route('operations.timesheets.edit', $timesheet);

        $this->from($editUrl)
            ->actingAs($this->staff)
            ->post(route('operations.timesheets.submit', $timesheet))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['timesheet']);

        $timesheet->refresh();

        $this->assertSame('draft', $timesheet->status);
        $this->assertSame('blocked', $timesheet->reconciliation_status);
        $this->assertSame('high', $timesheet->reconciliation_severity);
        $this->assertNotNull($timesheet->reconciliation_detected_at);
        $this->assertStringContainsString('materially differs', (string) $timesheet->reconciliation_summary);
        $this->assertContains(
            'attendance_vs_timesheet_duration_mismatch',
            collect($timesheet->reconciliation_findings)->pluck('type')->all(),
        );
    }

    public function test_missing_clock_out_blocks_submission(): void
    {
        [$shift] = $this->makeShiftWithAttendance(
            clockIn: now()->setTime(9, 0),
            clockOut: null,
            shiftStatus: 'in_progress',
        );

        $attendance = HrAttendanceSession::query()->where('shift_id', $shift->id)->firstOrFail();

        $timesheet = Timesheet::query()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ]);

        $editUrl = route('operations.timesheets.edit', $timesheet);

        $this->from($editUrl)
            ->actingAs($this->staff)
            ->post(route('operations.timesheets.submit', $timesheet))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['timesheet']);

        $timesheet->refresh();

        $this->assertSame('blocked', $timesheet->reconciliation_status);
        $this->assertContains(
            'attendance_incomplete',
            collect($timesheet->reconciliation_findings)->pluck('type')->all(),
        );
    }

    public function test_approve_is_blocked_when_submitted_timesheet_has_severe_reconciliation_issue(): void
    {
        [$shift, $attendance] = $this->makeShiftWithAttendance(
            clockIn: now()->setTime(9, 0),
            clockOut: now()->setTime(17, 0),
            shiftStatus: 'completed',
        );

        $timesheet = Timesheet::query()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ]);

        $attendance->forceFill([
            'clock_out_at' => null,
            'status' => 'open',
        ])->save();

        app(TimesheetReconciliationService::class)->reconcile($timesheet->fresh(), $attendance->fresh());

        $editUrl = route('operations.timesheets.edit', $timesheet);

        $this->from($editUrl)
            ->actingAs($this->admin)
            ->post(route('operations.timesheets.approve', $timesheet))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['timesheet']);

        $timesheet->refresh();

        $this->assertSame('submitted', $timesheet->status);
        $this->assertNull($timesheet->approved_at);
        $this->assertSame('blocked', $timesheet->reconciliation_status);
    }

    public function test_attendance_sync_does_not_mutate_approved_timesheet_operational_fields(): void
    {
        $this->travelTo(now()->setTime(17, 0));

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'actual_starts_at' => now()->setTime(9, 0),
            'actual_ends_at' => now()->setTime(17, 0),
            'status' => 'completed',
            'created_by' => $this->staff->id,
        ]);

        $timesheet = Timesheet::query()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ]);

        $timesheet->forceFill([
            'status' => 'approved',
            'submitted_at' => now()->subHour(),
            'submitted_by' => $this->staff->id,
            'approved_at' => now(),
            'approved_by' => $this->admin->id,
        ])->saveQuietly();

        $timesheet->refresh();

        $original = $timesheet->only([
            'starts_at',
            'ends_at',
            'break_minutes',
            'attendance_session_id',
            'status',
        ]);

        $session = HrAttendanceSession::query()->create([
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => now()->setTime(9, 0),
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->post('/attendance/clock-out', [
                'session_id' => $session->id,
                'break_minutes' => 0,
                'force' => true,
                'override_reason' => 'Reconciliation sync regression test.',
            ])
            ->assertSessionHas('success');

        $timesheet->refresh();

        $this->assertSame($original['status'], $timesheet->status);
        $this->assertSame($original['attendance_session_id'], $timesheet->attendance_session_id);
        $this->assertTrue($timesheet->starts_at->equalTo($original['starts_at']));
        $this->assertTrue($timesheet->ends_at->equalTo($original['ends_at']));
        $this->assertSame($original['break_minutes'], $timesheet->break_minutes);
        $this->assertSame('clear', $timesheet->reconciliation_status);
        $this->assertSame('none', $timesheet->reconciliation_severity);
    }

    /**
     * @return array{0: Shift, 1: HrAttendanceSession}
     */
    protected function makeShiftWithAttendance(?Carbon $clockIn, ?Carbon $clockOut, string $shiftStatus): array
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'actual_starts_at' => $clockIn,
            'actual_ends_at' => $clockOut,
            'status' => $shiftStatus,
            'created_by' => $this->staff->id,
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'break_minutes' => 0,
            'status' => $clockOut ? 'closed' : 'open',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $clockOut ? $this->staff->id : null,
        ]);

        return [$shift, $attendance];
    }
}
