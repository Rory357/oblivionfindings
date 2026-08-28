<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    Notification::fake();
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'Payroll race Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    ensureCanonicalHrStaffProfile($this->manager, $this->site);
    ensureCanonicalHrStaffProfile($this->worker, $this->site, [
        'manager_user_id' => $this->manager->id,
    ]);

    $this->serviceContext = ServiceContext::factory()->create(['site_id' => $this->site->id]);
    $this->client = Client::factory()->create([
        'site_id' => $this->site->id,
        'service_context_id' => $this->serviceContext->id,
        'status' => 'active',
    ]);

    $manageAny = Permission::query()->where('key', 'timesheets.manageAny')->firstOrFail();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $manageAny->id => ['allowed' => true],
    ]);

    $this->makeEntryEvidence = function (array $overrides = []): array {
        $entry = HrTimeEntry::factory()->create([
            'user_id' => $this->worker->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'shift_id' => null,
            'attendance_session_id' => null,
            'entry_date' => '2026-06-15',
            'clock_in' => '2026-06-14 21:00:00',
            'clock_out' => '2026-06-15 05:00:00',
            'break_minutes' => 30,
            'total_hours' => 7.5,
            'status' => 'submitted',
            ...$overrides,
        ]);
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->worker->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'shift_site_id' => null,
            'hr_time_entry_id' => $entry->id,
            'work_date' => $entry->entry_date,
            'starts_at' => $entry->clock_in,
            'ends_at' => $entry->clock_out ?? '2026-06-15 05:00:00',
            'break_minutes' => (int) $entry->break_minutes,
            'status' => 'draft',
            'payroll_reference' => null,
            'exported_to_payroll_at' => null,
            'payroll_segments_exported' => null,
        ]);

        return [$entry, $timesheet];
    };

    $this->makeShift = function (): Shift {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => '2026-06-14 21:00:00',
            'ends_at' => '2026-06-15 05:00:00',
            'expected_break_minutes' => 30,
            'created_by' => $this->manager->id,
        ]);
    };

    $this->lockPayrollDate = function (string $date, string $status = 'locked'): HrPayrollRun {
        return HrPayrollRun::factory()->create([
            'period_start' => $date,
            'period_end' => $date,
            'status' => $status,
            'locked_at' => now(),
            'locked_by' => $this->manager->id,
            'created_by' => $this->manager->id,
        ]);
    };
});

function assertTimeTrackingLockOrder(Collection $queries, bool $hasCanonicalEntry): void
{
    $mutexIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_payroll_run_mutexes'));
    $timesheetIndex = $queries->search(fn (string $query): bool => str_contains($query, 'timesheets'));
    $entryIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_time_entries'));
    $payrollIndex = $queries->search(fn (string $query): bool => str_contains($query, 'hr_payroll_runs'));

    expect($mutexIndex)->toBeInt()
        ->and($timesheetIndex)->toBeInt()
        ->and($payrollIndex)->toBeInt()
        ->and($mutexIndex)->toBeLessThan($timesheetIndex)
        ->and($timesheetIndex)->toBeLessThan($payrollIndex)
        ->and(strtolower($queries[$mutexIndex]))->toContain('for update')
        ->and(strtolower($queries[$timesheetIndex]))->toContain('for update')
        ->and(strtolower($queries[$payrollIndex]))->toContain('for update');

    if ($hasCanonicalEntry) {
        expect($entryIndex)->toBeInt()
            ->and($timesheetIndex)->toBeLessThan($entryIndex)
            ->and($entryIndex)->toBeLessThan($payrollIndex)
            ->and(strtolower($queries[$entryIndex]))->toContain('for update');
    }
}

