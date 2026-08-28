<?php

namespace Tests\Unit\Shifts\Timesheets;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AlternativeHolidayService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        $approvePermission = Permission::query()->firstOrCreate(
            ['key' => 'timesheets.approve'],
            [
                'description' => 'Approve/reject timesheets',
                'group' => 'timesheets',
                'module' => 'Operations',
            ],
        );
        $this->approver->permissionOverrides()->syncWithoutDetaching([
            $approvePermission->id => ['allowed' => true],
        ]);

        $writerPermissions = collect(['timesheets.update', 'timesheets.submit'])
            ->map(fn (string $key): Permission => Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'description' => 'Timesheet workflow test permission',
                    'group' => 'timesheets',
                    'module' => 'Operations',
                ],
            ));
        $this->staff->permissionOverrides()->syncWithoutDetaching(
            $writerPermissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ])->all(),
        );
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

    public function test_review_service_rejects_a_current_site_actor_without_review_permission_and_writes_nothing(): void
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $before = $timesheet->fresh()->getRawOriginal();
        $this->mockApprovalSideEffects(0);

        try {
            app(TimesheetApprovalService::class)->approve($timesheet, $actor, 'Unauthorized review.');
            $this->fail('The native approval service must require current review permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
    }

    public function test_review_leaves_and_bulk_wrappers_reject_a_foreign_site_actor_without_writes(): void
    {
        $foreignSite = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);
        $approvePermission = Permission::query()->where('key', 'timesheets.approve')->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $approvePermission->id => ['allowed' => true],
        ]);
        $this->mockApprovalSideEffects(0);
        $service = app(TimesheetApprovalService::class);

        foreach (['approve', 'return', 'reject', 'bulkApprove', 'bulkReturn', 'bulkReject'] as $offset => $command) {
            $timesheet = $this->makeSubmittedTimesheet($this->staff, [], $offset);
            $before = $timesheet->fresh()->getRawOriginal();

            try {
                match ($command) {
                    'approve' => $service->approve($timesheet, $actor, 'Foreign review.'),
                    'return' => $service->returnForChanges($timesheet, $actor, 'Foreign review.'),
                    'reject' => $service->reject($timesheet, $actor, 'Foreign review.'),
                    'bulkApprove' => $service->bulkApprove(new Collection([$timesheet]), $actor, 'Foreign review.'),
                    'bulkReturn' => $service->bulkReturn(new Collection([$timesheet]), $actor, 'Foreign review.'),
                    'bulkReject' => $service->bulkReject(new Collection([$timesheet]), $actor, 'Foreign review.'),
                };
                $this->fail("{$command} must enforce the actor's current Site inside the service transaction.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
        }
    }

    public function test_approve_fails_closed_without_the_application_payroll_mutex(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheetBefore = $timesheet->fresh()->getRawOriginal();
        $auditsBefore = AuditLog::query()
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $audit): array => $audit->getRawOriginal())
            ->all();
        $this->mockApprovalSideEffects(0);
        $this->mock(AlternativeHolidayService::class, function ($mock): void {
            $mock->shouldNotReceive('accrueForTimesheet');
        });

        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->first();
        $this->assertNotNull($mutex);
        DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->delete();

        try {
            try {
                app(TimesheetApprovalService::class)
                    ->approve($timesheet, $this->approver, 'Must not persist.');
                $this->fail('Approval must fail when the application payroll mutex is missing.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'The application payroll mutex is missing; migration repair is required.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
            $this->assertSame(
                $auditsBefore,
                AuditLog::query()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AuditLog $audit): array => $audit->getRawOriginal())
                    ->all(),
            );
        } finally {
            DB::table('hr_payroll_run_mutexes')->updateOrInsert(
                ['key' => 'application'],
                [
                    'created_at' => $mutex->created_at,
                    'updated_at' => $mutex->updated_at,
                ],
            );
        }
    }

    public function test_approve_blocks_locked_payroll_period_without_legacy_organisation_match_and_locks_mutex_first(): void
    {
        $staffWithoutLegacyOrganisation = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'organization_id' => null,
        ]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 777,
            'user_id' => $staffWithoutLegacyOrganisation->id,
            'employee_number' => 'EMP-NO-ORG-'.$staffWithoutLegacyOrganisation->id,
            'work_email' => $staffWithoutLegacyOrganisation->email,
            'position_title' => 'Operations',
            'position_role' => $staffWithoutLegacyOrganisation->role,
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $mismatchedStaff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'organization_id' => 778,
        ]);
        $mismatchedProfile = HrEmployeeProfile::factory()->create([
            'tenant_id' => 777,
            'user_id' => $mismatchedStaff->id,
            'employee_number' => 'EMP-MISMATCH-'.$mismatchedStaff->id,
            'work_email' => $mismatchedStaff->email,
            'position_title' => 'Operations',
            'position_role' => $mismatchedStaff->role,
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $this->assertSame(777, (int) $mismatchedProfile->getRawOriginal('tenant_id'));
        $timesheets = [
            $this->makeSubmittedTimesheet($staffWithoutLegacyOrganisation),
            $this->makeSubmittedTimesheet($mismatchedStaff),
        ];
        HrPayrollRun::factory()->create([
            'tenant_id' => 999,
            'period_start' => $timesheets[0]->work_date,
            'period_end' => $timesheets[0]->work_date,
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $this->approver->id,
            'created_by' => $this->approver->id,
        ]);
        $this->mockApprovalSideEffects(0);

        foreach ($timesheets as $timesheet) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                app(TimesheetApprovalService::class)
                    ->approve($timesheet, $this->approver, 'Must remain frozen.');
                $this->fail('Approval must reject every locked run in the single organisation.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString(
                    'locked by a payroll run',
                    collect($exception->errors())->flatten()->implode(' '),
                );
            } finally {
                $queries = collect(DB::getQueryLog())->pluck('query')->values();
                DB::disableQueryLog();
            }

            $mutexIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_payroll_run_mutexes'));
            $timesheetIndex = $queries->search(fn (string $query): bool => str_contains($query, 'from `timesheets`'));
            $payrollRunIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_payroll_runs'));

            $this->assertIsInt($mutexIndex);
            $this->assertIsInt($timesheetIndex);
            $this->assertIsInt($payrollRunIndex);
            $this->assertTrue($mutexIndex < $timesheetIndex);
            $this->assertTrue($timesheetIndex < $payrollRunIndex);
            $this->assertStringContainsString('for update', strtolower($queries[$mutexIndex]));
            $this->assertStringContainsString('for update', strtolower($queries[$timesheetIndex]));
            $this->assertSame('submitted', $timesheet->fresh()->status);
        }
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

    public function test_editable_update_rechecks_the_locked_workflow_state_after_a_stale_read(): void
    {
        $staleTimesheet = $this->makeDraftTimesheet($this->staff);

        DB::table('timesheets')
            ->where('id', $staleTimesheet->id)
            ->update([
                'status' => 'submitted',
                'submitted_by' => $this->staff->id,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            app(TimesheetApprovalService::class)->updateEditable($staleTimesheet, $this->staff, [
                'notes' => 'A stale draft update must not overwrite submission.',
            ]);
            $this->fail('Expected the locked workflow state to reject the stale update.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timesheet', $exception->errors());
        }

        $fresh = $staleTimesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame('Draft notes', $fresh->notes);
        $this->assertSame($this->staff->id, $fresh->submitted_by);
    }

    public function test_editable_update_locks_and_rejects_a_foreign_new_client_site_without_writes(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff);
        $timesheet->forceFill([
            'shift_id' => null,
            'attendance_session_id' => null,
            'site_id' => $this->site->id,
            'shift_site_id' => $this->site->id,
        ])->saveQuietly();
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $before = $timesheet->fresh()->getRawOriginal();
        $auditCount = AuditLog::query()->count();

        try {
            app(TimesheetApprovalService::class)->updateEditable($timesheet, $this->staff, [
                'client_id' => $foreignClient->id,
                'site_id' => $foreignSite->id,
                'shift_site_id' => $foreignSite->id,
                'notes' => 'Must not cross the locked Site boundary.',
            ]);
            $this->fail('A new foreign Client/Site must be rejected inside the writer transaction.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertFalse($timesheet->clientAllocations()->exists());
    }

    public function test_attendance_backed_timesheet_rejects_generic_update_and_resubmit_without_changing_evidence(): void
    {
        foreach (['update', 'resubmit'] as $dayOffset => $command) {
            $timesheet = $this->makeDraftTimesheet($this->staff, [], $dayOffset);
            $attendance = HrAttendanceSession::query()
                ->where('shift_id', $timesheet->shift_id)
                ->where('user_id', $this->staff->id)
                ->sole();
            $entry = HrTimeEntry::query()->create([
                'tenant_id' => 1,
                'user_id' => $this->staff->id,
                'shift_id' => $timesheet->shift_id,
                'attendance_session_id' => $attendance->id,
                'site_id' => $this->site->id,
                'client_id' => $this->client->id,
                'entry_date' => $timesheet->work_date,
                'clock_in' => $timesheet->starts_at,
                'clock_out' => $timesheet->ends_at,
                'break_minutes' => 0,
                'total_hours' => 8,
                'entry_type' => 'clock',
                'status' => 'submitted',
                'source_type' => 'attendance',
                'source_id' => $attendance->id,
                'created_by' => $this->staff->id,
            ]);
            $timesheet->forceFill([
                'attendance_session_id' => $attendance->id,
                'hr_time_entry_id' => $entry->id,
            ])->saveQuietly();
            $timesheetBefore = $timesheet->fresh()->getRawOriginal();
            $attendanceBefore = $attendance->fresh()->getRawOriginal();
            $entryBefore = $entry->fresh()->getRawOriginal();

            try {
                $service = app(TimesheetApprovalService::class);
                $updates = [
                    'starts_at' => $timesheet->starts_at->copy()->addHour(),
                    'ends_at' => $timesheet->ends_at->copy()->addHour(),
                    'break_minutes' => 30,
                ];
                $command === 'resubmit'
                    ? $service->resubmit($timesheet, $this->staff, $updates)
                    : $service->updateEditable($timesheet, $this->staff, $updates);
                $this->fail("Attendance-backed {$command} must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString(
                    'governed attendance correction workflow',
                    collect($exception->errors())->flatten()->implode(' '),
                );
            }

            $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
            $this->assertSame($attendanceBefore, $attendance->fresh()->getRawOriginal());
            $this->assertSame($entryBefore, $entry->fresh()->getRawOriginal());
        }
    }

    public function test_attendance_backed_approval_fails_closed_when_canonical_projection_is_missing(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheet->forceFill([
            'status' => 'approved',
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ])->saveQuietly();

        try {
            app(TimesheetHrSyncService::class)->syncToHr($timesheet);
            $this->fail('Attendance-backed approval must not create a generic replacement projection.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'exactly one canonical attendance HR time entry',
                collect($exception->errors())->flatten()->implode(' '),
            );
        }

        $this->assertFalse(HrTimeEntry::query()->exists());
        $this->assertNull($timesheet->fresh()->hr_time_entry_id);
    }

    public function test_prepared_hr_sync_cannot_bypass_the_lock_boundary_outside_a_transaction(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheet->forceFill([
            'status' => 'approved',
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ])->saveQuietly();

        $connection = DB::connection();
        $connection->rollBack();

        try {
            try {
                app(TimesheetHrSyncService::class)->syncToHr($timesheet, null, true);
                $this->fail('Prepared HR sync must reject callers outside a transaction.');
            } catch (\LogicException $exception) {
                $this->assertSame(
                    'Prepared Timesheet HR sync requires an active transaction.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $connection->beginTransaction();
        }
    }

    public function test_attendance_backed_approval_rejects_a_generic_wrong_link_without_rewriting_source(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $entry = HrTimeEntry::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'shift_id' => $timesheet->shift_id,
            'attendance_session_id' => $timesheet->attendance_session_id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'entry_date' => $timesheet->work_date,
            'clock_in' => $timesheet->starts_at,
            'clock_out' => $timesheet->ends_at,
            'break_minutes' => 0,
            'total_hours' => 8,
            'entry_type' => 'timesheet',
            'status' => 'submitted',
            'source_type' => 'timesheet',
            'source_id' => $timesheet->id,
            'created_by' => $this->staff->id,
        ]);
        $timesheet->forceFill([
            'status' => 'approved',
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'hr_time_entry_id' => $entry->id,
        ])->saveQuietly();
        $entryBefore = $entry->fresh()->getRawOriginal();

        try {
            app(TimesheetHrSyncService::class)->syncToHr($timesheet);
            $this->fail('A generic source must not stand in for an attendance projection.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'invalid source provenance',
                collect($exception->errors())->flatten()->implode(' '),
            );
        }

        $this->assertSame($entryBefore, $entry->fresh()->getRawOriginal());
        $this->assertSame($entry->id, (int) $timesheet->fresh()->hr_time_entry_id);
    }

    public function test_approval_locks_and_checks_the_linked_hr_entry_old_payroll_date_before_transition(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheet->forceFill(['attendance_session_id' => null])->saveQuietly();
        $entry = HrTimeEntry::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'shift_id' => $timesheet->shift_id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'entry_date' => '2026-04-09',
            'clock_in' => $timesheet->starts_at,
            'clock_out' => $timesheet->ends_at,
            'break_minutes' => 0,
            'total_hours' => 8,
            'entry_type' => 'timesheet',
            'status' => 'submitted',
            'source_type' => 'timesheet',
            'source_id' => $timesheet->id,
            'created_by' => $this->staff->id,
        ]);
        $timesheet->forceFill(['hr_time_entry_id' => $entry->id])->saveQuietly();
        HrPayrollRun::factory()->create([
            'period_start' => '2026-04-09',
            'period_end' => '2026-04-09',
            'status' => 'exported',
            'locked_at' => now(),
            'locked_by' => $this->approver->id,
            'created_by' => $this->approver->id,
        ]);
        $timesheetBefore = $timesheet->fresh()->getRawOriginal();
        $entryBefore = $entry->fresh()->getRawOriginal();
        $this->mock(BillingService::class, fn ($mock) => $mock->shouldNotReceive('generateFromTimesheet'));
        $this->mock(AlternativeHolidayService::class, fn ($mock) => $mock->shouldNotReceive('accrueForTimesheet'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(TimesheetApprovalService::class)->approve($timesheet, $this->approver);
            $this->fail('The linked entry old payroll date must block approval.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('locked by a payroll run', collect($exception->errors())->flatten()->implode(' '));
        } finally {
            $queries = collect(DB::getQueryLog())->pluck('query')->values();
            DB::disableQueryLog();
        }

        $mutexIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_payroll_run_mutexes'));
        $timesheetIndex = $queries->search(fn (string $query): bool => str_contains($query, 'from `timesheets`'));
        $entryIndex = $queries->search(fn (string $query): bool => str_contains($query, 'from `hr_time_entries`'));
        $payrollIndex = $queries->search(fn (string $query): bool => str_contains($query, 'from `hr_payroll_runs`'));
        $this->assertIsInt($mutexIndex);
        $this->assertIsInt($timesheetIndex);
        $this->assertIsInt($entryIndex);
        $this->assertIsInt($payrollIndex);
        $this->assertTrue($mutexIndex < $timesheetIndex);
        $this->assertTrue($timesheetIndex < $entryIndex);
        $this->assertTrue($entryIndex < $payrollIndex);
        $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
        $this->assertSame($entryBefore, $entry->fresh()->getRawOriginal());
    }

    public function test_update_and_resubmit_check_a_linked_hr_entry_old_payroll_date(): void
    {
        foreach (['update', 'resubmit'] as $dayOffset => $command) {
            $oldDate = $command === 'resubmit' ? '2026-04-07' : '2026-04-08';
            $timesheet = $this->makeDraftTimesheet($this->staff, [
                'status' => $command === 'resubmit' ? 'returned' : 'draft',
            ], $dayOffset);
            $entry = HrTimeEntry::query()->create([
                'tenant_id' => 1,
                'user_id' => $this->staff->id,
                'shift_id' => $timesheet->shift_id,
                'site_id' => $this->site->id,
                'client_id' => $this->client->id,
                'entry_date' => $oldDate,
                'clock_in' => $timesheet->starts_at,
                'clock_out' => $timesheet->ends_at,
                'break_minutes' => 0,
                'total_hours' => 8,
                'entry_type' => 'timesheet',
                'status' => 'submitted',
                'source_type' => 'timesheet',
                'source_id' => $timesheet->id,
                'created_by' => $this->staff->id,
            ]);
            $timesheet->forceFill(['hr_time_entry_id' => $entry->id])->saveQuietly();
            HrPayrollRun::factory()->create([
                'period_start' => $oldDate,
                'period_end' => $oldDate,
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => $this->approver->id,
                'created_by' => $this->approver->id,
            ]);
            $before = $timesheet->fresh()->getRawOriginal();

            try {
                $service = app(TimesheetApprovalService::class);
                $updates = [
                    'work_date' => $timesheet->work_date->copy()->addDay()->toDateString(),
                    'notes' => 'Must roll back',
                ];
                $command === 'resubmit'
                    ? $service->resubmit($timesheet, $this->staff, $updates)
                    : $service->updateEditable($timesheet, $this->staff, $updates);
                $this->fail("{$command} must reject the linked entry old locked date.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('locked by a payroll run', collect($exception->errors())->flatten()->implode(' '));
            }

            $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
        }
    }

    public function test_attendance_approval_rejects_interval_and_site_drift_without_rewriting_projection(): void
    {
        foreach (['interval', 'site'] as $dayOffset => $drift) {
            $timesheet = $this->makeSubmittedTimesheet($this->staff, [], $dayOffset);
            $attendance = HrAttendanceSession::query()->findOrFail($timesheet->attendance_session_id);
            $entry = HrTimeEntry::query()->create([
                'tenant_id' => 1,
                'user_id' => $this->staff->id,
                'shift_id' => $timesheet->shift_id,
                'attendance_session_id' => $attendance->id,
                'site_id' => $this->site->id,
                'client_id' => $this->client->id,
                'entry_date' => $timesheet->work_date,
                'clock_in' => $attendance->clock_in_at,
                'clock_out' => $attendance->clock_out_at,
                'break_minutes' => $attendance->break_minutes,
                'total_hours' => 8,
                'entry_type' => 'clock',
                'status' => 'submitted',
                'source_type' => 'attendance',
                'source_id' => $attendance->id,
                'created_by' => $this->staff->id,
            ]);
            $updates = ['hr_time_entry_id' => $entry->id];
            if ($drift === 'interval') {
                $updates['starts_at'] = $timesheet->starts_at->copy()->addMinute();
            } else {
                $updates['shift_site_id'] = Site::factory()->create()->id;
            }
            $timesheet->forceFill($updates)->saveQuietly();
            $timesheetBefore = $timesheet->fresh()->getRawOriginal();
            $entryBefore = $entry->fresh()->getRawOriginal();

            try {
                app(TimesheetApprovalService::class)->approve($timesheet, $this->approver);
                $this->fail("Attendance {$drift} drift must fail closed.");
            } catch (ValidationException $exception) {
                $this->assertSame('interval', $drift);
                $this->assertStringContainsString(
                    'no longer matches its canonical session and projection',
                    collect($exception->errors())->flatten()->implode(' '),
                );
            } catch (HttpException $exception) {
                $this->assertSame('site', $drift);
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertStringContainsString(
                    'not authorized to access records for this site',
                    $exception->getMessage(),
                );
            }

            $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
            $this->assertSame($entryBefore, $entry->fresh()->getRawOriginal());
        }
    }

    public function test_manual_approval_rejects_worker_overlap_and_rolls_back_status_and_hr_sync(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $timesheet->forceFill(['attendance_session_id' => null])->saveQuietly();
        $overlap = HrTimeEntry::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'entry_date' => $timesheet->work_date,
            'clock_in' => $timesheet->starts_at->copy()->addHour(),
            'clock_out' => $timesheet->ends_at->copy()->subHour(),
            'break_minutes' => 0,
            'total_hours' => 6,
            'entry_type' => 'manual',
            'status' => 'submitted',
            'source_type' => 'manual',
            'created_by' => $this->staff->id,
        ]);
        $timesheetBefore = $timesheet->fresh()->getRawOriginal();
        $overlapBefore = $overlap->fresh()->getRawOriginal();
        $this->mock(BillingService::class, fn ($mock) => $mock->shouldNotReceive('generateFromTimesheet'));
        $this->mock(AlternativeHolidayService::class, fn ($mock) => $mock->shouldNotReceive('accrueForTimesheet'));

        try {
            app(TimesheetApprovalService::class)->approve($timesheet, $this->approver);
            $this->fail('Overlapping manual approval must fail closed.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('overlapping time entry', $exception->getMessage());
        }

        $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
        $this->assertSame($overlapBefore, $overlap->fresh()->getRawOriginal());
        $this->assertNull($timesheet->fresh()->hr_time_entry_id);
        $this->assertSame(1, HrTimeEntry::query()->count());
    }

    protected function mockApprovalSideEffects(int $times): void
    {
        $this->mock(TimesheetHrSyncService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('lockCanonicalEntryForMutation')
                ->zeroOrMoreTimes()
                ->andReturnNull();
            $mock->shouldReceive('assertNoWorkerOverlapForMutation')
                ->zeroOrMoreTimes();
            $mock->shouldReceive('syncToHr')
                ->times($times)
                ->with(Mockery::type(Timesheet::class), null, true);
        });

        $this->mock(BillingService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('generateFromTimesheet')
                ->times($times)
                ->with(Mockery::type(Timesheet::class))
                ->andReturn(new Collection);
        });
    }

    protected function makeDraftTimesheet(User $staff, array $overrides = [], int $dayOffset = 0): Timesheet
    {
        $shift = $this->makeCompletedShiftWithAttendance($staff, $dayOffset)[0];

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

    protected function makeSubmittedTimesheet(User $staff, array $overrides = [], int $dayOffset = 0): Timesheet
    {
        [$shift, $attendance] = $this->makeCompletedShiftWithAttendance($staff, $dayOffset);

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
    protected function makeCompletedShiftWithAttendance(User $staff, int $dayOffset = 0): array
    {
        $startsAt = Carbon::parse('2026-04-10 09:00:00')->addDays($dayOffset);
        $endsAt = $startsAt->copy()->addHours(8);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
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
