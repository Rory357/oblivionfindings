<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Real services, model events and root commits; all command guards remain active. */
class MyHrClockRecordedResultTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;
    private Site $site;
    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 02:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff(true);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::connection()->transactionLevel() === 0) DB::beginTransaction();
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_canonical_clock_records_exact_identity_and_server_breaks_without_flash_or_private_fields(): void
    {
        $this->commitFixtures();
        $start = $this->actingAs($this->worker)->postJson('/hr/my/time/clock-in', $this->idle())
            ->assertOk()->assertJsonPath('target_kind', 'attendance_session')->assertJsonPath('time_entry_result', null)
            ->assertJsonPath('attendance_result.timesheet_sync_outcome', 'none')->assertSessionMissing('success');
        $session = HrAttendanceSession::where('user_id', $this->worker->id)->sole();
        $this->assertSame($session->id, $start->json('attendance_result.session_id'));
        $this->assertSame($this->worker->id, $start->json('actor_id'));
        $this->assertPrivate($start);
        $this->travel(2)->hours();
        $session->update(['break_minutes' => 11]);
        $end = $this->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $session->id))
            ->assertOk()->assertJsonPath('attendance_result.session_id', $session->id)
            ->assertJsonPath('attendance_result.shift_id', null)->assertJsonPath('attendance_result.session_status', 'closed')
            ->assertJsonPath('attendance_result.break_minutes', 11)->assertJsonPath('attendance_result.timesheet_sync_outcome', 'created')
            ->assertJsonPath('attendance_result.handover_outcome', 'no_shift')->assertSessionMissing('success');
        $this->assertSame(1.82, $end->json('attendance_result.worked_hours'));
        $this->assertSame($session->fresh()->clock_out_at->toIso8601String(), $end->json('attendance_result.clock_out_at'));
        $this->assertStringNotContainsString('Private fixture note', $end->getContent());
        $this->assertPrivate($end);
    }

    public function test_clock_out_uses_the_original_attendance_id_when_the_linked_hr_entry_id_is_different(): void
    {
        $session = $this->session();
        $entry = new HrTimeEntry(['user_id' => $this->worker->id, 'attendance_session_id' => $session->id,
            'shift_id' => null, 'site_id' => $this->site->id,
            'entry_date' => $session->clock_in_at->copy()->timezone('Pacific/Auckland')->toDateString(),
            'clock_in' => $session->clock_in_at, 'clock_out' => null, 'break_minutes' => 0,
            'entry_type' => 'clock', 'status' => 'active', 'source_type' => 'attendance', 'source_id' => $session->id,
            'created_by' => $this->worker->id]);
        // Choose from already-created identities, rather than guessing either
        // table's next auto-increment counter after committed-fixture cleanup.
        $entry->id = max((int) HrTimeEntry::withTrashed()->max('id'), (int) $session->id) + 1;
        $this->assertTrue($entry->save());
        $this->assertNotSame($entry->id, $session->id);
        $this->commitFixtures();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $session->id))
            ->assertOk()->assertJsonPath('attendance_result.session_id', $session->id);
        $this->assertNotNull($session->fresh()->clock_out_at);
        $this->assertNotNull($entry->fresh()->clock_out);
        $this->assertSame($session->id, $entry->fresh()->attendance_session_id);
    }

    #[DataProvider('orphanBreakChoices')]
    public function test_orphan_closure_preserves_omitted_breaks_or_accepts_an_explicit_override(?int $override, int $expected): void
    {
        $entry = $this->orphan();
        $payload = $this->target('orphan_time_entry', $entry->id);
        if ($override !== null) $payload['break_minutes'] = $override;
        $this->commitFixtures();
        $reply = $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $payload)->assertOk()
            ->assertJsonPath('attendance_result', null)->assertJsonPath('target_kind', 'orphan_time_entry')
            ->assertJsonPath('time_entry_result.id', $entry->id)->assertJsonPath('time_entry_result.attendance_session_id', null)
            ->assertJsonPath('time_entry_result.break_minutes', $expected)->assertJsonPath('time_entry_result.status', 'submitted')
            ->assertJsonPath('time_entry_result.timesheet_sync_outcome', 'created');
        $this->assertSame($expected, $entry->fresh()->break_minutes);
        $this->assertSame(0, HrAttendanceSession::where('user_id', $this->worker->id)->count());
        $this->assertSame($entry->id, Timesheet::where('hr_time_entry_id', $entry->id)->sole()->hr_time_entry_id);
        $this->assertPrivate($reply);
    }

    public static function orphanBreakChoices(): array
    {
        return ['preserve stored ten' => [null, 10], 'explicit fifteen' => [15, 15], 'explicit zero' => [0, 0]];
    }

    public function test_hr_only_staff_keep_orphan_recovery_without_receiving_attendance_permission(): void
    {
        $this->worker = $this->staff(false);
        $entry = $this->orphan();
        $this->commitFixtures();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-in', $this->idle())->assertForbidden();
        $this->postJson('/hr/my/time/clock-out', $this->target('orphan_time_entry', $entry->id))->assertOk();
        $this->assertNotNull($entry->fresh()->clock_out);
    }

    public function test_prewrite_validation_is_the_only_retryable_error_and_canonical_local_breaks_are_rejected(): void
    {
        $session = $this->session();
        $before = $this->history();
        $payload = [...$this->target('attendance_session', $session->id), 'break_minutes' => 0];
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $payload)->assertUnprocessable()
            ->assertJsonPath('code', 'my_hr_clock_rejected')->assertJsonValidationErrors('break_minutes');
        $this->assertSame($before, $this->history());
    }

    public function test_canonical_target_never_falls_through_to_an_orphan_and_nullable_shift_is_exact(): void
    {
        $session = $this->session(['clock_in_at' => now()->subHours(5), 'clock_out_at' => now()->subHours(4), 'status' => 'closed']);
        $entry = $this->orphan();
        $this->commitFixtures();
        $before = $this->history();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $session->id))
            ->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
        $this->postJson('/hr/my/time/clock-out', [...$this->target('orphan_time_entry', $entry->id), 'expected_shift_id' => 999999])->assertNotFound();
        $this->assertSame($before, $this->history());
    }

    public function test_orphan_target_cannot_close_a_new_attendance_session_or_another_entry(): void
    {
        $session = $this->session();
        $entry = $this->orphan();
        $this->commitFixtures();
        $before = $this->history();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('orphan_time_entry', $entry->id))
            ->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
        $this->postJson('/hr/my/time/clock-out', $this->target('orphan_time_entry', $entry->id + 100000))->assertNotFound();
        $this->assertSame($before, $this->history());
        $this->assertNull($session->fresh()->clock_out_at);
    }

    #[DataProvider('vetoes')]
    public function test_real_save_veto_is_unconfirmed_and_rolls_back_clock_payroll_and_audit(string $command, string $row): void
    {
        $target = $command === 'clock_in' ? null : ($command === 'orphan' ? $this->orphan() : $this->session());
        $this->commitFixtures();
        $reached = false;
        $listener = function ($model) use (&$reached) { $reached = true; return false; };
        if ($row === 'session') {
            $command === 'clock_in' ? HrAttendanceSession::creating($listener) : HrAttendanceSession::updating($listener);
        } elseif ($row === 'entry' || $row === 'entry_create') {
            $row === 'entry_create' ? HrTimeEntry::creating($listener) : HrTimeEntry::updating($listener);
        } else {
            Timesheet::creating($listener);
        }
        $before = $this->history();
        $url = '/hr/my/time/'.($command === 'clock_in' ? 'clock-in' : 'clock-out');
        $payload = $command === 'clock_in' ? $this->idle() : $this->target($command === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id);
        $this->actingAs($this->worker)->postJson($url, $payload)->assertStatus(409)
            ->assertJsonPath('code', 'my_hr_clock_result_unconfirmed')->assertJsonMissingPath('errors');
        $this->assertTrue($reached);
        $this->assertSame($before, $this->history());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function vetoes(): array
    {
        return ['clock-in session' => ['clock_in', 'session'], 'clock-out session' => ['clock_out', 'session'],
            'orphan entry' => ['orphan', 'entry'], 'clock-in projected entry' => ['clock_in', 'entry_create'],
            'clock-out projected entry' => ['clock_out', 'entry_create'],
            'canonical timesheet' => ['clock_out', 'timesheet'], 'orphan timesheet' => ['orphan', 'timesheet']];
    }

    #[DataProvider('draftOrigins')]
    public function test_existing_returned_draft_update_veto_stops_before_the_later_quiet_save(string $origin): void
    {
        $target = $origin === 'orphan' ? $this->orphan() : $this->session();
        $sheet = $this->draft($target, ['status' => 'returned', 'break_minutes' => 18]);
        $this->commitFixtures();
        $reached = 0;
        Timesheet::updating(function (Timesheet $candidate) use ($sheet, &$reached) {
            if ($candidate->id === $sheet->id) { $reached++; return false; }
        });
        $before = $this->history();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target(
            $origin === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id,
        ))->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
        $this->assertSame(1, $reached);
        $this->assertSame($before, $this->history());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('draftOrigins')]
    public function test_later_real_quiet_rate_write_failure_rolls_back_the_entire_original_clock(string $origin): void
    {
        $target = $origin === 'orphan' ? $this->orphan() : $this->session();
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['hourly_rate' => 31]);
        $this->commitFixtures();
        $reached = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$reached) {
            if (! $reached && str_starts_with(strtolower($query->sql), 'update `timesheets`')
                && str_contains($query->sql, '`pay_rate`')) {
                $reached = true;
                // QueryExecuted fires after the real quiet rate update. Throwing
                // here checks rollback of that write and the earlier insertion.
                throw new \LogicException('Reached later quiet rate write.');
            }
        });
        $before = $this->history();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target(
            $origin === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id,
        ))->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
        $this->assertTrue($reached);
        $this->assertSame($before, $this->history());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('draftOrigins')]
    public function test_existing_returned_draft_reports_update_and_preserves_the_canonical_break_floor(string $origin): void
    {
        $target = $origin === 'orphan' ? $this->orphan() : $this->session(['break_minutes' => 11]);
        $sheet = $this->draft($target, ['status' => 'returned', 'break_minutes' => 18]);
        $this->commitFixtures();
        $branch = $origin === 'orphan' ? 'time_entry_result' : 'attendance_result';
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target(
            $origin === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id,
        ))->assertOk()->assertJsonPath($branch.'.timesheet_sync_outcome', 'updated')
            ->assertJsonPath($branch.'.timesheet_id', $sheet->id)->assertJsonPath($branch.'.timesheet_status', 'draft');
        $this->assertSame($origin === 'orphan' ? 10 : 18, $sheet->fresh()->break_minutes);
    }

    public static function draftOrigins(): array { return ['attendance' => ['attendance'], 'orphan' => ['orphan']]; }

    public function test_submitted_canonical_sheet_is_not_rewritten_or_claimed_as_a_draft_save(): void
    {
        $session = $this->session();
        $sheet = $this->draft($session, ['status' => 'submitted']);
        $before = $sheet->getRawOriginal();
        $this->commitFixtures();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $session->id))
            ->assertOk()->assertJsonPath('attendance_result.timesheet_sync_outcome', 'skipped_follow_up')
            ->assertJsonPath('attendance_result.timesheet_id', null)->assertJsonPath('attendance_result.timesheet_status', null);
        $this->assertSame($before, $sheet->fresh()->getRawOriginal());
        $this->assertNotNull($session->fresh()->clock_out_at);
    }

    #[DataProvider('breakEndCases')]
    public function test_running_break_uses_recorded_minutes_and_an_event_veto_cannot_partially_close(bool $veto): void
    {
        $session = $this->session(['break_minutes' => 7, 'break_count' => 1, 'break_started_at' => now()->subMinutes(9)]);
        $event = HrAttendanceBreakEvent::create(['session_id' => $session->id, 'started_at' => $session->break_started_at]);
        $this->commitFixtures();
        $reached = false;
        if ($veto) HrAttendanceBreakEvent::updating(function ($candidate) use ($event, &$reached) {
            if ($candidate->id === $event->id) { $reached = true; return false; }
        });
        $before = $this->history();
        $response = $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $session->id));
        if ($veto) {
            $response->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
            $this->assertTrue($reached);
            $this->assertSame($before, $this->history());
        } else {
            $response->assertOk()->assertJsonPath('attendance_result.break_minutes', 16);
            $this->assertSame(9, $event->fresh()->minutes);
            $this->assertNotNull($event->fresh()->ended_at);
            $this->assertNull($session->fresh()->break_started_at);
        }
    }

    public static function breakEndCases(): array { return ['recorded break' => [false], 'real event update veto' => [true]]; }

    #[DataProvider('lateFailures')]
    public function test_after_commit_logic_or_validation_failure_never_becomes_retryable(string $command, string $kind): void
    {
        $target = $command === 'clock_in' ? null : ($command === 'orphan' ? $this->orphan() : $this->session());
        $this->commitFixtures();
        $reached = false;
        $callback = function () use ($kind, &$reached) {
            if ($reached) return;
            $reached = true;
            DB::afterCommit(function () use ($kind) {
                if ($kind === 'logic') throw new \LogicException('Late committed clock callback.');
                throw ValidationException::withMessages(['notes' => 'Late committed validation.']);
            });
        };
        if ($command === 'clock_in') HrAttendanceSession::created($callback);
        elseif ($command === 'orphan') HrTimeEntry::updated($callback);
        else HrAttendanceSession::updated($callback);
        $payload = $command === 'clock_in' ? $this->idle() : $this->target($command === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id);
        $this->actingAs($this->worker)->postJson('/hr/my/time/'.($command === 'clock_in' ? 'clock-in' : 'clock-out'), $payload)
            ->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed')->assertJsonMissingPath('errors');
        $this->assertTrue($reached);
        if ($command === 'clock_in') $this->assertSame(1, HrAttendanceSession::where('user_id', $this->worker->id)->open()->count());
        elseif ($command === 'orphan') $this->assertNotNull($target->fresh()->clock_out);
        else $this->assertNotNull($target->fresh()->clock_out_at);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function lateFailures(): array
    {
        return ['clock-in logic' => ['clock_in', 'logic'], 'clock-in validation' => ['clock_in', 'validation'],
            'clock-out logic' => ['clock_out', 'logic'], 'clock-out validation' => ['clock_out', 'validation'],
            'orphan logic' => ['orphan', 'logic'], 'orphan validation' => ['orphan', 'validation']];
    }

    #[DataProvider('draftOrigins')]
    public function test_post_commit_reader_withdrawal_denies_the_result_without_claiming_rollback(string $origin): void
    {
        $target = $origin === 'orphan' ? $this->orphan() : $this->session();
        $workerId = $this->worker->id;
        $this->commitFixtures();
        $reached = false;
        $listener = function () use (&$reached, $workerId, $origin) {
            if ($reached) return;
            $reached = true;
            DB::afterCommit(function () use ($workerId, $origin) {
                if ($origin === 'orphan') HrEmployeeProfile::where('user_id', $workerId)->update(['is_active' => false]);
                else DB::table('role_user')->where('user_id', $workerId)->delete();
            });
        };
        if ($origin === 'orphan') HrTimeEntry::updated($listener);
        else HrAttendanceSession::updated($listener);
        $response = $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target(
            $origin === 'orphan' ? 'orphan_time_entry' : 'attendance_session', $target->id,
        ));
        $response->assertStatus($origin === 'orphan' ? 404 : 403)->assertJsonMissingPath('attendance_result')->assertJsonMissingPath('time_entry_result');
        $this->assertTrue($reached);
        $this->assertNotNull($origin === 'orphan' ? $target->fresh()->clock_out : $target->fresh()->clock_out_at);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_post_commit_current_read_does_not_replace_the_original_recorded_clock_frame(): void
    {
        $session = $this->session();
        $originalEnd = now()->toIso8601String();
        $sessionId = $session->id;
        $this->commitFixtures();
        $reached = false;
        HrAttendanceSession::updated(function ($candidate) use ($sessionId, &$reached) {
            if ($candidate->id !== $sessionId || $reached) return;
            $reached = true;
            DB::afterCommit(fn () => DB::table('hr_attendance_sessions')->where('id', $sessionId)->update(['clock_out_at' => now()->addMinutes(6)]));
        });
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', $this->target('attendance_session', $sessionId))
            ->assertOk()->assertJsonPath('attendance_result.clock_out_at', $originalEnd);
        $this->assertTrue($reached);
        $this->assertNotSame($originalEnd, $session->fresh()->clock_out_at->toIso8601String());
    }

    public function test_nested_success_is_unconfirmed_until_the_actual_outer_owner_decides(): void
    {
        $this->commitFixtures();
        DB::beginTransaction();
        try {
            $this->actingAs($this->worker)->postJson('/hr/my/time/clock-in', $this->idle())->assertStatus(409)
                ->assertJsonPath('code', 'my_hr_clock_result_unconfirmed');
            DB::rollBack();
            $this->assertSame(0, HrAttendanceSession::where('user_id', $this->worker->id)->count());
        } finally {
            while (DB::transactionLevel() > 0) DB::rollBack();
        }
    }

    public function test_default_and_inertia_callers_keep_the_legacy_redirect_contract(): void
    {
        $this->actingAs($this->worker)->from('/hr/my')->post('/hr/my/time/clock-in')->assertRedirect('/hr/my')->assertSessionHas('success');
        $this->travel(2)->hours();
        $this->withHeader('X-Inertia', 'true')->post('/hr/my/time/clock-out', ['break_minutes' => 0], ['Accept' => 'application/json'])
            ->assertRedirect('/hr/my')->assertSessionHas('success');
    }

    private function staff(bool $clock): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'hr-clock-'.Str::uuid(), 'label' => 'HR clock fixture', 'type' => 'custom', 'level' => 10]);
        if ($clock) $role->permissions()->attach(Permission::firstOrCreate(['key' => 'timesheets.create'], ['description' => 'timesheets.create', 'group' => 'timesheets', 'module' => 'Operations']));
        $worker->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        return $worker->fresh();
    }

    private function session(array $extra = []): HrAttendanceSession
    {
        return HrAttendanceSession::create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'manual', 'created_by' => $this->worker->id, ...$extra]);
    }

    private function orphan(): HrTimeEntry
    {
        $in = now()->subHours(2);
        return HrTimeEntry::create(['user_id' => $this->worker->id, 'attendance_session_id' => null, 'shift_id' => null,
            'site_id' => $this->site->id, 'entry_date' => $in->copy()->timezone('Pacific/Auckland')->toDateString(),
            'clock_in' => $in, 'clock_out' => null, 'break_minutes' => 10, 'entry_type' => 'clock', 'status' => 'active',
            'notes' => 'Private fixture note', 'created_by' => $this->worker->id]);
    }

    private function draft(HrAttendanceSession|HrTimeEntry $target, array $extra = []): Timesheet
    {
        $session = $target instanceof HrAttendanceSession;
        $in = $session ? $target->clock_in_at : $target->clock_in;
        return Timesheet::create(['user_id' => $this->worker->id, 'shift_id' => null, 'client_id' => null,
            'attendance_session_id' => $session ? $target->id : null, 'hr_time_entry_id' => $session ? null : $target->id,
            'activity_type' => 'other', 'site_id' => $this->site->id, 'work_date' => $in->copy()->timezone('Pacific/Auckland')->toDateString(),
            'starts_at' => $in, 'ends_at' => now()->subMinute(), 'break_minutes' => 0,
            'status' => 'draft', 'created_by' => $this->worker->id, ...$extra]);
    }

    private function idle(): array { return ['actor_id' => $this->worker->id, 'target' => ['kind' => 'idle']]; }
    private function target(string $kind, int $id): array { return ['actor_id' => $this->worker->id, 'target' => ['kind' => $kind, 'id' => $id], 'expected_shift_id' => null]; }

    private function history(): array
    {
        return collect(['hr_attendance_sessions', 'hr_attendance_break_events', 'hr_time_entries', 'timesheets', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function assertPrivate($response): void
    {
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->fixturesCommitted = true;
        $this->assertFalse($connection->getPdo()->inTransaction());
    }
}
