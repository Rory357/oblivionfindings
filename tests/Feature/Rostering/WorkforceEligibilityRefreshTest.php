<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\HsTrainingRequirement;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\EligibilityResult;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Eligibility\WorkforceEligibilityRefreshPresenter;
use App\Services\ShiftStaffEligibilityService;
use Carbon\Carbon;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class WorkforceEligibilityRefreshTest extends TestCase
{
    use RefreshDatabase;

    private bool $retryFixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:00:00', 'UTC'));
        Queue::fake();
        Notification::fake();
    }

    private function commitRetryFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $connection->transactionLevel());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $this->retryFixturesCommitted = true;
        Queue::fake();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->retryFixturesCommitted && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_additive_refresh_and_progress_migrations_are_applied_after_the_native_snapshot(): void
    {
        $this->assertTrue(Schema::hasTable('workforce_eligibility_rechecks'));
        $this->assertTrue(Schema::hasTable('workforce_eligibility_observations'));
        $this->assertTrue(Schema::hasColumn('workforce_eligibility_rechecks', 'last_shift_id'));
        $this->assertTrue(Schema::hasColumn('workforce_eligibility_observations', 'observed_versions'));
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', [
            '2026_10_05_210000_create_workforce_eligibility_refresh_tables',
            '2026_10_05_210100_add_workforce_refresh_progress',
        ])->count());
    }

    public function test_source_intent_and_queue_dispatch_roll_back_with_the_source_and_wait_for_commit(): void
    {
        $worker = $this->worker($this->site());
        $this->clearRefresh();
        try {
            DB::transaction(function () use ($worker): void {
                StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'issued_at' => '2026-01-01']);
                $this->assertSame(1, WorkforceEligibilityRecheck::count());
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                throw new RuntimeException('Roll back the source command.');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        $this->assertSame(0, StaffCredential::where('user_id', $worker->id)->count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);

        DB::transaction(function () use ($worker): void {
            StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'issued_at' => '2026-01-01']);
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        });
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
        $this->assertSame('pending', WorkforceEligibilityRecheck::firstOrFail()->status);
    }

    public function test_duplicate_state_is_coalesced_but_same_second_revoke_and_restore_have_new_versions(): void
    {
        $worker = $this->worker($this->site());
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'expires_at' => '2026-12-31']);
        $request = $this->sourceRequest($credential);
        $this->assertSame(1, $request->source_version);
        app(WorkforceEligibilityRefresh::class)->sourceChanged($credential);
        $credential->update(['notes' => 'Private source notes do not change operational evidence.']);
        $this->assertSame(1, $request->fresh()->source_version);
        $credential->update(['expires_at' => '2026-10-01']);
        $credential->update(['expires_at' => '2026-12-31']);
        $this->assertSame(3, $request->fresh()->source_version);
        $this->assertSame(1, WorkforceEligibilityRecheck::where('source_type', 'staff_credentials')->where('source_id', $credential->id)->count());
    }

    public function test_committed_membership_changes_refresh_both_relation_directions_without_changing_grants(): void
    {
        $worker = $this->worker($this->site());
        $role = Role::create(['name' => 'refresh-membership', 'label' => 'Refresh membership', 'type' => 'custom', 'level' => 10]);
        $this->clearRefresh();
        try {
            DB::transaction(function () use ($worker, $role): void {
                $worker->roles()->attach($role);
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                throw new RuntimeException('Roll back membership.');
            });
        } catch (RuntimeException) {
        }
        $this->assertFalse($worker->fresh()->hasRole($role->name));
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        DB::transaction(fn () => $worker->roles()->syncWithoutDetaching([$role->id]));
        $request = WorkforceEligibilityRecheck::where('source_type', 'role_membership')->where('source_id', $worker->id)->firstOrFail();
        $this->assertSame([$worker->id], $request->user_ids);
        $this->assertTrue($worker->fresh()->hasRole($role->name));
        $this->assertFalse($worker->fresh()->canDo('shifts.update'));
        DB::transaction(fn () => $role->users()->detach($worker->id));
        $this->assertSame(2, $request->fresh()->source_version);
        $this->assertFalse($worker->fresh()->hasRole($role->name));
    }

    public function test_superseded_work_skips_and_the_latest_version_checks_current_evidence(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker);
        $this->clearRefresh();
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'expires_at' => '2026-10-01']);
        $old = $this->sourceRequest($credential);
        $credential->update(['expires_at' => '2027-12-31']);
        $latest = $old->fresh();
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($credential): void {
            $mock->shouldReceive('evaluate')->once()->andReturnUsing(function () use ($credential): EligibilityResult {
                $this->assertSame('2027-12-31', $credential->fresh()->expires_at->toDateString());

                return $this->clearResult();
            });
        });
        $refresh = app(WorkforceEligibilityRefresh::class);
        $refresh->process($old->id, $old->source_version);
        $this->assertSame('pending', $old->fresh()->status);
        $this->assertSame(0, $old->fresh()->attempts);
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $refresh->process($latest->id, $latest->source_version);
        $this->assertSame('clear', WorkforceEligibilityObservation::where('shift_id', $shift->id)->firstOrFail()->posture);
        $this->assertSame('completed', $latest->fresh()->status);
    }

    public function test_targeted_refresh_includes_current_and_next_month_duties_without_mutating_assignments(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $other = $this->worker($site);
        $current = $this->shift($site, $worker, ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'in_progress']);
        $future = $this->shift($site, $worker, ['starts_at' => now()->addDays(35), 'ends_at' => now()->addDays(35)->addHours(2), 'published_at' => now()]);
        $cancelled = $this->shift($site, $worker, ['status' => 'cancelled']);
        $completed = $this->shift($site, $worker, ['status' => 'completed', 'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHour(), 'actual_starts_at' => now()->subHours(2), 'actual_ends_at' => now()->subHour()]);
        $unrelated = $this->shift($site, $other);
        $before = Shift::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->clearRefresh();
        $this->mock(ShiftStaffEligibilityService::class, fn ($mock) => $mock->shouldReceive('evaluate')->twice()->andReturn($this->clearResult()));
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'expires_at' => '2027-12-31']);
        $this->process($this->sourceRequest($credential));
        $this->assertEqualsCanonicalizing([$current->id, $future->id], WorkforceEligibilityObservation::pluck('shift_id')->all());
        $this->assertSame($before, Shift::orderBy('id')->get()->map->getRawOriginal()->all());
        foreach ([$cancelled, $completed, $unrelated] as $excluded) {
            $this->assertFalse(WorkforceEligibilityObservation::where('shift_id', $excluded->id)->exists());
        }
    }

    public function test_real_canonical_credential_expiry_and_renewal_correct_only_the_observation(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker, ['starts_at' => now()->addDays(35), 'ends_at' => now()->addDays(35)->addHours(2)]);
        $role = $worker->roles()->firstOrFail();
        $requirement = HrComplianceRequirement::factory()->create(['code' => 'REFRESH-CREDENTIAL', 'name' => 'Refresh credential', 'check_type' => 'credential', 'hard_stop' => true, 'is_active' => true]);
        HrComplianceMatrix::create(['requirement_id' => $requirement->id, 'role' => $role->name, 'site_type' => 'all', 'is_mandatory' => true]);
        $before = $shift->fresh()->getRawOriginal();
        $this->clearRefresh();
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => $requirement->code, 'issued_at' => '2026-01-01', 'expires_at' => '2026-10-01', 'notes' => 'Private credential note']);
        $this->process($this->sourceRequest($credential));
        $this->assertSame('blocked', $this->observation($shift)->posture);
        $credential->update(['expires_at' => '2027-12-31']);
        $this->process($this->sourceRequest($credential));
        $this->assertSame(0, $this->observation($shift)->block_count);
        $this->assertContains($this->observation($shift)->posture, ['clear', 'warning']);
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertStringNotContainsString('Private credential note', json_encode(ShiftSignal::pluck('payload')->all()));
    }

    public function test_failed_evaluation_keeps_last_success_and_retry_restores_current_freshness(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker);
        $this->clearRefresh();
        $calls = 0;
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('evaluate')->times(3)->andReturnUsing(function () use (&$calls): EligibilityResult {
                if (++$calls === 2) {
                    throw new RuntimeException('PRIVATE-CLINICAL-CASE-CONTENT');
                }

                return $this->clearResult();
            });
        });
        $refresh = app(WorkforceEligibilityRefresh::class);
        $this->process($refresh->requestShift($shift));
        $success = $this->observation($shift)->last_successful_at;
        $retry = $refresh->requestShift($shift);
        $this->expectUnavailable($retry);
        $observation = $this->observation($shift);
        $this->assertSame('clear', $observation->posture);
        $this->assertEquals($success, $observation->last_successful_at);
        $this->assertSame('evaluation_unavailable', $observation->error_code);
        $this->assertSame('failed', $retry->fresh()->status);
        $presenter = app(WorkforceEligibilityRefreshPresenter::class);
        $this->assertSame('failed', $presenter->present($shift->fresh(), $worker)['freshness']);
        $this->assertStringNotContainsString('PRIVATE-CLINICAL-CASE-CONTENT', json_encode([$retry->fresh()->getAttributes(), $observation->getAttributes()]));
        $this->process($retry->fresh());
        $this->assertSame('current', $presenter->present($shift->fresh(), $worker)['freshness']);
        $this->assertNull($this->observation($shift)->error_code);
        $this->assertSame(2, $retry->fresh()->attempts);
    }

    public function test_new_source_revision_is_pending_even_when_all_changes_share_one_timestamp(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker);
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => 'refresh-regression', 'expires_at' => '2027-01-01']);
        $this->mock(ShiftStaffEligibilityService::class, fn ($mock) => $mock->shouldReceive('evaluate')->twice()->andReturn($this->clearResult()));
        $this->process($this->sourceRequest($credential));
        $presenter = app(WorkforceEligibilityRefreshPresenter::class);
        $this->assertSame('current', $presenter->present($shift->fresh(), $worker)['freshness']);
        $credential->update(['expires_at' => '2027-02-01']);
        $this->assertSame('pending', $presenter->present($shift->fresh(), $worker)['freshness']);
        // A bounded successful manual check observes all current changes without clearing other work.
        $this->process(app(WorkforceEligibilityRefresh::class)->requestShift($shift));
        $this->assertSame('pending', $this->sourceRequest($credential)->status);
        $this->assertSame('current', $presenter->present($shift->fresh(), $worker)['freshness']);
    }

    public function test_review_signal_deduplication_correction_and_private_message_redaction_preserve_independent_signals(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker);
        $independent = ShiftSignal::create(['shift_id' => $shift->id, 'site_id' => $site->id, 'user_id' => $worker->id,
            'signal_type' => 'shift_no_show', 'severity_hint' => 'high', 'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'independent-refresh-signal'), 'payload' => ['reviewed' => true]]);
        $before = $independent->fresh()->getRawOriginal();
        $this->clearRefresh();
        $blocked = EligibilityResult::fromChecks([['rule' => 'driver_licence', 'passed' => false, 'severity' => 'block', 'overrideable' => false, 'message' => 'PRIVATE suspension reason']]);
        $this->mock(ShiftStaffEligibilityService::class, fn ($mock) => $mock->shouldReceive('evaluate')->times(4)->andReturn($blocked, $blocked, $this->clearResult(), $blocked));
        $refresh = app(WorkforceEligibilityRefresh::class);
        $this->process($refresh->requestShift($shift));
        $this->process($refresh->requestShift($shift));
        $this->assertSame(1, ShiftSignal::where('signal_type', RecalculateFutureShiftEligibility::SIGNAL_TYPE)->count());
        $this->process($refresh->requestShift($shift));
        $this->assertSame('clear', $this->observation($shift)->posture);
        $this->assertSame(1, ShiftSignal::where('signal_type', RecalculateFutureShiftEligibility::SIGNAL_TYPE)->count());
        $this->assertSame($before, $independent->fresh()->getRawOriginal());
        $this->process($refresh->requestShift($shift));
        $this->assertSame(2, ShiftSignal::where('signal_type', RecalculateFutureShiftEligibility::SIGNAL_TYPE)->count());
        $this->assertStringNotContainsString('PRIVATE suspension reason', json_encode(ShiftSignal::pluck('payload')->all()));
        $this->assertSame($before, $independent->fresh()->getRawOriginal());
    }

    public function test_account_approval_revocation_is_visible_review_evidence_and_never_unassigns(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker);
        $before = $shift->fresh()->getRawOriginal();
        $this->clearRefresh();
        $this->mock(ShiftStaffEligibilityService::class, fn ($mock) => $mock->shouldReceive('evaluate')->once()->andReturn($this->clearResult()));
        $worker->update(['approved_at' => null]);
        $this->process($this->sourceRequest($worker));
        $this->assertSame('review', $this->observation($shift)->posture);
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
    }

    public function test_scoped_read_and_exact_retry_permission_deny_hidden_and_view_only_actions(): void
    {
        $local = $this->site();
        $remote = $this->site();
        $viewer = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny']);
        $localShift = $this->shift($local, $this->worker($local));
        $remoteShift = $this->shift($remote, $this->worker($remote));
        $this->clearRefresh();
        $this->actingAs($viewer)->getJson(route('operations.workforce.eligibility-refresh.index', ['shift_ids' => [$localShift->id, $remoteShift->id]]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shift_id', $localShift->id)
            ->assertJsonPath('data.0.can_retry', false)->assertJsonPath('data.0.posture', 'unverified');
        $this->actingAs($viewer)->postJson(route('operations.workforce.eligibility-refresh.retry', $localShift))->assertForbidden();
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        $writer = $this->worker($local, ['rostering.viewAny', 'shifts.manageAny', 'shifts.update']);
        $this->clearRefresh();
        $this->actingAs($writer)->postJson(route('operations.workforce.eligibility-refresh.retry', $remoteShift))->assertForbidden();
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        $this->commitRetryFixtures();
        $this->actingAs($writer)->postJson(route('operations.workforce.eligibility-refresh.retry', $localShift))
            ->assertStatus(202)->assertExactJson(['shift_id' => $localShift->id, 'queued' => true]);
        $this->assertSame(1, WorkforceEligibilityRecheck::count());
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
        $this->actingAs($writer)->postJson(route('operations.workforce.eligibility-refresh.retry', $localShift))->assertStatus(202);
        $this->assertSame(1, WorkforceEligibilityRecheck::count());
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
    }

    public function test_matrix_scopes_use_canonical_rbac_and_removal_stages_a_committed_refresh(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $role = $worker->roles()->firstOrFail();
        $requirement = HrComplianceRequirement::factory()->create(['is_active' => true]);
        $actor = $this->worker($site, ['hr.compliance.view', 'hr.compliance.manage']);
        $this->clearRefresh();
        $entry = HrComplianceMatrix::create(['requirement_id' => $requirement->id, 'role' => $role->name, 'site_type' => 'all', 'is_mandatory' => true]);
        $this->assertSame('support_worker', $worker->role);
        $this->assertSame([$worker->id], $this->sourceRequest($entry)->user_ids);
        $version = $this->sourceRequest($entry)->source_version;
        $this->actingAs($actor)->post(route('hr.compliance.matrix.update'), ['requirement_id' => $requirement->id,
            'role' => $role->name, 'site_type' => 'all', 'is_mandatory' => true, 'action' => 'unassign'])->assertRedirect();
        $this->assertFalse(HrComplianceMatrix::whereKey($entry->id)->exists());
        $this->assertSame($version + 1, $this->sourceRequest($entry)->source_version);
    }

    #[DataProvider('matrixRootCommands')]
    public function test_matrix_removal_and_refresh_intent_share_the_actual_root_boundary(string $failure): void
    {
        $this->assertSame(0, WorkforceEligibilityRecheck::count(), 'Unexpected committed recovery metadata must fail before fixtures.');
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $site = $this->site();
        $worker = $this->worker($site);
        $role = $worker->roles()->firstOrFail();
        $actor = $this->worker($site, ['hr.compliance.view', 'hr.compliance.manage']);
        $requirement = HrComplianceRequirement::factory()->create(['is_active' => true]);
        $this->clearRefresh();
        $entry = HrComplianceMatrix::create(['requirement_id' => $requirement->id, 'role' => $role->name,
            'site_type' => 'all', 'is_mandatory' => true, 'notes' => 'Retained matrix history']);
        $this->assertSame([$worker->id], $this->sourceRequest($entry)->user_ids);
        $this->commitRetryFixtures();
        $before = $this->matrixCommandState();
        $intent = $this->sourceRequest($entry);
        $version = $intent->source_version;
        $connection = DB::connection();
        $testManager = $this->app['db.transactions'];
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        $commandActive = true;
        $vetoActive = $failure === 'veto';
        HrComplianceMatrix::deleting(function (HrComplianceMatrix $candidate) use (&$vetoActive, $entry) {
            return $vetoActive && $candidate->id === $entry->id ? false : null;
        });
        $intentWriteSeen = false;
        $connection->beforeExecuting(function (string $sql) use ($failure, &$commandActive, &$intentWriteSeen): void {
            if ($commandActive && str_starts_with($sql, 'update `workforce_eligibility_rechecks`')) {
                $intentWriteSeen = true;
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                if ($failure === 'stage') {
                    throw new RuntimeException('Matrix refresh staging unavailable');
                }
            }
        });
        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
            $body = ['requirement_id' => $requirement->id, 'role' => $role->name,
                'site_type' => 'all', 'is_mandatory' => true, 'action' => 'unassign'];
            if ($failure === 'stage') {
                try {
                    $this->withoutExceptionHandling()->actingAs($actor)->post(route('hr.compliance.matrix.update'), $body);
                    $this->fail('A failed durable refresh write must not report a removed matrix entry.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Matrix refresh staging unavailable', $exception->getMessage());
                }
            } elseif ($failure === 'veto') {
                $this->actingAs($actor)->post(route('hr.compliance.matrix.update'), $body)
                    ->assertConflict()->assertSessionMissing('success');
            } else {
                $this->actingAs($actor)->post(route('hr.compliance.matrix.update'), $body)
                    ->assertRedirect()->assertSessionHas('success', 'Matrix entry removed.')->assertSessionHasNoErrors();
            }
            $commandActive = false;
            $vetoActive = false;
            $this->assertSame($failure !== 'veto', $intentWriteSeen);
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
            if ($failure !== 'none') {
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                $this->assertSame($before, $this->matrixCommandState());
            } else {
                $this->assertFalse(HrComplianceMatrix::whereKey($entry->id)->exists());
                $current = $intent->fresh();
                $this->assertSame($version + 1, $current->source_version);
                $this->assertNotSame($intent->source_fingerprint, $current->source_fingerprint);
                $this->assertSame([$worker->id], $current->user_ids);
                $this->assertSame('pending', $current->status);
                $this->assertNull($current->completed_at);
                Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
                Queue::assertPushed(RefreshWorkforceEligibility::class,
                    fn ($job) => $job->recheckId === $intent->id && $job->sourceVersion === $version + 1);
                $after = $this->matrixCommandState();
                foreach (['users', 'hr_employee_profiles', 'roles', 'role_user', 'hr_compliance_requirements', 'workforce_eligibility_observations'] as $table) {
                    $this->assertSame($before[$table], $after[$table], $table.' is unchanged by matrix removal.');
                }
                Queue::fake();
                $this->actingAs($actor)->post(route('hr.compliance.matrix.update'), $body)
                    ->assertRedirect()->assertSessionHas('success', 'Matrix entry removed.')->assertSessionHasNoErrors();
                $this->assertSame($after, $this->matrixCommandState(), 'A zero-match removal remains an idempotent success.');
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            }
        } finally {
            $commandActive = false;
            $vetoActive = false;
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } finally {
                $this->app->instance('db.transactions', $testManager);
                $connection->setTransactionManager($testManager);
            }
        }
    }

    public static function matrixRootCommands(): array
    {
        return ['actual committed removal' => ['none'], 'staging failure rolls back removal and audit' => ['stage'],
            'model veto preserves removal and refresh state' => ['veto']];
    }

    private function matrixCommandState(): array
    {
        $state = [];
        foreach (['users', 'hr_employee_profiles', 'roles', 'role_user', 'hr_compliance_requirements',
            'hr_compliance_matrix', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'audit_logs'] as $table) {
            $state[$table] = DB::table($table)->orderBy($table === 'role_user' ? 'user_id' : 'id')
                ->when($table === 'role_user', fn ($query) => $query->orderBy('role_id'))
                ->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    public function test_moved_requirement_scope_retains_both_old_and_new_sites_until_processed(): void
    {
        $first = $this->site();
        $second = $this->site();
        $requirement = HsTrainingRequirement::create(['name' => 'Scoped refresh requirement', 'code' => 'REFRESH-SCOPE',
            'scope_type' => 'site', 'scope_site_ids' => [$first->id], 'enforcement_mode' => 'warn', 'is_active' => true]);
        $requirement->update(['scope_site_ids' => [$second->id]]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->sourceRequest($requirement)->site_ids);
        $this->assertFalse($this->sourceRequest($requirement)->all_assigned);
    }

    public function test_nightly_backstop_stages_current_duties_and_recovery_retains_terminal_failures_for_review(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker, ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'in_progress']);
        $this->shift($site, $worker, ['starts_at' => now()->addDays(35), 'ends_at' => now()->addDays(35)->addHours(2)]);
        $this->clearRefresh();
        $refresh = app(WorkforceEligibilityRefresh::class);
        (new RecalculateFutureShiftEligibility)->handle($refresh);
        $this->assertSame(1, WorkforceEligibilityRecheck::count());
        $this->process(WorkforceEligibilityRecheck::firstOrFail());
        $request = WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->firstOrFail();
        $this->assertSame([$shift->id], $request->shift_ids);
        $request->update(['status' => 'processing', 'attempts' => 1, 'last_attempt_at' => now()->subMinutes(11)]);
        Queue::fake();
        $this->assertSame(1, $refresh->recover());
        $this->assertSame('worker_interrupted', $request->fresh()->error_code);
        $request->update(['status' => 'failed', 'attempts' => 3, 'available_at' => now()]);
        Queue::fake();
        $this->assertSame(0, $refresh->recover());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        $this->assertSame('failed', $request->fresh()->status);
    }

    public function test_nightly_scan_covers_current_and_all_published_future_employee_duties_only(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $current = $this->shift($site, $worker, ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'in_progress']);
        $near = $this->shift($site, $worker);
        $published = $this->shift($site, $worker, ['starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(2), 'published_at' => now()]);
        $unpublished = $this->shift($site, $worker, ['starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(2)]);
        $cancelled = $this->shift($site, $worker, ['status' => 'cancelled', 'published_at' => now()]);
        $unassigned = $this->shift($site, $worker, ['user_id' => null, 'published_at' => now()]);
        $stay = $this->shift($site, $worker, ['published_at' => now()]);
        $booking = RespiteBooking::factory()->create(['client_id' => $stay->client_id]);
        // Imported legacy employee-linked stay evidence must remain untouched.
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => $booking->id]);
        $before = Shift::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->clearRefresh();
        $refresh = app(WorkforceEligibilityRefresh::class);
        (new RecalculateFutureShiftEligibility)->handle($refresh);
        $scan = WorkforceEligibilityRecheck::firstOrFail();
        $this->process($scan);
        $this->assertEqualsCanonicalizing([$current->id, $near->id, $published->id],
            WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->pluck('source_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(3, $scan->fresh()->scanned_count);
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $this->assertSame($before, Shift::orderBy('id')->get()->map->getRawOriginal()->all());
        foreach ([$unpublished, $cancelled, $unassigned, $stay] as $excluded) {
            $this->assertFalse(WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->where('source_id', $excluded->id)->exists());
        }
    }

    public function test_nightly_scan_resumes_fixed_chunks_and_coalesces_across_days_and_new_ids(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shifts = collect(range(1, 52))->map(fn ($day) => $this->shift($site, $worker, [
            'starts_at' => now()->addDays(30 + $day), 'ends_at' => now()->addDays(30 + $day)->addHours(2), 'published_at' => now(),
        ]));
        $this->clearRefresh();
        $refresh = app(WorkforceEligibilityRefresh::class);
        $scan = $refresh->requestNightlyScan();
        $oldVersion = $scan->source_version;
        $this->process($scan);
        $current = $scan->fresh();
        $this->assertSame('pending', $current->status);
        $this->assertSame(50, $current->scanned_count);
        $this->assertSame((int) $shifts[49]->id, $current->last_shift_id);
        $this->assertSame($oldVersion + 1, $current->source_version);
        $this->assertSame(50, WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->count());
        Carbon::setTestNow(now()->addDays(2));
        $later = $this->shift($site, $worker, ['starts_at' => now()->addDays(100), 'ends_at' => now()->addDays(100)->addHours(2), 'published_at' => now()]);
        $coalesced = $refresh->requestNightlyScan();
        $this->assertSame($scan->id, $coalesced->id);
        $this->assertSame($shifts->last()->id, (int) $coalesced->source_id);
        $this->assertSame($current->source_version, $coalesced->source_version);
        $refresh->process($scan->id, $oldVersion);
        $this->assertSame(50, $scan->fresh()->scanned_count);
        $this->process($coalesced);
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(52, $scan->fresh()->scanned_count);
        $this->assertSame(52, WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->count());
        $this->assertFalse(WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->where('source_id', $later->id)->exists());
    }

    public function test_sync_nightly_delivery_is_bounded_and_async_recovery_queues_the_next_version(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        foreach (range(1, 52) as $day) {
            $this->shift($site, $worker, ['starts_at' => now()->addDays(30 + $day),
                'ends_at' => now()->addDays(30 + $day)->addHours(2), 'published_at' => now()]);
        }
        $this->clearRefresh();
        $this->mock(ShiftStaffEligibilityService::class, fn ($mock) => $mock->shouldReceive('evaluate')->times(52)->andReturn($this->clearResult()));
        config(['queue.default' => 'sync']);
        $configuredQueue = Queue::getFacadeRoot()->queue;
        $this->assertInstanceOf(QueueManager::class, $configuredQueue);
        Queue::swap($configuredQueue);
        try {
            (new RecalculateFutureShiftEligibility)->handle(app(WorkforceEligibilityRefresh::class));
        } finally {
            Queue::fake();
        }
        $scan = WorkforceEligibilityRecheck::where('source_type', WorkforceEligibilityRefresh::NIGHTLY_SCAN_SOURCE)->firstOrFail();
        $this->assertSame('pending', $scan->status);
        $this->assertSame(50, $scan->scanned_count);
        $this->assertSame(50, WorkforceEligibilityObservation::count());
        $this->assertSame(1, app(WorkforceEligibilityRefresh::class)->recover());
        Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($job) => $job->recheckId === $scan->id && $job->sourceVersion === $scan->source_version);
        $this->process($scan);
        foreach (WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->where('status', 'pending')->get() as $request) {
            $this->process($request);
        }
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(52, $scan->fresh()->scanned_count);
        $this->assertSame(52, WorkforceEligibilityObservation::count());
    }

    public function test_nightly_published_backstop_rechecks_quiet_credential_changes_with_canonical_evidence(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker, ['starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(2), 'published_at' => now()]);
        $requirement = HrComplianceRequirement::factory()->create(['code' => 'QUIET-REFRESH-CREDENTIAL',
            'check_type' => 'credential', 'hard_stop' => true, 'is_active' => true]);
        HrComplianceMatrix::create(['requirement_id' => $requirement->id, 'role' => $worker->roles()->firstOrFail()->name,
            'site_type' => 'all', 'is_mandatory' => true]);
        $credential = StaffCredential::create(['user_id' => $worker->id, 'type' => $requirement->code, 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $this->clearRefresh();
        $refresh = app(WorkforceEligibilityRefresh::class);
        $this->process($refresh->requestShift($shift));
        $this->assertSame(0, $this->observation($shift)->block_count);
        WorkforceEligibilityRecheck::query()->delete();
        DB::table('staff_credentials')->where('id', $credential->id)->update(['expires_at' => '2026-10-01']);
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        $before = $shift->fresh()->getRawOriginal();
        $this->process($refresh->requestNightlyScan());
        $this->process(WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->firstOrFail());
        $this->assertSame('blocked', $this->observation($shift)->posture);
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
    }

    public function test_nightly_published_backstop_rechecks_config_only_fatigue_changes(): void
    {
        $site = $this->site();
        $worker = $this->worker($site);
        $shift = $this->shift($site, $worker, ['starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(2), 'published_at' => now()]);
        $this->clearRefresh();
        $refresh = app(WorkforceEligibilityRefresh::class);
        $this->process($refresh->requestShift($shift));
        $this->assertSame(0, $this->observation($shift)->block_count);
        WorkforceEligibilityRecheck::query()->delete();
        config(['hr.fatigue.max_hours_per_day' => 1]);
        $this->process($refresh->requestNightlyScan());
        $this->process(WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->firstOrFail());
        $this->assertSame('blocked', $this->observation($shift)->posture);
        $this->assertContains('fatigue_daily', array_column($this->observation($shift)->failed_rules, 'rule'));
        $this->assertSame($worker->id, $shift->fresh()->user_id);
    }

    private function site(): Site
    {
        return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function worker(Site $site, array $permissions = []): User
    {
        $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true, 'manager_user_id' => null]);
        $role = Role::create(['name' => 'refresh-'.str()->uuid(), 'label' => 'Refresh regression', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($permissions)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $worker->roles()->attach($role);

        return $worker;
    }

    private function shift(Site $site, User $worker, array $overrides = []): Shift
    {
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);

        return Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $worker->id,
            'service_context_id' => null, 'status' => 'scheduled', 'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2), ...$overrides]);
    }

    private function clearRefresh(): void
    {
        WorkforceEligibilityRecheck::query()->delete();
        WorkforceEligibilityObservation::query()->delete();
        Queue::fake();
    }

    private function sourceRequest($source): WorkforceEligibilityRecheck
    {
        return WorkforceEligibilityRecheck::where('source_type', $source->getTable())->where('source_id', $source->id)->firstOrFail();
    }

    private function process(WorkforceEligibilityRecheck $request): void
    {
        app(WorkforceEligibilityRefresh::class)->process($request->id, $request->source_version);
    }

    private function expectUnavailable(WorkforceEligibilityRecheck $request): void
    {
        try {
            $this->process($request);
            $this->fail('An unavailable evaluation must remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Workforce eligibility refresh is temporarily unavailable.', $exception->getMessage());
        }
    }

    private function observation(Shift $shift): WorkforceEligibilityObservation
    {
        return WorkforceEligibilityObservation::where('shift_id', $shift->id)->firstOrFail();
    }

    private function clearResult(): EligibilityResult
    {
        return new EligibilityResult(true, [], [], [], []);
    }
}
