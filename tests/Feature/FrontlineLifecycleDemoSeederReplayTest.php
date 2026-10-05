<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\FrontlineLifecycleDemoSeeder;
use Database\Seeders\JobBoardReadinessDemoSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemCatalogSeeder;
use Database\Seeders\SystemClientsSeeder;
use Database\Seeders\SystemShiftsSeeder;
use Database\Seeders\SystemUsersSeeder;
use Tests\Support\FrontlineFixtureResetDiagnostic;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('frontline reset survives completed attendance and real approval across UTC midnight without changing approved evidence', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04T23:50:00Z')->utc());
    config(['features.rostering.publish' => true]);
    $this->seed([
        RbacSeeder::class,
        SystemCatalogSeeder::class,
        SystemUsersSeeder::class,
        SystemClientsSeeder::class,
        SystemShiftsSeeder::class,
    ]);
    $reset = function (string $phase): void {
        try {
            $this->seed(FrontlineLifecycleDemoSeeder::class);
        } catch (Throwable $exception) {
            $this->fail('Frontline fixture reset failed '.$phase.': '.json_encode(
                FrontlineFixtureResetDiagnostic::describe($exception), JSON_THROW_ON_ERROR,
            ));
        }
    };
    $reset('before attendance');

    $worker = User::query()->where('email', 'sw2@demo.test')->sole();
    $shift = Shift::query()->where('notes', 'PW:active-clean:'.$worker->email)->sole();
    $session = HrAttendanceSession::query()->where('shift_id', $shift->id)->where('user_id', $worker->id)->sole();
    $this->actingAs($worker)->post('/attendance/clock-out', [
        'session_id' => $session->id,
        'break_minutes' => 0,
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($session->fresh()->status)->toBe('closed')
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->count())->toBe(1);
    $closedTimesheet = Timesheet::query()->where('attendance_session_id', $session->id)->sole();
    $closedEntry = HrTimeEntry::query()->where('attendance_session_id', $session->id)->sole();
    $closedGraph = static fn (): array => [
        $session->fresh()->getRawOriginal(), $shift->fresh()->getRawOriginal(),
        $closedTimesheet->fresh()->getRawOriginal(), $closedEntry->fresh()->getRawOriginal(),
    ];
    $closedBefore = $closedGraph();
    $reset('after canonical clock out');
    $nextSession = HrAttendanceSession::query()->where('user_id', $worker->id)->where('status', 'open')->sole();
    expect($nextSession->id)->not->toBe($session->id)
        ->and((int) $nextSession->shift_id)->not->toBe((int) $shift->id)
        ->and($nextSession->shift->notes)->toBe('PW:active-clean:'.$worker->email.':after-'.$shift->id)
        ->and($nextSession->clock_in_at->equalTo($session->fresh()->clock_out_at))->toBeTrue()
        ->and($closedGraph())->toBe($closedBefore);

    $submitted = Timesheet::query()
        ->whereHas('shift', fn ($query) => $query->where('notes', 'PW:submitted-approval:'.$worker->email))
        ->sole();
    $entry = HrTimeEntry::query()->where('attendance_session_id', $submitted->attendance_session_id)->sole();
    $admin = User::query()->where('email', 'admin@demo.test')->sole();
    $this->actingAs($admin)->post(route('operations.timesheets.bulkApprove'), [
        'ids' => [$submitted->id],
        'decision_notes' => 'Approved the dedicated readiness attendance.',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Selected timesheets approved.');
    expect($submitted->fresh()->status)->toBe('approved')
        ->and($entry->fresh()->status)->toBe('approved');
    $approved = $submitted->fresh()->getRawOriginal();
    $approvedShift = $submitted->shift->fresh()->getRawOriginal();
    $approvedSession = $submitted->attendanceSession->fresh()->getRawOriginal();
    $approvedEntry = $entry->fresh()->getRawOriginal();
    $sibling = Timesheet::query()->whereHas('shift', fn ($query) => $query
        ->where('notes', 'PW:submitted-approval:sw6@demo.test'))->sole();
    $siblingEntry = HrTimeEntry::query()->where('attendance_session_id', $sibling->attendance_session_id)->sole();
    $siblingClocks = [
        'timesheet' => $sibling->only(['id', 'user_id', 'client_id', 'shift_id', 'attendance_session_id', 'shift_site_id', 'work_date', 'starts_at', 'ends_at']),
        'session' => $sibling->attendanceSession->only(['id', 'user_id', 'shift_id', 'site_id', 'clock_in_at', 'clock_out_at']),
        'entry' => $siblingEntry->only(['id', 'user_id', 'client_id', 'shift_id', 'site_id', 'attendance_session_id', 'clock_in', 'clock_out']),
    ];
    $nzDay = Carbon::now('Pacific/Auckland')->toDateString();

    $this->seed(JobBoardReadinessDemoSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-05T00:10:00Z')->utc());
    expect(Carbon::now('Pacific/Auckland')->toDateString())->toBe($nzDay);
    $reset('across UTC midnight on the same NZ day');
    $reset('on exact replay');

    expect($submitted->fresh()->getRawOriginal())->toBe($approved)
        ->and($submitted->shift->fresh()->getRawOriginal())->toBe($approvedShift)
        ->and($submitted->attendanceSession->fresh()->getRawOriginal())->toBe($approvedSession)
        ->and($entry->fresh()->getRawOriginal())->toBe($approvedEntry)
        ->and(Timesheet::query()->where('attendance_session_id', $approvedSession['id'])->count())->toBe(1)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $approvedSession['id'])->count())->toBe(1)
        ->and([
            'timesheet' => $sibling->fresh()->only(['id', 'user_id', 'client_id', 'shift_id', 'attendance_session_id', 'shift_site_id', 'work_date', 'starts_at', 'ends_at']),
            'session' => $sibling->attendanceSession->fresh()->only(['id', 'user_id', 'shift_id', 'site_id', 'clock_in_at', 'clock_out_at']),
            'entry' => $siblingEntry->fresh()->only(['id', 'user_id', 'client_id', 'shift_id', 'site_id', 'attendance_session_id', 'clock_in', 'clock_out']),
        ])->toEqual($siblingClocks)
        ->and($sibling->fresh()->status)->toBe('submitted')
        ->and($siblingEntry->fresh()->status)->toBe('submitted')
        ->and($closedGraph())->toBe($closedBefore)
        ->and(HrAttendanceSession::query()->where('user_id', $worker->id)->where('status', 'open')->sole()->id)->toBe($nextSession->id);

    $this->actingAs($worker)->post('/attendance/clock-out', [
        'session_id' => $nextSession->id,
        'break_minutes' => 0,
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    $nextTimesheet = Timesheet::query()->where('attendance_session_id', $nextSession->id)->sole();
    $nextEntry = HrTimeEntry::query()->where('attendance_session_id', $nextSession->id)->sole();
    $nextClosedGraph = static fn (): array => [
        $nextSession->fresh()->getRawOriginal(), $nextSession->shift->fresh()->getRawOriginal(),
        $nextTimesheet->fresh()->getRawOriginal(), $nextEntry->fresh()->getRawOriginal(),
    ];
    $nextClosedBefore = $nextClosedGraph();
    $reset('after the distinct next generation completes');
    $thirdSession = HrAttendanceSession::query()->where('user_id', $worker->id)->where('status', 'open')->sole();
    expect($thirdSession->id)->not->toBe($nextSession->id)
        ->and($thirdSession->shift->notes)->toBe('PW:active-clean:'.$worker->email.':after-'.$nextSession->shift_id)
        ->and($nextClosedGraph())->toBe($nextClosedBefore)
        ->and($closedGraph())->toBe($closedBefore)
        ->and($submitted->fresh()->getRawOriginal())->toBe($approved)
        ->and($submitted->shift->fresh()->getRawOriginal())->toBe($approvedShift)
        ->and($submitted->attendanceSession->fresh()->getRawOriginal())->toBe($approvedSession)
        ->and($entry->fresh()->getRawOriginal())->toBe($approvedEntry);
});