test('edit loser rechecks a locked target payroll period and leaves all evidence unchanged in canonical lock order', function () {
    [$entry, $timesheet] = ($this->makeEntryEvidence)();
    $staleEntry = $entry->fresh();
    ($this->lockPayrollDate)('2026-07-15');
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TimeTrackingService::class)->editTimeEntry($staleEntry, $this->manager, [
            'clock_in' => '2026-07-15T09:00',
            'clock_out' => '2026-07-15T17:00',
            'break_minutes' => 30,
        ], 'Losing concurrent edit'))
            ->toThrow(LogicException::class, 'locked payroll period');
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();
    }

    assertTimeTrackingLockOrder($queries, true);
    expect($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(0);
});

test('void loser rechecks the locked Timesheet and leaves entry Timesheet and amendments unchanged', function () {
    [$entry, $timesheet] = ($this->makeEntryEvidence)();
    $staleEntry = $entry->fresh();
    DB::table('timesheets')->where('id', $timesheet->id)->update([
        'payroll_reference' => 'concurrent-payroll-winner',
        'exported_to_payroll_at' => now(),
    ]);
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TimeTrackingService::class)->voidEntry(
            $staleEntry,
            $this->manager,
            'Losing concurrent void',
        ))->toThrow(LogicException::class, 'payroll-linked');
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();
    }

    assertTimeTrackingLockOrder($queries, true);
    expect(HrTimeEntry::withTrashed()->findOrFail($entry->id)->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(0);
});

test('missed-clock correction loser rechecks the payroll period and rolls back every linked record', function () {
    [$entry, $timesheet] = ($this->makeEntryEvidence)([
        'clock_out' => null,
        'break_minutes' => 0,
        'total_hours' => null,
        'status' => 'active',
    ]);
    $staleEntry = $entry->fresh();
    ($this->lockPayrollDate)('2026-06-15', 'exported');
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TimeTrackingService::class)->correctMissedClockOut(
            $staleEntry,
            $this->manager,
            '2026-06-15T17:00',
            30,
            'Losing concurrent correction',
        ))->toThrow(LogicException::class, 'locked payroll period');
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();
    }

    assertTimeTrackingLockOrder($queries, true);
    expect($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(0);
});

test('manual creation locks Timesheet then payroll before insert and leaves no partial evidence in a locked period', function () {
    $shift = ($this->makeShift)();
    ($this->lockPayrollDate)('2026-06-15');
    $entriesBefore = HrTimeEntry::query()->count();
    $timesheetsBefore = Timesheet::query()->count();
    $amendmentsBefore = HrTimeEntryAmendment::query()->count();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TimeTrackingService::class)->createManualEntry($this->manager, [
            'user_id' => $this->worker->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'client_id' => $shift->client_id,
            'clock_in' => '2026-06-15T09:00',
            'clock_out' => '2026-06-15T17:00',
            'break_minutes' => 30,
        ]))->toThrow(LogicException::class, 'locked payroll period');
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();
    }

    assertTimeTrackingLockOrder($queries, true);
    expect(HrTimeEntry::query()->count())->toBe($entriesBefore)
        ->and(Timesheet::query()->count())->toBe($timesheetsBefore)
        ->and(HrTimeEntryAmendment::query()->count())->toBe($amendmentsBefore);
});

test('clock-on-behalf locks Timesheet then payroll before insert and leaves no audit fragment in an exported period', function () {
    $shift = ($this->makeShift)();
    ($this->lockPayrollDate)('2026-06-15', 'exported');
    $entriesBefore = HrTimeEntry::query()->count();
    $timesheetsBefore = Timesheet::query()->count();
    $amendmentsBefore = HrTimeEntryAmendment::query()->count();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(fn () => app(TimeTrackingService::class)->clockOnBehalf($this->manager, $this->worker->id, [
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'client_id' => $shift->client_id,
            'clock_in' => '2026-06-15T09:00',
            'clock_out' => '2026-06-15T17:00',
            'break_minutes' => 30,
            'reason' => 'Manager correction',
        ]))->toThrow(LogicException::class, 'locked payroll period');
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();
    }

    assertTimeTrackingLockOrder($queries, true);
    expect(HrTimeEntry::query()->count())->toBe($entriesBefore)
        ->and(Timesheet::query()->count())->toBe($timesheetsBefore)
        ->and(HrTimeEntryAmendment::query()->count())->toBe($amendmentsBefore);
});

