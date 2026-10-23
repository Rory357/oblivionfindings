<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Http\Controllers\AttendanceController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Real break writes and root commits; no service/permission/payroll mocks. */
class AttendanceBreakRecordedResultTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private User $worker;
    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 02:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff($this->site);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_json_reports_the_exact_acted_event_and_committed_session_without_relations_or_flash(): void
    {
        $session = $this->breakSession();
        $this->commitFixtures();
        $start = $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id]);
        $start->assertOk()->assertSessionMissing('success');
        $event = HrAttendanceBreakEvent::where('session_id', $session->id)->sole();
        $this->assertFrame($start->json(), 'start_break', $session->fresh(), $event);
        $this->assertSame(0, $start->json('break_event.minutes'));
        $this->assertSame(1, $start->json('session.break_count'));
        $this->assertNull($start->json('break_event.ended_at'));
        $this->assertPrivate($start);

        $this->travel(17)->minutes();
        $end = $this->postJson('/attendance/break/end', ['session_id' => $session->id]);
        $end->assertOk()->assertSessionMissing('success');
        $this->assertFrame($end->json(), 'end_break', $session->fresh(), $event->fresh());
        $this->assertSame($event->id, $end->json('break_event.id'));
        $this->assertSame(17, $end->json('break_event.minutes'));
        $this->assertSame(24, $end->json('session.break_minutes'));
        $this->assertNull($end->json('session.break_started_at'));
        $this->assertPrivate($end);
    }

    #[DataProvider('legacyRequests')]
    public function test_default_inertia_and_nonpreferred_json_callers_keep_the_existing_redirect(string $action, array $headers): void
    {
        $session = $this->breakSession($action === 'end');
        $this->actingAs($this->worker)->from('/attendance')->post('/attendance/break/'.$action, ['session_id' => $session->id], $headers)
            ->assertRedirect('/attendance')->assertSessionHas('success', $action === 'start' ? 'Break started.' : 'Break ended.');
    }

    public static function legacyRequests(): array
    {
        return [
            'default start' => ['start', []], 'default end' => ['end', []],
            'inertia start' => ['start', ['Accept' => 'application/json', 'X-Inertia' => 'true']],
            'inertia end' => ['end', ['Accept' => 'application/json', 'X-Inertia' => 'true']],
            'html preferred' => ['start', ['Accept' => 'text/html, application/json;q=0.5']],
        ];
    }

    public function test_json_requires_exact_session_but_legacy_omission_still_resolves_the_workers_open_session(): void
    {
        $session = $this->breakSession();
        $before = $this->history($session);
        $this->actingAs($this->worker)->postJson('/attendance/break/start', [])->assertUnprocessable()
            ->assertJsonPath('code', 'break_rejected')->assertJsonValidationErrors('session_id');
        $this->assertSame($before, $this->history($session));
        $this->post('/attendance/break/start')->assertRedirect()->assertSessionHas('success', 'Break started.');
        $this->assertNotNull($session->fresh()->break_started_at);
    }

    public function test_zero_minute_end_preserves_a_legacy_zero_break_count(): void
    {
        $session = $this->breakSession(true, ['break_started_at' => now(), 'break_count' => 0]);
        $this->commitFixtures();
        $reply = $this->actingAs($this->worker)->postJson('/attendance/break/end', ['session_id' => $session->id])->assertOk();
        $this->assertSame(0, $reply->json('break_event.minutes'));
        $this->assertSame(0, $reply->json('session.break_count'));
        $this->assertSame(7, $reply->json('session.break_minutes'));
    }

    #[DataProvider('actions')]
    public function test_repeated_action_is_a_known_rejection_without_a_second_event_or_increment(string $action): void
    {
        $session = $this->breakSession($action === 'end');
        $this->commitFixtures();
        $this->actingAs($this->worker)->postJson('/attendance/break/'.$action, ['session_id' => $session->id])->assertOk();
        $before = $this->history($session);
        $this->postJson('/attendance/break/'.$action, ['session_id' => $session->id])->assertUnprocessable()->assertJsonValidationErrors('break');
        $this->assertSame($before, $this->history($session));
    }

    public static function actions(): array
    {
        return ['start' => ['start'], 'end' => ['end']];
    }

    #[DataProvider('refusals')]
    public function test_real_boolean_save_refusal_rolls_back_both_break_rows_and_audit(string $action, string $row): void
    {
        $session = $this->breakSession($action === 'end');
        $eventId = $session->breakEvents()->value('id');
        $this->commitFixtures();
        $reached = false;
        if ($row === 'session') {
            HrAttendanceSession::updating(function (HrAttendanceSession $candidate) use ($session, &$reached) {
                if ((int) $candidate->id === (int) $session->id) {
                    $reached = true;
                    return false;
                }
            });
        } elseif ($action === 'start') {
            HrAttendanceBreakEvent::creating(function (HrAttendanceBreakEvent $candidate) use ($session, &$reached) {
                if ((int) $candidate->session_id === (int) $session->id) {
                    $reached = true;
                    return false;
                }
            });
        } else {
            HrAttendanceBreakEvent::updating(function (HrAttendanceBreakEvent $candidate) use ($eventId, &$reached) {
                if ((int) $candidate->id === (int) $eventId) {
                    $reached = true;
                    return false;
                }
            });
        }
        $before = $this->history($session);
        $reply = $this->actingAs($this->worker)->postJson('/attendance/break/'.$action, ['session_id' => $session->id]);
        $reply->assertUnprocessable()->assertJsonPath('code', 'break_rejected')
            ->assertJsonValidationErrors('break')->assertJsonMissingPath('break_event');
        $this->assertTrue($reached);
        $this->assertSame($before, $this->history($session));
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public static function refusals(): array
    {
        return ['start session' => ['start', 'session'], 'start event' => ['start', 'event'],
            'end event' => ['end', 'event'], 'end session after event write' => ['end', 'session']];
    }

    public function test_legacy_boolean_event_refusal_returns_existing_error_bag_without_false_success(): void
    {
        $session = $this->breakSession();
        HrAttendanceBreakEvent::creating(function (HrAttendanceBreakEvent $event) use ($session) {
            return (int) $event->session_id === (int) $session->id ? false : null;
        });
        $before = $this->history($session);
        $this->actingAs($this->worker)->post('/attendance/break/start', ['session_id' => $session->id])
            ->assertRedirect()->assertSessionHasErrors('break')->assertSessionMissing('success');
        $this->assertSame($before, $this->history($session));
    }

    public function test_a_saved_callback_corrupting_the_acted_event_cannot_leave_half_written_evidence(): void
    {
        $session = $this->breakSession();
        $this->commitFixtures();
        $reached = false;
        HrAttendanceBreakEvent::created(function (HrAttendanceBreakEvent $event) use ($session, &$reached) {
            if ((int) $event->session_id === (int) $session->id) {
                $reached = true;
                DB::table($event->getTable())->where('id', $event->id)->update(['minutes' => 99]);
            }
        });
        $before = $this->history($session);
        $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id])
            ->assertUnprocessable()->assertJsonValidationErrors('break');
        $this->assertTrue($reached);
        $this->assertSame($before, $this->history($session));
    }

    #[DataProvider('outerOutcomes')]
    public function test_nested_command_never_certifies_root_commit_even_if_the_outer_transaction_later_commits(bool $commit): void
    {
        $session = $this->breakSession();
        $this->commitFixtures();
        DB::beginTransaction();
        try {
            $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id])
                ->assertStatus(409)->assertJsonPath('code', 'break_result_unconfirmed')->assertJsonMissingPath('break_event');
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame($commit, $session->fresh()->break_started_at !== null);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function outerOutcomes(): array
    {
        return ['outer commit' => [true], 'outer rollback' => [false]];
    }

    #[DataProvider('withdrawals')]
    public function test_postcommit_authority_withdrawal_denies_result_without_undoing_the_real_break(string $withdraw): void
    {
        $session = $this->breakSession();
        $permission = Permission::where('key', 'timesheets.create')->sole();
        $this->commitFixtures();
        $reached = false;
        HrAttendanceSession::updated(function (HrAttendanceSession $candidate) use ($session, $withdraw, $permission, &$reached) {
            if ((int) $candidate->id === (int) $session->id && ! $reached) {
                $reached = true;
                DB::afterCommit(function () use ($withdraw, $permission) {
                    if ($withdraw === 'permission') {
                        $this->worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
                    } else {
                        $this->worker->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
                    }
                });
            }
        });
        $reply = $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id]);
        $reply->assertStatus($withdraw === 'permission' ? 403 : 404)->assertJsonMissingPath('break_event');
        $this->assertTrue($reached);
        $this->assertNotNull($session->fresh()->break_started_at);
        $this->assertSame(1, HrAttendanceBreakEvent::where('session_id', $session->id)->count());
    }

    public static function withdrawals(): array
    {
        return ['fresh permission' => ['permission'], 'current profile/site scope' => ['profile']];
    }

    #[DataProvider('lateFailures')]
    public function test_after_commit_exception_is_unconfirmed_and_never_a_retryable_rejection(string $kind): void
    {
        $session = $this->breakSession();
        $this->commitFixtures();
        $reached = false;
        HrAttendanceSession::updated(function (HrAttendanceSession $candidate) use ($session, $kind, &$reached) {
            if ((int) $candidate->id === (int) $session->id && ! $reached) {
                $reached = true;
                DB::afterCommit(function () use ($kind) {
                    if ($kind === 'logic') {
                        throw new \LogicException('Injected committed break callback failure.');
                    }
                    throw \Illuminate\Validation\ValidationException::withMessages(['break' => 'Injected committed callback validation.']);
                });
            }
        });
        $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id])
            ->assertStatus(409)->assertJsonPath('code', 'break_result_unconfirmed')->assertJsonMissingPath('errors');
        $this->assertTrue($reached);
        $this->assertNotNull($session->fresh()->break_started_at);
        $this->assertSame(1, HrAttendanceBreakEvent::where('session_id', $session->id)->count());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public static function lateFailures(): array
    {
        return ['logic after commit' => ['logic'], 'validation after commit' => ['validation']];
    }

    public function test_missing_writer_frame_is_unconfirmed_and_does_not_reload_a_substitute_event(): void
    {
        $session = $this->breakSession();
        $this->commitFixtures();
        $recorded = app(AttendanceService::class)->startBreak($this->worker, $session);
        $before = $this->history($session);
        $recorded->unsetRelation('attendanceBreakCommandEvent');
        $reply = (new ReflectionMethod(AttendanceController::class, 'breakResult'))
            ->invoke(app(AttendanceController::class), $this->worker, $recorded, 'start_break', true);
        $this->assertSame(409, $reply->getStatusCode());
        $this->assertSame('break_result_unconfirmed', $reply->getData(true)['code']);
        $this->assertSame($before, $this->history($session));
    }

    public function test_foreign_worker_and_foreign_site_direct_objects_are_concealed_before_validation(): void
    {
        $other = $this->staff($this->site);
        $foreignWorker = $this->breakSession(false, ['user_id' => $other->id, 'created_by' => $other->id]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreignSiteSession = $this->breakSession(false, ['site_id' => $foreignSite->id]);
        foreach ([$foreignWorker, $foreignSiteSession] as $session) {
            $before = $this->history($session);
            $this->actingAs($this->worker)->postJson('/attendance/break/start', ['session_id' => $session->id])
                ->assertNotFound()->assertJsonMissingPath('break_event');
            $this->assertSame($before, $this->history($session));
        }
    }

    private function staff(Site $site): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'break-result-'.Str::uuid(), 'label' => 'Break result fixture', 'type' => 'custom', 'level' => 10]);
        $permission = Permission::firstOrCreate(['key' => 'timesheets.create'], ['description' => 'timesheets.create', 'group' => 'timesheets', 'module' => 'Operations']);
        $role->permissions()->attach($permission);
        $worker->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null,
            'created_by' => $worker->id, 'updated_by' => $worker->id]);
        return $worker->fresh();
    }

    private function breakSession(bool $active = false, array $attributes = []): HrAttendanceSession
    {
        $session = HrAttendanceSession::create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'manual',
            'break_started_at' => $active ? now()->subMinutes(5) : null, 'break_minutes' => 7,
            'break_count' => $active ? 1 : 0, 'created_by' => $this->worker->id, ...$attributes]);
        if ($active) {
            HrAttendanceBreakEvent::create(['session_id' => $session->id, 'started_at' => $session->break_started_at,
                'ended_at' => null, 'minutes' => 0, 'created_by' => $session->user_id]);
        }
        return $session->fresh();
    }

    private function history(HrAttendanceSession $session): array
    {
        return [(array) DB::table($session->getTable())->where('id', $session->id)->first(),
            DB::table('hr_attendance_break_events')->where('session_id', $session->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('audit_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
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
        // RefreshDatabase's testing manager treats the first open transaction as its own
        // wrapper and runs afterCommit callbacks at once. That wrapper is committed now,
        // so the request's real transaction needs the production manager's timing.
        $manager = new \Illuminate\Database\DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $this->app->instance('db.transactions', $manager);
    }

    private function assertPrivate($response): void
    {
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    private function assertFrame(array $reply, string $action, HrAttendanceSession $session, HrAttendanceBreakEvent $event): void
    {
        $this->assertSame(['schema_version', 'scope', 'action', 'actor_id', 'session', 'break_event'], array_keys($reply));
        $this->assertSame(1, $reply['schema_version']);
        $this->assertSame('attendance_break_recorded_result', $reply['scope']);
        $this->assertSame($action, $reply['action']);
        $this->assertSame($this->worker->id, $reply['actor_id']);
        $this->assertSame(['id', 'shift_id', 'status', 'break_started_at', 'break_minutes', 'break_count'], array_keys($reply['session']));
        $this->assertSame(['id', 'session_id', 'started_at', 'ended_at', 'minutes'], array_keys($reply['break_event']));
        $this->assertSame((int) $session->id, $reply['session']['id']);
        $this->assertSame('open', $reply['session']['status']);
        $this->assertSame((int) $event->id, $reply['break_event']['id']);
        $this->assertSame((int) $session->id, $reply['break_event']['session_id']);
        $this->assertSame($event->started_at->toIso8601String(), $reply['break_event']['started_at']);
        $this->assertSame($event->ended_at?->toIso8601String(), $reply['break_event']['ended_at']);
        $this->assertSame($session->break_started_at?->toIso8601String(), $reply['session']['break_started_at']);
        $this->assertSame((int) $session->break_minutes, $reply['session']['break_minutes']);
    }
}
