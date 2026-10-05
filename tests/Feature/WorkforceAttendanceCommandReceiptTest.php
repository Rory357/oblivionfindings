<?php

namespace Tests\Feature;

use App\Domain\Hr\Enums\AttendanceHandoverOutcome;
use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Http\Controllers\AttendanceController;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/** Real root commits; session, handover and pay rules execute unchanged. */
class WorkforceAttendanceCommandReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $manager;

    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 05:00:00', 'UTC'));
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        $this->worker = $this->staff($this->site, ['timesheets.create', 'shifts.viewAssigned', 'clients.viewAssigned']);
        $this->manager = $this->staff($this->site, ['timesheets.manageAny', 'shifts.manageAny', 'clients.viewAny']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction(); // RefreshDatabase rolls back before committed fixture cleanup.
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('clockInChoices')]
    public function test_clock_in_receipt_reports_server_time_and_actual_explicit_automatic_or_shiftless_link(string $choice): void
    {
        $shift = $choice === 'shiftless' ? null : $this->shift($this->worker, [
            'status' => 'scheduled', 'actual_starts_at' => null, 'started_by' => null,
            'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHours(7),
        ]);
        $this->commitFixtures();

        $response = $this->actingAs($this->worker)->from('/attendance')->post(route('attendance.clockIn'), [
            'shift_id' => $choice === 'explicit' ? $shift->id : null,
            'clock_in_at' => now()->subDay()->toIso8601String(), // HTTP intentionally does not accept this field.
            'notes' => 'Private clock-in note.',
        ])->assertRedirect('/attendance')->assertSessionHas('success', 'Clocked in successfully.')->assertSessionHasNoErrors();
        $session = HrAttendanceSession::where('user_id', $this->worker->id)->sole();
        $receipt = $this->assertReceipt($response, 'clock_in', $session, 'none', null);
        $this->assertSame($shift?->id, $receipt['shift_id']);
        $this->assertSame(now()->timestamp, $session->clock_in_at->timestamp);
        $this->assertSame('open', $receipt['session_status']);
        $this->assertNull($receipt['clock_out_at']);
        $this->assertNull($receipt['worked_hours']);
        $this->assertSame(0, Timesheet::where('attendance_session_id', $session->id)->count());
        $this->assertSame($session->id, HrTimeEntry::where('attendance_session_id', $session->id)->sole()->source_id);
        $this->assertStringNotContainsString('Private clock-in note', json_encode($receipt, JSON_THROW_ON_ERROR));

        $this->actingAs($this->worker)->get(route('attendance.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.attendance_result', $receipt));
        $this->actingAs($this->worker)->get(route('attendance.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.attendance_result', null));
    }

    public static function clockInChoices(): array
    {
        return ['explicit' => ['explicit'], 'automatic' => ['automatic'], 'shiftless' => ['shiftless']];
    }

    #[DataProvider('handoverBranches')]
    public function test_clock_out_receipt_reports_the_actual_transactional_handover_outcome_without_claiming_submission(string $branch, string $outcome): void
    {
        $shift = $branch === 'no_shift' ? null : $this->shift($this->worker);
        $session = $this->attendanceSession($this->worker, $shift);
        $retained = null;
        if (in_array($branch, ['submitted', 'acknowledged'], true)) {
            $incoming = $this->shift($this->manager, ['status' => 'scheduled', 'actual_starts_at' => null,
                'started_by' => null, 'starts_at' => $shift->ends_at, 'ends_at' => $shift->ends_at->copy()->addHours(8)]);
            $retained = $this->handover($shift, $branch, $incoming);
        } elseif ($branch === 'no_payload') {
            $retained = $this->handover($shift, 'draft');
        } elseif ($branch === 'draft') {
            $this->shift($this->manager, ['status' => 'scheduled', 'actual_starts_at' => null,
                'started_by' => null, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(9)]);
        }
        $retainedBefore = $retained?->fresh()->getRawOriginal();
        $this->commitFixtures();
        $payload = ['session_id' => $session->id, 'clock_out_at' => now()->toIso8601String(), 'break_minutes' => 15];
        if ($branch !== 'no_payload') {
            $payload['handover'] = $this->handoverPayload();
        }

        $response = $this->actingAs($this->worker)->post(route('attendance.clockOut'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $receipt = $this->assertReceipt($response, 'clock_out', $session->fresh(), 'created', $outcome);
        $this->assertSame('closed', $receipt['session_status']);
        $this->assertSame(7.75, $receipt['worked_hours']);
        if ($retained) {
            $this->assertSame($retainedBefore, $retained->fresh()->getRawOriginal());
        } elseif ($shift) {
            $draft = ShiftHandover::where('outgoing_shift_id', $shift->id)->sole();
            $this->assertSame('draft', $draft->status);
            $this->assertNull($draft->submitted_at);
            $this->assertSame('in_progress', $shift->fresh()->status, 'The exact incoming duty defers completion until its handover is submitted.');
        } else {
            $this->assertSame(0, ShiftHandover::count());
        }
        $this->assertStringNotContainsString('Private handover narrative', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public static function handoverBranches(): array
    {
        return [
            'actual draft save' => ['draft', 'draft_saved'],
            'retained submission' => ['submitted', 'existing_submitted_or_acknowledged'],
            'retained acknowledgement' => ['acknowledged', 'existing_submitted_or_acknowledged'],
            'no payload despite existing draft' => ['no_payload', 'no_payload'],
            'no linked shift' => ['no_shift', 'no_shift'],
        ];
    }

    #[DataProvider('runningBreaks')]
    public function test_omitted_break_payload_receipt_uses_the_saved_running_floor_to_the_entered_end_including_over_240(int $saved, int $runningStartMinutesAgo, int $endMinutesAgo, int $expected): void
    {
        $session = $this->attendanceSession($this->worker, null, ['break_minutes' => $saved,
            'break_started_at' => now()->subMinutes($runningStartMinutesAgo)]);
        $break = HrAttendanceBreakEvent::create(['session_id' => $session->id,
            'started_at' => $session->break_started_at, 'ended_at' => null, 'minutes' => 0,
            'created_by' => $this->worker->id]);
        $this->commitFixtures();
        $end = now()->subMinutes($endMinutesAgo);

        $response = $this->actingAs($this->worker)->post(route('attendance.clockOut'), [
            'session_id' => $session->id, 'clock_out_at' => $end->toIso8601String(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $receipt = $this->assertReceipt($response, 'clock_out', $session->fresh(), 'created', 'no_shift');
        $this->assertSame($expected, $receipt['break_minutes']);
        $this->assertSame($end->timestamp, Carbon::parse($receipt['clock_out_at'])->timestamp);
        $this->assertNull($session->fresh()->break_started_at);
        $this->assertSame($end->timestamp, $break->fresh()->ended_at->timestamp);
        $this->assertSame($runningStartMinutesAgo - $endMinutesAgo, $break->fresh()->minutes);
    }

    public static function runningBreaks(): array
    {
        return ['entered earlier end' => [20, 75, 15, 80], 'true floor over input cap' => [240, 60, 0, 300]];
    }

    public function test_correction_receipt_reports_updated_draft_and_replacement_breaks_after_real_commit(): void
    {
        $session = $this->attendanceSession($this->worker, null, ['status' => 'closed', 'clock_out_at' => now()->subHours(3),
            'break_minutes' => 45, 'closed_by' => $this->worker->id]);
        $timesheet = app(DraftTimesheetService::class)->fromAttendanceSession($session, $this->worker->id);
        $timesheet->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $this->worker->id])->saveQuietly();
        $this->commitFixtures();
        $end = now()->subHours(2);

        $response = $this->actingAs($this->worker)->post(route('attendance.sessions.correct', $session), [
            'clock_out_at' => $end->toIso8601String(), 'break_minutes' => 15, 'reason' => 'Confirmed the actual end and replacement break.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $receipt = $this->assertReceipt($response, 'correct', $session->fresh(), 'updated', null);
        $this->assertSame($timesheet->id, $receipt['timesheet_id']);
        $this->assertSame('draft', $receipt['timesheet_status']);
        $this->assertSame(15, $receipt['break_minutes']);
        $this->assertSame($end->timestamp, $timesheet->fresh()->ends_at->timestamp);
        $response->assertSessionHas('success', "Session corrected. Timesheet #{$timesheet->id} recalculated.");
    }

    #[DataProvider('recoveryActions')]
    public function test_reassigned_recovery_receipt_reports_follow_up_without_exposing_or_mutating_protected_timesheet(string $action): void
    {
        $shift = $this->shift($this->worker);
        $session = $this->attendanceSession($this->worker, $shift);
        $entry = $this->timeEntry($session);
        $timesheet = Timesheet::factory()->create(['shift_id' => $shift->id, 'attendance_session_id' => $session->id,
            'user_id' => $this->worker->id, 'client_id' => $this->client->id, 'shift_site_id' => $this->site->id,
            'work_date' => $session->clock_in_at->copy()->timezone('Pacific/Auckland')->toDateString(),
            'starts_at' => $session->clock_in_at, 'ends_at' => now()->subHour(), 'break_minutes' => 0,
            'status' => 'draft', 'created_by' => $this->worker->id]);
        $timesheet->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $this->worker->id])->saveQuietly();
        $shift->update(['user_id' => $this->manager->id]);
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $timesheetBefore = $timesheet->fresh()->getRawOriginal();
        $this->commitFixtures();
        $payload = ['reason' => 'Recover original attendance without rewriting replacement or payroll evidence.'];
        if ($action === 'correct') {
            $payload += ['clock_out_at' => now()->subMinute()->toIso8601String(), 'break_minutes' => 0];
        }

        $response = $this->actingAs($this->manager)->post(route('attendance.sessions.'.($action === 'correct' ? 'correct' : 'end'), $session), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $receipt = $this->assertReceipt($response, $action, $session->fresh(), 'skipped_follow_up', null);
        $this->assertNull($receipt['timesheet_id']);
        $this->assertNull($receipt['timesheet_status']);
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($timesheetBefore, $timesheet->fresh()->getRawOriginal());
        $this->assertSame($this->worker->id, $entry->fresh()->user_id);
        $this->assertSame('submitted', $entry->fresh()->status);
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Payroll follow-up is required; no Timesheet was changed.'));
    }

    public static function recoveryActions(): array
    {
        return ['correction' => ['correct'], 'administrative end' => ['end_session']];
    }

    public function test_administrative_end_receipt_uses_the_actual_passed_rostered_end_and_created_draft(): void
    {
        $shift = $this->shift($this->worker);
        $session = $this->attendanceSession($this->worker, $shift);
        $this->commitFixtures();

        $response = $this->actingAs($this->manager)->post(route('attendance.sessions.end', $session), ['reason' => 'Confirmed rostered end for missed clock-out.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $receipt = $this->assertReceipt($response, 'end_session', $session->fresh(), 'created', null);
        $this->assertSame($shift->ends_at->timestamp, Carbon::parse($receipt['clock_out_at'])->timestamp);
        $this->assertSame($this->manager->id, $session->fresh()->closed_by);
        $this->assertSame('completed', $shift->fresh()->status);
    }

    public function test_already_closed_administrative_no_op_has_no_mutation_receipt_and_preserves_history(): void
    {
        $session = $this->attendanceSession($this->worker, null, ['status' => 'closed', 'clock_out_at' => now()->subHour(),
            'closed_by' => $this->worker->id]);
        $this->commitFixtures();
        $before = $this->history();

        $this->actingAs($this->manager)->post(route('attendance.sessions.end', $session), ['reason' => 'Reviewed the already closed record.'])
            ->assertRedirect()->assertSessionHas('info', 'This session was already closed.')->assertSessionMissing('attendance_result');
        $this->assertSame($before, $this->history());
    }

    public function test_repeated_end_no_op_cannot_reuse_the_previous_same_session_receipt_without_an_intervening_page_read(): void
    {
        $session = $this->attendanceSession($this->worker);
        $this->commitFixtures();
        $this->actingAs($this->manager)->post(route('attendance.sessions.end', $session), ['reason' => 'Confirmed actual session end.'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('attendance_result');
        $before = $this->history();

        $this->actingAs($this->manager)->post(route('attendance.sessions.end', $session), ['reason' => 'Repeated explicit record check.'])
            ->assertRedirect()->assertSessionHas('info', 'This session was already closed.')->assertSessionMissing('attendance_result');
        $this->assertSame($before, $this->history());
    }

    #[DataProvider('deniedCommands')]
    public function test_denied_direct_objects_never_return_receipts_or_change_time_history(string $action, bool $foreignSite): void
    {
        $site = $foreignSite ? Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]) : $this->site;
        $target = $this->staff($site, ['timesheets.create']);
        $session = $this->attendanceSession($target, null, ['site_id' => $site->id]);
        $this->commitFixtures();
        $before = $this->history();
        $actor = $action === 'end_session' ? $this->manager : $this->worker;
        $route = $action === 'clock_out' ? route('attendance.clockOut') : route('attendance.sessions.'.($action === 'correct' ? 'correct' : 'end'), $session);

        $this->actingAs($actor)->post($route, ['session_id' => $session->id, 'clock_out_at' => now()->toIso8601String(),
            'reason' => 'Foreign source must remain concealed.'])
            ->assertNotFound()->assertSessionMissing('attendance_result');
        $this->assertSame($before, $this->history());
    }

    public static function deniedCommands(): array
    {
        return ['foreign worker clock-out' => ['clock_out', false], 'foreign worker correction' => ['correct', false],
            'foreign Site correction' => ['correct', true], 'foreign Site administrative end' => ['end_session', true]];
    }

    public function test_later_clock_out_blocker_rolls_back_saved_draft_and_all_time_evidence_without_receipt(): void
    {
        $shift = $this->shift($this->worker);
        $session = $this->attendanceSession($this->worker, $shift);
        ShiftTask::create(['shift_id' => $shift->id, 'label' => 'Required unfinished task', 'is_completed' => false, 'sort_order' => 1]);
        $this->commitFixtures();
        $before = $this->history();

        $this->actingAs($this->worker)->post(route('attendance.clockOut'), ['session_id' => $session->id,
            'clock_out_at' => now()->toIso8601String(), 'handover' => $this->handoverPayload()])
            ->assertRedirect()->assertSessionHasErrors('clock_out')->assertSessionHas('clock_out_blockers')
            ->assertSessionMissing('attendance_result');
        $this->assertSame($before, $this->history());
        $this->assertSame(0, ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
    }

    #[DataProvider('outerOutcomes')]
    public function test_an_enclosing_transaction_never_emits_an_early_commit_receipt_even_when_it_later_commits(bool $commit): void
    {
        $this->commitFixtures();
        $before = $this->history();
        DB::beginTransaction();
        try {
            $this->actingAs($this->worker)->post(route('attendance.clockIn'))
                ->assertRedirect()->assertSessionHas('success', 'Clocked in successfully.')->assertSessionMissing('attendance_result');
            $this->assertSame(1, HrAttendanceSession::where('user_id', $this->worker->id)->count());
            if ($commit) {
                DB::commit();
                $this->assertSame(1, HrAttendanceSession::where('user_id', $this->worker->id)->count());
            } else {
                DB::rollBack();
                $this->assertSame($before, $this->history());
            }
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertNull(session('attendance_result'));
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function outerOutcomes(): array
    {
        return ['root rollback' => [false], 'later root commit' => [true]];
    }

    public function test_receipt_projection_and_logging_failure_cannot_report_a_failed_committed_clock_out(): void
    {
        $session = $this->attendanceSession($this->worker);
        $this->commitFixtures();
        $closed = app(AttendanceService::class)->clockOut($this->worker, $session);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $before = $this->history();
        $fault = new class extends HrAttendanceSession
        {
            public function getWorkedHoursAttribute(): float
            {
                throw new RuntimeException('Injected receipt-only accessor failure.');
            }
        };
        $fault->setRawAttributes($closed->getAttributes(), true);
        $fault->setRelations($closed->getRelations());
        $fault->markTimesheetSyncOutcome($closed->timesheetSyncOutcome())->markHandoverOutcome(AttendanceHandoverOutcome::NoShift);
        Log::partialMock()->shouldReceive('warning')->once()->andThrow(new RuntimeException('Injected receipt-only logging failure.'));
        $method = new ReflectionMethod(AttendanceController::class, 'attendanceSuccess');
        $response = $method->invoke(app(AttendanceController::class), 'Clocked out successfully.', 'clock_out', $fault, true);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Clocked out successfully.', session('success'));
        $this->assertNull(session('attendance_result'));
        $this->assertSame($before, $this->history());
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('draft', $session->timesheet()->sole()->status);
    }

    private function staff(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'attendance-receipt-'.Str::uuid(), 'label' => 'Receipt fixture role', 'type' => 'custom', 'level' => 10]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => explode('.', $key)[0], 'module' => 'Operations']);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null,
            'hourly_rate' => 30, 'manager_user_id' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);
        if ((int) $site->id === (int) $this->site->id) {
            $this->client->supportWorkers()->syncWithoutDetaching([$user->id]);
        }

        return $user->fresh();
    }

    private function shift(User $worker, array $attributes = []): Shift
    {
        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id,
            'service_context_id' => null, 'user_id' => $worker->id, 'created_by' => $worker->id,
            'starts_at' => now()->subHours(8), 'ends_at' => now()->subHour(), 'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(8), 'started_by' => $worker->id,
            'expected_break_minutes' => 0, 'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false,
            'coverage_roles' => [], ...$attributes])->fresh();
    }

    private function attendanceSession(User $worker, ?Shift $shift = null, array $attributes = []): HrAttendanceSession
    {
        return HrAttendanceSession::create(['user_id' => $worker->id, 'shift_id' => $shift?->id,
            'site_id' => $this->site->id, 'clock_in_at' => now()->subHours(8), 'break_minutes' => 0,
            'status' => 'open', 'source' => 'manual', 'created_by' => $worker->id, ...$attributes])->fresh();
    }

    private function handover(Shift $shift, string $status, ?Shift $incoming = null): ShiftHandover
    {
        return ShiftHandover::create(['outgoing_shift_id' => $shift->id, 'client_id' => $shift->client_id,
            'outgoing_staff_id' => $shift->user_id, 'incoming_shift_id' => $incoming?->id,
            'incoming_staff_id' => $incoming?->user_id, 'status' => $status,
            'handover_notes' => 'Private retained handover narrative.', 'version' => 1,
            'submitted_at' => $status === 'draft' ? null : now()->subMinutes(30),
            'submitted_by' => $status === 'draft' ? null : $shift->user_id,
            'acknowledged_at' => $status === 'acknowledged' ? now()->subMinutes(20) : null,
            'acknowledged_by' => $status === 'acknowledged' ? $incoming?->user_id : null])->fresh();
    }

    private function handoverPayload(): array
    {
        return ['meds_completed' => true, 'follow_up_needed' => false, 'shift_rating' => 'calm',
            'handover_notes' => 'Private handover narrative for receipt-only regression.'];
    }

    private function timeEntry(HrAttendanceSession $session): HrTimeEntry
    {
        return HrTimeEntry::create(['user_id' => $session->user_id, 'shift_id' => $session->shift_id,
            'attendance_session_id' => $session->id, 'site_id' => $session->site_id, 'client_id' => $this->client->id,
            'entry_date' => $session->clock_in_at->copy()->timezone('Pacific/Auckland')->toDateString(),
            'clock_in' => $session->clock_in_at, 'clock_out' => null, 'break_minutes' => 0,
            'entry_type' => 'clock', 'status' => 'active', 'source_type' => 'attendance', 'source_id' => $session->id,
            'created_by' => $session->user_id])->fresh();
    }

    private function commitFixtures(): void
    {
        $this->assertMatchesRegularExpression('/^oblivion_workforce_[a-zA-Z0-9_]+_'.getmypid().'$/D', DB::connection()->getDatabaseName());
        $this->assertSame(1, DB::connection()->transactionLevel());
        DB::commit();
        $this->fixturesCommitted = true;
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function assertReceipt($response, string $action, HrAttendanceSession $session, string $sync, ?string $handover): array
    {
        $receipt = session('attendance_result');
        $this->assertIsArray($receipt);
        $response->assertSessionHas('attendance_result', $receipt);
        $this->assertSame(['action', 'session_id', 'shift_id', 'session_status', 'clock_in_at', 'clock_out_at',
            'break_minutes', 'worked_hours', 'timesheet_sync_outcome', 'timesheet_id', 'timesheet_status', 'handover_outcome'], array_keys($receipt));
        $this->assertSame($action, $receipt['action']);
        $this->assertSame((int) $session->id, $receipt['session_id']);
        $this->assertSame($session->shift_id === null ? null : (int) $session->shift_id, $receipt['shift_id']);
        $this->assertSame($session->clock_in_at->timestamp, Carbon::parse($receipt['clock_in_at'])->timestamp);
        $this->assertSame($session->clock_out_at?->timestamp, $receipt['clock_out_at'] === null ? null : Carbon::parse($receipt['clock_out_at'])->timestamp);
        $this->assertSame((int) $session->break_minutes, $receipt['break_minutes']);
        $this->assertSame($sync, $receipt['timesheet_sync_outcome']);
        $this->assertSame($handover, $receipt['handover_outcome']);
        if (in_array($sync, ['created', 'updated'], true)) {
            $timesheet = Timesheet::where('attendance_session_id', $session->id)->sole();
            $this->assertSame((int) $timesheet->id, $receipt['timesheet_id']);
            $this->assertSame($timesheet->status, $receipt['timesheet_status']);
        } else {
            $this->assertNull($receipt['timesheet_id']);
            $this->assertNull($receipt['timesheet_status']);
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());

        return $receipt;
    }

    private function history(): array
    {
        $tables = [Shift::class, ShiftTask::class, ShiftHandover::class, HrAttendanceSession::class, HrTimeEntry::class, Timesheet::class];
        $snapshot = [];
        foreach ($tables as $class) {
            $table = (new $class)->getTable();
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        foreach (['audit_logs', 'timeline_events', 'hr_time_entry_amendments'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