test('clock-on-behalf loser cannot create a second active clock when no Timesheet exists', function () {
    $existing = HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'shift_id' => null,
        'attendance_session_id' => null,
        'entry_date' => '2026-06-15',
        'clock_in' => '2026-06-14 21:00:00',
        'clock_out' => null,
        'break_minutes' => 0,
        'total_hours' => null,
        'status' => 'active',
    ]);
    $entryBefore = $existing->fresh()->getRawOriginal();
    $entriesBefore = HrTimeEntry::query()->count();
    $amendmentsBefore = HrTimeEntryAmendment::query()->count();

    expect(fn () => app(TimeTrackingService::class)->clockOnBehalf($this->manager, $this->worker->id, [
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'clock_in' => '2026-06-15T10:00',
        'reason' => 'Concurrent duplicate clock',
    ]))->toThrow(LogicException::class, 'canonical time entry already exists');

    expect(HrTimeEntry::query()->count())->toBe($entriesBefore)
        ->and($existing->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and(HrTimeEntryAmendment::query()->count())->toBe($amendmentsBefore);
});

test('service rejects reversed clocks and breaks equal to the elapsed interval before any write', function () {
    $entriesBefore = HrTimeEntry::query()->count();

    expect(fn () => app(TimeTrackingService::class)->createManualEntry($this->manager, [
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'clock_in' => '2026-06-15T17:00',
        'clock_out' => '2026-06-15T09:00',
        'break_minutes' => 0,
    ]))->toThrow(LogicException::class, 'clock-out must be after');

    expect(fn () => app(TimeTrackingService::class)->clockOnBehalf($this->manager, $this->worker->id, [
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'clock_in' => '2026-06-15T09:00',
        'clock_out' => '2026-06-15T10:00',
        'break_minutes' => 60,
        'reason' => 'Invalid interval',
    ]))->toThrow(LogicException::class, 'must be less than the session duration');

    expect(HrTimeEntry::query()->count())->toBe($entriesBefore);
});

test('offset timestamps lock the canonical worker-local payroll date for create on-behalf and edit', function () {
    ($this->lockPayrollDate)('2026-06-16');
    $entriesBefore = HrTimeEntry::query()->count();
    $timesheetsBefore = Timesheet::query()->count();
    $offsetInterval = [
        'clock_in' => '2026-06-15T21:00:00Z',
        'clock_out' => '2026-06-15T22:00:00Z',
    ];

    expect(fn () => app(TimeTrackingService::class)->createManualEntry($this->manager, [
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        ...$offsetInterval,
    ]))->toThrow(LogicException::class, 'locked payroll period');

    expect(fn () => app(TimeTrackingService::class)->clockOnBehalf($this->manager, $this->worker->id, [
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'reason' => 'Canonical offset boundary',
        ...$offsetInterval,
    ]))->toThrow(LogicException::class, 'locked payroll period');

    [$entry, $timesheet] = ($this->makeEntryEvidence)();
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();
    expect(fn () => app(TimeTrackingService::class)->editTimeEntry(
        $entry,
        $this->manager,
        $offsetInterval,
        'Canonical offset edit',
    ))->toThrow(LogicException::class, 'locked payroll period');

    expect(HrTimeEntry::query()->count())->toBe($entriesBefore + 1)
        ->and(Timesheet::query()->count())->toBe($timesheetsBefore + 1)
        ->and($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore);
});

