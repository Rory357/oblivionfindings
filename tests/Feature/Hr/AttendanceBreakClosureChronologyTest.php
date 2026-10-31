<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Real writers and their original nested transaction boundaries, without clock mocks. */
class AttendanceBreakClosureChronologyTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;
    private User $manager;
    private Site $site;
    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 02:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff(['timesheets.create']);
        $this->manager = $this->staff(['timesheets.manageAny', 'timesheets.viewAny']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::transactionLevel() === 0) DB::beginTransaction();
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('reversedClosures')]
    public function test_each_original_writer_rolls_back_before_closing_a_break_in_reverse_order(string $writer, string $scenario): void
    {
        $end = $scenario === 'earlier chosen end' ? now()->subMinutes(20) : now();
        $start = $scenario === 'future session start' ? now()->addMinutes(10) : now()->subMinutes(10);
        $eventStart = match ($scenario) {
            'future event start' => now()->addMinutes(10),
            'different past starts' => now()->subMinutes(5),
            default => $start,
        };
        [$session, $event] = $this->runningBreak($start, $eventStart);
        $sessionWritten = false;
        $eventWrites = 0;
        HrAttendanceSession::updated(function ($candidate) use ($session, &$sessionWritten) {
            if ($candidate->id === $session->id) $sessionWritten = true;
        });
        HrAttendanceBreakEvent::updating(function ($candidate) use ($event, &$eventWrites) {
            if ($candidate->id === $event->id) $eventWrites++;
        });
        $before = $this->history();
        $transactionLevel = DB::transactionLevel();
        try {
            $this->close($writer, $session, $end, 7);
            $this->fail('Conflicting break starts or a reversed break interval must be rejected.');
        } catch (\LogicException $exception) {
            $expected = in_array($scenario, ['future event start', 'different past starts'], true)
                ? 'The active attendance break evidence is missing or conflicting.'
                : 'Clock-out time cannot be before the active break starts.';
            $this->assertSame($expected, $exception->getMessage());
        }
        // All three callers update the session before reaching the shared
        // helper. This proves the existing transaction rolls that write back.
        $this->assertTrue($sessionWritten);
        $this->assertSame(0, $eventWrites);
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertSame($before, $this->history());
    }

    public static function reversedClosures(): array
    {
        $cases = [];
        foreach (['clock_out', 'admin_end', 'correct'] as $writer) {
            foreach (['future session start', 'future event start', 'earlier chosen end', 'different past starts'] as $scenario) {
                $cases[$writer.' '.$scenario] = [$writer, $scenario];
            }
        }
        return $cases;
    }

    #[DataProvider('ordinaryClosures')]
    public function test_each_original_writer_keeps_ordinary_and_equal_instant_break_arithmetic(string $writer, int $runningMinutes): void
    {
        $end = now();
        $start = $end->copy()->subMinutes($runningMinutes);
        [$session, $event] = $this->runningBreak($start, $start);
        $transactionLevel = DB::transactionLevel();
        $closed = $this->close($writer, $session, $end, 7 + $runningMinutes);
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertSame('closed', $closed->status);
        $this->assertSame(7 + $runningMinutes, $closed->break_minutes);
        $this->assertNull($closed->break_started_at);
        $this->assertSame($end->toIso8601String(), $closed->clock_out_at->toIso8601String());
        $this->assertSame($runningMinutes, $event->fresh()->minutes);
        $this->assertSame($end->toIso8601String(), $event->fresh()->ended_at->toIso8601String());
        $entry = HrTimeEntry::where('attendance_session_id', $session->id)->sole();
        $sheet = Timesheet::where('attendance_session_id', $session->id)->sole();
        $this->assertSame(7 + $runningMinutes, $entry->break_minutes);
        $this->assertSame(7 + $runningMinutes, $sheet->break_minutes);
        $this->assertSame($end->toIso8601String(), $entry->clock_out->toIso8601String());
    }

    public static function ordinaryClosures(): array
    {
        $cases = [];
        foreach (['clock_out', 'admin_end', 'correct'] as $writer) {
            foreach ([9, 0] as $minutes) $cases[$writer.' '.$minutes.' minutes'] = [$writer, $minutes];
        }
        return $cases;
    }

    public function test_checked_my_hr_clock_out_keeps_the_unconfirmed_contract_and_unchanged_history(): void
    {
        $start = now()->addMinutes(10);
        [$session] = $this->runningBreak($start, $start);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->commitFixtures();
        $sessionWritten = false;
        HrAttendanceSession::updated(function ($candidate) use ($session, &$sessionWritten) {
            if ($candidate->id === $session->id) $sessionWritten = true;
        });
        $before = $this->history();
        $this->actingAs($this->worker)->postJson('/hr/my/time/clock-out', [
            'actor_id' => $this->worker->id,
            'target' => ['kind' => 'attendance_session', 'id' => $session->id],
            'expected_shift_id' => null,
        ])->assertStatus(409)->assertJsonPath('code', 'my_hr_clock_result_unconfirmed')
            ->assertJsonMissingPath('errors')->assertJsonMissingPath('attendance_result')->assertSessionMissing('success');
        $this->assertTrue($sessionWritten, 'The actual writer must reach the session update before chronology rejection.');
        $this->assertSame($before, $this->history());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_existing_manager_end_session_endpoint_keeps_its_error_bag_without_a_success_receipt(): void
    {
        $start = now()->addMinutes(10);
        [$session] = $this->runningBreak($start, $start);
        $before = $this->history();
        $this->actingAs($this->manager)->from('/attendance')->post('/attendance/sessions/'.$session->id.'/end', [
            'reason' => 'Review the future break timestamp.',
        ])->assertRedirect('/attendance')->assertSessionHasErrors('end_session')
            ->assertSessionMissing('success')->assertSessionMissing('attendance_result');
        $this->assertSame($before, $this->history());
    }

    private function close(string $writer, HrAttendanceSession $session, Carbon $end, int $breakMinutes): HrAttendanceSession
    {
        $service = app(AttendanceService::class);
        return match ($writer) {
            'clock_out' => $service->clockOut($this->worker, $session, ['clock_out_at' => $end]),
            'admin_end' => $service->adminEndSession($this->manager, $session, 'Chronology control.', $end),
            'correct' => $service->correctSession($this->manager, $session, $end, $breakMinutes, 'Chronology control.'),
        };
    }

    private function runningBreak(Carbon $start, Carbon $eventStart): array
    {
        $session = HrAttendanceSession::create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'manual',
            'break_minutes' => 7, 'break_count' => 1, 'break_started_at' => $start, 'created_by' => $this->worker->id]);
        $event = HrAttendanceBreakEvent::create(['session_id' => $session->id, 'started_at' => $eventStart,
            'minutes' => 0, 'created_by' => $this->worker->id]);
        return [$session, $event];
    }

    private function staff(array $grants): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'break-closure-'.Str::uuid(), 'label' => 'Break closure fixture', 'type' => 'custom', 'level' => 10]);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $grant],
                ['description' => $grant, 'group' => 'timesheets', 'module' => 'Operations']));
        }
        $worker->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        return $worker->fresh();
    }

    private function history(): array
    {
        return collect(['hr_attendance_sessions', 'hr_attendance_break_events', 'hr_time_entries',
            'hr_time_entry_amendments', 'timesheets', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
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