test('generic edit and void conceal attendance-backed projections without changing evidence', function () {
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'clock_in_at' => '2026-06-14 21:00:00',
        'clock_out_at' => '2026-06-15 05:00:00',
        'status' => 'closed',
        'source' => 'test',
        'created_by' => $this->worker->id,
    ]);
    [$entry, $timesheet] = ($this->makeEntryEvidence)([
        'attendance_session_id' => $session->id,
        'source_type' => 'attendance',
        'source_id' => $session->id,
    ]);
    $timesheet->update(['attendance_session_id' => $session->id]);
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->editTimeEntry(
        $entry->fresh(),
        $this->manager,
        ['clock_in' => '2026-06-15T09:00', 'clock_out' => '2026-06-15T18:00'],
        'Generic attendance edit',
    ))->toThrow(NotFoundHttpException::class);
    expect(fn () => app(TimeTrackingService::class)->voidEntry(
        $entry->fresh(),
        $this->manager,
        'Generic attendance void',
    ))->toThrow(NotFoundHttpException::class);

    expect($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and($entry->amendments()->count())->toBe(0);
});

test('voiding a mutable manual entry terminalizes and archives its linked timesheet atomically', function () {
    [$entry, $timesheet] = ($this->makeEntryEvidence)();

    app(TimeTrackingService::class)->voidEntry(
        $entry,
        $this->manager,
        'Duplicate manual record',
    );

    $timesheet->refresh();
    expect(HrTimeEntry::withTrashed()->findOrFail($entry->id)->status)->toBe('voided')
        ->and($timesheet->status)->toBe('voided')
        ->and($timesheet->archived_at)->not->toBeNull()
        ->and($timesheet->archived_reason)->toBe('Duplicate manual record')
        ->and((int) $timesheet->hr_time_entry_id)->toBe($entry->id);
});

test('void reason length is bounded before linked payroll evidence can be mutated', function () {
    [$entry, $timesheet] = ($this->makeEntryEvidence)();
    $entryBefore = $entry->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->voidEntry(
        $entry,
        $this->manager,
        str_repeat('x', 256),
    ))->toThrow(LogicException::class, 'no more than 255 characters');

    expect($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and($entry->amendments()->count())->toBe(0);
});

test('edit distinguishes omitted and null clock out and submits an entry when it becomes closed', function () {
    [$closed] = ($this->makeEntryEvidence)();
    $closedBefore = $closed->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->editTimeEntry(
        $closed,
        $this->manager,
        ['clock_in' => '2026-06-15T09:00', 'clock_out' => null],
        'Invalid reopen',
    ))->toThrow(LogicException::class, 'cannot be reopened');
    expect($closed->fresh()->getRawOriginal())->toBe($closedBefore);

    [$open, $timesheet] = ($this->makeEntryEvidence)([
        'entry_date' => '2026-06-14',
        'clock_in' => '2026-06-13 21:00:00',
        'clock_out' => null,
        'total_hours' => 99,
        'break_compliance_met' => true,
        'status' => 'active',
    ]);
    $timesheet->delete();
    $updated = app(TimeTrackingService::class)->editTimeEntry(
        $open,
        $this->manager,
        ['clock_in' => '2026-06-14T09:00', 'clock_out' => '2026-06-14T17:00', 'break_minutes' => 30],
        'Confirmed finish',
    );

    expect($updated->status)->toBe('submitted')
        ->and((float) $updated->total_hours)->toBe(7.5)
        ->and(Timesheet::query()->where('hr_time_entry_id', $open->id)->value('status'))->toBe('draft');
});

test('editing an open entry clears stale payable totals even when clock out is omitted or explicitly null', function (array $data) {
    [$open] = ($this->makeEntryEvidence)([
        'clock_out' => null,
        'total_hours' => 99,
        'break_compliance_met' => true,
        'status' => 'submitted',
    ]);

    $updated = app(TimeTrackingService::class)->editTimeEntry(
        $open,
        $this->manager,
        $data,
        'Normalise open entry',
    );

    expect($updated->clock_out)->toBeNull()
        ->and($updated->total_hours)->toBeNull()
        ->and($updated->break_compliance_met)->toBeNull()
        ->and($updated->status)->toBe('active');
})->with([
    'clock out omitted' => [[]],
    'clock out explicitly null' => [['clock_out' => null]],
]);

test('worker intervals use half-open overlap semantics for creation and edit', function () {
    app(TimeTrackingService::class)->createManualEntry($this->manager, [
        'user_id' => $this->worker->id,
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'clock_in' => '2026-06-15T09:00',
        'clock_out' => '2026-06-15T10:00',
    ]);

    $touching = app(TimeTrackingService::class)->createManualEntry($this->manager, [
        'user_id' => $this->worker->id,
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'clock_in' => '2026-06-15T10:00',
        'clock_out' => '2026-06-15T11:00',
    ]);
    expect($touching)->toBeInstanceOf(HrTimeEntry::class);

    expect(fn () => app(TimeTrackingService::class)->createManualEntry($this->manager, [
        'user_id' => $this->worker->id,
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'clock_in' => '2026-06-15T09:30',
        'clock_out' => '2026-06-15T10:30',
    ]))->toThrow(LogicException::class, 'overlapping time entry');
});

test('orphan recovery counts persisted nonvoided statuses and refuses duplicate rows', function () {
    $rows = collect(['active', 'submitted'])->map(fn (string $status) => HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'entry_date' => now(config('app.worker_timezone'))->toDateString(),
        'clock_in' => now()->subHours(2),
        'clock_out' => null,
        'status' => $status,
    ]));
    $before = $rows->mapWithKeys(fn (HrTimeEntry $row) => [$row->id => $row->fresh()->getRawOriginal()]);

    expect(fn () => app(TimeTrackingService::class)->closeOpenEntries(
        $this->worker,
        now(),
        10,
    ))->toThrow(LogicException::class, 'exactly one orphan');

    foreach ($rows as $row) {
        expect($row->fresh()->getRawOriginal())->toBe($before[$row->id]);
    }
});

test('orphan recovery rejects an impossible break without changing the entry', function () {
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'entry_date' => now(config('app.worker_timezone'))->toDateString(),
        'clock_in' => now()->subMinutes(30),
        'clock_out' => null,
        'status' => 'active',
    ]);
    $before = $entry->fresh()->getRawOriginal();
    $timesheetsBefore = Timesheet::query()->count();

    expect(fn () => app(TimeTrackingService::class)->closeOpenEntries(
        $this->worker,
        now(),
        60,
    ))->toThrow(LogicException::class, 'must be less than the session duration');

    expect($entry->fresh()->getRawOriginal())->toBe($before)
        ->and(Timesheet::query()->count())->toBe($timesheetsBefore);
});

test('orphan recovery rejects overlap with a submitted interval without changing either row', function () {
    $clockOut = now()->startOfMinute();
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'entry_date' => $clockOut->copy()->timezone(config('app.worker_timezone'))->toDateString(),
        'clock_in' => $clockOut->copy()->subHours(2),
        'clock_out' => null,
        'status' => 'active',
    ]);
    $overlap = HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'entry_date' => $entry->entry_date,
        'clock_in' => $clockOut->copy()->subMinutes(90),
        'clock_out' => $clockOut->copy()->subMinutes(60),
        'status' => 'submitted',
    ]);
    $entryBefore = $entry->fresh()->getRawOriginal();
    $overlapBefore = $overlap->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->closeOpenEntries(
        $this->worker,
        $clockOut,
        10,
    ))->toThrow(LogicException::class, 'overlapping time entry');

    expect($entry->fresh()->getRawOriginal())->toBe($entryBefore)
        ->and($overlap->fresh()->getRawOriginal())->toBe($overlapBefore);
});

test('attendance backfill is idempotent and ignores its own locked projection in overlap checks', function () {
    $shift = ($this->makeShift)();
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'site_id' => $this->site->id,
        'clock_in_at' => '2026-06-14 21:00:00',
        'clock_out_at' => '2026-06-15 05:00:00',
        'break_minutes' => 30,
        'status' => 'closed',
        'source' => 'legacy-backfill',
        'created_by' => $this->worker->id,
    ]);

    $first = app(TimeTrackingService::class)->syncEntryFromSession($session, $this->manager);
    $second = app(TimeTrackingService::class)->syncEntryFromSession($session->fresh(), $this->manager);

    expect($second?->id)->toBe($first?->id)
        ->and((float) $second?->total_hours)->toBe(7.5)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->count())->toBe(1);
});

test('attendance backfill overlap rejection rolls back canonical Site repair and creates no projection', function () {
    $shift = ($this->makeShift)();
    $overlap = HrTimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'client_id' => $this->client->id,
        'shift_id' => null,
        'attendance_session_id' => null,
        'entry_date' => '2026-06-15',
        'clock_in' => '2026-06-14 22:00:00',
        'clock_out' => '2026-06-14 23:00:00',
        'status' => 'submitted',
    ]);
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'site_id' => null,
        'clock_in_at' => '2026-06-14 21:00:00',
        'clock_out_at' => '2026-06-15 05:00:00',
        'break_minutes' => 30,
        'status' => 'closed',
        'source' => 'legacy-backfill',
        'created_by' => $this->worker->id,
    ]);
    $sessionBefore = $session->fresh()->getRawOriginal();
    $overlapBefore = $overlap->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->syncEntryFromSession($session, $this->manager))
        ->toThrow(LogicException::class, 'overlapping time entry');

    expect($session->fresh()->getRawOriginal())->toBe($sessionBefore)
        ->and($overlap->fresh()->getRawOriginal())->toBe($overlapBefore)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
});

test('attendance backfill rejects malformed breaks before repairing or projecting legacy evidence', function () {
    $shift = ($this->makeShift)();
    $session = HrAttendanceSession::withoutEvents(fn () => HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'site_id' => null,
        'clock_in_at' => '2026-06-14 21:00:00',
        'clock_out_at' => '2026-06-14 22:00:00',
        'break_minutes' => 60,
        'status' => 'closed',
        'source' => 'legacy-backfill',
        'created_by' => $this->worker->id,
    ]));
    $before = $session->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->syncEntryFromSession($session, $this->manager))
        ->toThrow(LogicException::class, 'must be less than the session duration');

    expect($session->fresh()->getRawOriginal())->toBe($before)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
});

test('attendance backfill rechecks protected Timesheets under lock and leaves legacy evidence unchanged', function (array $protection) {
    $shift = ($this->makeShift)();
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'site_id' => null,
        'clock_in_at' => '2026-06-14 21:00:00',
        'clock_out_at' => '2026-06-15 05:00:00',
        'break_minutes' => 30,
        'status' => 'closed',
        'source' => 'legacy-backfill',
        'created_by' => $this->worker->id,
    ]);
    $timesheet = Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->worker->id,
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'work_date' => '2026-06-15',
        'starts_at' => $session->clock_in_at,
        'ends_at' => $session->clock_out_at,
        'break_minutes' => 30,
        'payroll_reference' => null,
        'exported_to_payroll_at' => null,
        ...$protection,
    ]);
    $sessionBefore = $session->fresh()->getRawOriginal();
    $timesheetBefore = $timesheet->fresh()->getRawOriginal();

    expect(fn () => app(TimeTrackingService::class)->syncEntryFromSession($session, $this->manager))
        ->toThrow(LogicException::class);

    expect($session->fresh()->getRawOriginal())->toBe($sessionBefore)
        ->and($timesheet->fresh()->getRawOriginal())->toBe($timesheetBefore)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
})->with([
    'approved timesheet' => [['status' => 'approved']],
    'complete payroll segment' => [[
        'status' => 'draft',
        'payroll_segments_exported' => [['segment_minutes' => 480]],
    ]],
]);
