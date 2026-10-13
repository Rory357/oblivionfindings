<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\StaffCredential;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\WorkforceEligibilityRuntimeProcess as Runtime;
use Tests\TestCase;

/** Separate ordinary runtimes; no evaluator, worker, reservation or lease fake. */
class WorkforceEligibilityAsyncRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $runtimeQueue;

    private User $worker;

    private Site $site;

    private Client $client;

    private StaffCredential $credential;

    private HrComplianceRequirement $requirement;

    /** @var list<Process> */
    private array $ownedProcesses = [];

    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        // Only fixture observers are isolated here. Every guarded child boots
        // the real database queue, persistent cache and canonical evaluator.
        Queue::fake();
        $cleanup = CommittedFixtureCleanup::capture();
        $this->beforeApplicationDestroyed(fn () => $cleanup->restore());
        // Never erase committed recovery metadata left by another scenario.
        $this->assertSame(0, WorkforceEligibilityRecheck::count(), 'Unexpected recovery metadata must fail before fixture creation.');
        $this->assertSame(0, WorkforceEligibilityObservation::count(), 'Unexpected observation metadata must fail before fixture creation.');
        $this->runtimeQueue = 'workforce-eligibility-runtime-'.Str::uuid();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(),
            'end_date' => null, 'manager_user_id' => null, 'created_by' => $this->worker->id,
            'updated_by' => $this->worker->id, 'position_role' => 'support_worker',
        ]);
        $role = Role::create(['name' => 'async-worker-'.Str::uuid(), 'label' => 'Runtime fixture employee', 'type' => 'custom', 'level' => 10]);
        $this->worker->roles()->attach($role);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        foreach (range(0, 6) as $day) {
            StaffAvailability::create(['user_id' => $this->worker->id, 'day_of_week' => $day,
                'starts_at' => '08:00', 'ends_at' => '17:00', 'ends_next_day' => false]);
        }
        $this->requirement = HrComplianceRequirement::factory()->create([
            'code' => 'ASYNC-'.Str::upper(Str::random(12)), 'check_type' => 'credential',
            'hard_stop' => true, 'is_active' => true,
        ]);
        HrComplianceMatrix::create(['requirement_id' => $this->requirement->id,
            'role' => $role->name, 'site_type' => 'all', 'is_mandatory' => true]);
        $this->credential = StaffCredential::create(['user_id' => $this->worker->id,
            'type' => $this->requirement->code, 'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addYears(3)->toDateString()]);
        app(ComplianceMatrixService::class)->evaluateStaff($this->worker->fresh());
        $this->assertSame('compliant', $this->compliance()->status);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->ownedProcesses as $process) {
                if ($process->isRunning()) {
                    // Symfony stops this exact owned process tree on Windows.
                    $process->stop(1);
                }
                $this->assertFalse($process->isRunning(), 'An owned runtime must be fully stopped.');
            }
            Runtime::cleanup($this->runtimeQueue);
            if ($this->fixturesCommitted && DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction(); // RefreshDatabase closes this before committed fixture cleanup.
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_real_source_rollback_and_outer_commit_are_consumed_by_a_separate_database_worker(): void
    {
        $shift = $this->duty(0);
        $before = $this->commitFixtures();
        $credentialBefore = $this->credential->fresh()->getRawOriginal();
        $expiry = now()->addYears(4)->toDateString();

        config(['app.worker_timezone' => 'America/New_York']);
        try {
            $rolledBack = $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id,
                'expires_at' => $expiry, 'rollback' => true]);
            $this->assertSame('America/New_York', $rolledBack['worker_timezone'], 'The ordinary child must retain the configured nondefault worker clock.');
        } finally {
            config(['app.worker_timezone' => 'Pacific/Auckland']);
        }
        $this->assertTrue($rolledBack['rolled_back']);
        $this->assertSame(0, $rolledBack['jobs_inside_transaction']);
        $this->assertSame(0, $rolledBack['jobs_after']);
        $this->assertSame($credentialBefore, $this->credential->fresh()->getRawOriginal());
        $this->assertSame(0, WorkforceEligibilityRecheck::count());

        $committed = $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id, 'expires_at' => $expiry]);
        $this->assertFalse($committed['rolled_back']);
        $this->assertSame(0, $committed['jobs_inside_transaction']);
        $this->assertSame(1, $committed['jobs_after']);
        $request = $this->sourceRequest();
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->source_version);
        $this->assertSame([$this->worker->id], $request->user_ids);
        $this->assertSerializedVersion($request);
        $this->assertPersistentUniqueLease($request);
        $this->assertSame(0, WorkforceEligibilityObservation::count());

        $receipt = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertSame([RefreshWorkforceEligibility::class], $this->processedClasses($receipt));
        $this->assertCompleted($request, 1);
        $observation = WorkforceEligibilityObservation::where('shift_id', $shift->id)->firstOrFail();
        $this->assertSame(1, $observation->observed_versions[$request->id]);
        $this->assertSame('clear', $observation->posture);
        $this->assertSame(0, $observation->block_count);
        $this->assertSame($expiry, $this->compliance()->expires_at->toDateString());
        // The real compliance projector may stage its own legitimate current
        // status source. Consume it without assuming a fixed queue shape.
        $this->drain();
        $this->assertSame(1, WorkforceEligibilityObservation::count());
        $this->assertUnchangedEmployeeHistory($before);
    }

    public function test_current_versions_persistent_uniqueness_and_broker_duplicates_have_truthful_no_op_outcomes(): void
    {
        $shift = $this->duty(0);
        $before = $this->commitFixtures();
        $first = $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id,
            'expires_at' => now()->addYears(4)->toDateString()]);
        $request = $this->sourceRequest();
        $this->assertSame(1, $first['source_version']);
        $coalesced = $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id, 'touch' => true]);
        $this->assertSame(1, $coalesced['source_version']);
        $this->assertSame(1, $coalesced['jobs_after']);
        $this->assertPersistentUniqueLease($request);
        $recovery = $this->runRuntime(['mode' => 'recovery']);
        $this->assertSame(1, $recovery['recover_dispatch_attempts']);
        $this->assertSame(1, DB::table('jobs')->count(), 'The separate recovery producer must honor the persistent uniqueness lock.');
        $this->assertSame('pending', $request->fresh()->status, 'A dispatch attempt is not completed evaluation.');

        $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id,
            'expires_at' => now()->addYears(5)->toDateString()]);
        $current = $request->fresh();
        $this->assertSame(2, $current->source_version);
        $this->assertSame(2, DB::table('jobs')->count());
        $oldDelivery = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertSame([RefreshWorkforceEligibility::class], $this->processedClasses($oldDelivery));
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $this->assertSame(0, $current->fresh()->attempts);
        $this->assertSame('pending', $current->fresh()->status);
        $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertCompleted($current, 1);
        $observation = WorkforceEligibilityObservation::where('shift_id', $shift->id)->firstOrFail();
        $this->assertSame(2, $observation->observed_versions[$current->id]);
        $this->assertSame(now()->addYears(5)->toDateString(), $this->compliance()->expires_at->toDateString());
        $this->drain();
        $observationsBefore = $this->observationRows();
        $requestBefore = $current->fresh()->getRawOriginal();

        // This is an actual broker duplicate of a legitimate completed payload;
        // it bypasses dispatch uniqueness intentionally, without deleting a lease.
        $this->runRuntime(['mode' => 'duplicate', 'recheck_id' => $current->id, 'version' => 2]);
        $duplicate = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertSame([RefreshWorkforceEligibility::class], $this->processedClasses($duplicate));
        $this->assertSame($observationsBefore, $this->observationRows());
        $this->assertSame($requestBefore, $current->fresh()->getRawOriginal());
        $this->assertUnchangedEmployeeHistory($before);
    }

    public function test_nightly_fifty_plus_two_continues_through_the_unchanged_registered_minute_event_and_real_worker(): void
    {
        $duties = collect(range(0, 51))->map(fn (int $ordinal): Shift => $this->duty($ordinal));
        $unpublished = $this->duty(60, ['published_at' => null]);
        $cancelled = $this->duty(61, ['status' => 'cancelled']);
        $unassigned = $this->duty(62, ['user_id' => null]);
        $stay = $this->duty(63);
        $booking = RespiteBooking::factory()->create(['client_id' => $stay->client_id]);
        DB::table('shifts')->where('id', $stay->id)->update(['respite_booking_id' => $booking->id]);
        $before = $this->commitFixtures();

        $this->runRuntime(['mode' => 'nightly']);
        $nightly = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertSame([RecalculateFutureShiftEligibility::class], $this->processedClasses($nightly));
        $scan = WorkforceEligibilityRecheck::where('source_type', 'nightly_scan')->firstOrFail();
        $this->assertSame((int) $duties->last()->id, (int) $scan->source_id);
        $firstScan = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 1]);
        $this->assertSame([RefreshWorkforceEligibility::class], $this->processedClasses($firstScan));
        $scan->refresh();
        $this->assertSame('pending', $scan->status);
        $this->assertSame(2, $scan->source_version);
        $this->assertSame(50, $scan->scanned_count);
        $this->assertSame((int) $duties[49]->id, $scan->last_shift_id);
        $this->assertSame(50, WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->count());
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $this->drain();
        $this->assertSame(50, WorkforceEligibilityObservation::count());
        $this->assertSame('pending', $scan->fresh()->status);

        $scheduled = $this->runRuntime(['mode' => 'scheduled_recovery']);
        $this->assertSame('* * * * *', $scheduled['event_expression']);
        $this->assertTrue($scheduled['without_overlapping']);
        $this->assertSame(0, $scheduled['event_exit_code']);
        $this->assertNotEmpty($scheduled['event_mutex']);
        $this->assertSerializedVersion($scan->fresh());
        $this->drain();
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(52, $scan->fresh()->scanned_count);
        $this->assertSame(52, WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->where('status', 'completed')->count());
        $this->assertEqualsCanonicalizing($duties->pluck('id')->all(), WorkforceEligibilityObservation::pluck('shift_id')->all());
        foreach ([$unpublished, $cancelled, $unassigned, $stay] as $excluded) {
            $this->assertFalse(WorkforceEligibilityRecheck::where('source_type', 'nightly_shift')->where('source_id', $excluded->id)->exists());
        }
        $this->assertUnchangedEmployeeHistory($before);
    }

    public function test_a_genuinely_interrupted_worker_retains_its_reserved_job_and_lease_then_resumes_the_committed_cursor(): void
    {
        $duties = collect(range(0, 51))->map(fn (int $ordinal): Shift => $this->duty($ordinal));
        $before = $this->commitFixtures();
        $clock = Carbon::now('UTC')->startOfSecond();
        $this->runRuntime(['mode' => 'producer', 'credential_id' => $this->credential->id, 'touch' => true], $clock->toIso8601String());
        $request = $this->sourceRequest();
        $lease = $this->assertPersistentUniqueLease($request);
        $pauseFile = base_path('test-results/'.$this->runtimeQueue.'-pause.json');
        $process = $this->process(['mode' => 'worker', 'max_jobs' => 100, 'pause_recheck_id' => $request->id,
            'pause_version' => $request->source_version, 'pause_file' => $pauseFile], $clock->toIso8601String());
        try {
            $process->start();
            $deadline = microtime(true) + 40;
            while (! is_file($pauseFile) && microtime(true) < $deadline && $process->isRunning()) {
                $process->checkTimeout();
                usleep(10000);
            }
            $this->assertFileExists($pauseFile, 'The real worker must reach the committed 50-duty cursor: '.$process->getErrorOutput());
            $paused = json_decode(file_get_contents($pauseFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $paused['pid']);
            $this->assertSame($request->id, $paused['recheck_id']);
            $this->assertSame(1, $paused['source_version']);
            $this->assertSame(0, $paused['transaction_level']);
            $this->assertSame((int) $duties[49]->id, $paused['last_shift_id']);
            $this->assertSame(50, $paused['scanned_count']);
            $request->refresh();
            $this->assertSame('processing', $request->status);
            $this->assertSame(1, $request->attempts);
            $this->assertSame(50, $request->scanned_count);
            $this->assertSame(50, WorkforceEligibilityObservation::count());
            $firstChunk = $this->observationRows();
            $reserved = DB::table('jobs')->where('queue', $this->runtimeQueue)->whereNotNull('reserved_at')->sole();
            $this->assertSame(1, (int) $reserved->attempts);
            $this->assertSame($clock->timestamp, (int) $reserved->reserved_at);
            $this->assertSame($lease->owner, DB::table('cache_locks')->where('key', $lease->key)->value('owner'));
            $this->assertGreaterThan($clock->timestamp, (int) $lease->expiration);

            $process->stop(1);
            $this->assertFalse($process->isRunning());
            $this->assertSame($reserved->id, DB::table('jobs')->where('queue', $this->runtimeQueue)->sole()->id);
            $this->assertSame($lease->owner, DB::table('cache_locks')->where('key', $lease->key)->value('owner'));
            $this->assertSame('processing', $request->fresh()->status);

            // Explicit simulated deadline proof, separate from the real-time
            // registered Schedule Event proved by the nightly scenario.
            $recoveryClock = $clock->copy()->addMinutes(11)->toIso8601String();
            $recovery = $this->runRuntime(['mode' => 'recovery'], $recoveryClock);
            $this->assertSame(1, $recovery['recover_dispatch_attempts']);
            $request->refresh();
            $this->assertSame('failed', $request->status);
            $this->assertSame('worker_interrupted', $request->error_code);
            $this->assertSame(50, $request->last_shift_id > 0 ? $request->scanned_count : -1);
            $this->assertSame(2, DB::table('jobs')->count(), 'Retained expired reservation and recovered delivery must both remain represented.');
            $resumed = $this->drain($recoveryClock);
            $this->assertCount(2, $this->processedClasses($resumed), 'Both original and recovered broker deliveries must be consumed.');
            $this->assertCompleted($request, 52);
            $this->assertSame(2, $request->fresh()->attempts);
            $this->assertSame(52, WorkforceEligibilityObservation::count());
            foreach ($firstChunk as $old) {
                $this->assertSame($old, WorkforceEligibilityObservation::findOrFail($old['id'])->getRawOriginal(), 'Committed first-chunk observations must not be replayed.');
            }
            $this->assertEqualsCanonicalizing($duties->pluck('id')->all(), WorkforceEligibilityObservation::pluck('shift_id')->all());
            $this->assertUnchangedEmployeeHistory($before);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach ([$pauseFile, $pauseFile.'.writing'] as $ownedReceipt) {
                if (is_file($ownedReceipt)) {
                    unlink($ownedReceipt); // Only this test's validated, unique receipt paths.
                }
            }
        }
    }

    private function duty(int $ordinal, array $overrides = []): Shift
    {
        $starts = Carbon::now('Pacific/Auckland')->addDays(30 + 7 * $ordinal)->setTime(9, 0)->utc();
        $shift = Shift::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'user_id' => $this->worker->id,
            'created_by' => $this->worker->id, 'service_context_id' => null, 'coverage_roles' => [],
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false,
            'status' => 'scheduled', 'starts_at' => $starts, 'ends_at' => $starts->copy()->addHour(),
            ...array_diff_key($overrides, ['published_at' => true]),
        ]);
        $shift->forceFill(['published_at' => array_key_exists('published_at', $overrides) ? $overrides['published_at'] : now()])->save();

        return $shift->fresh();
    }

    /** Commit only newly created fixtures and fail unexpected pre-existing work. */
    private function commitFixtures(): array
    {
        foreach (['jobs', 'failed_jobs', 'shift_signal_outbox', 'notifications'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Unexpected runtime work cannot be silently drained: '.$table);
        }
        WorkforceEligibilityRecheck::query()->delete();
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $before = $this->employeeHistory();
        $this->assertSame(1, DB::connection()->transactionLevel());
        DB::commit();
        $this->fixturesCommitted = true;
        $this->assertSame(0, DB::connection()->transactionLevel());

        return $before;
    }

    private function process(array $operation, ?string $clock = null): Process
    {
        $process = Runtime::make($operation, $this->runtimeQueue, $clock);
        $this->ownedProcesses[] = $process;

        return $process;
    }

    private function runRuntime(array $operation, ?string $clock = null): array
    {
        $receipt = Runtime::receipt($this->process($operation, $clock));
        $this->assertNotSame(getmypid(), $receipt['pid']);
        $this->assertSame(DB::connection()->getDatabaseName(), $receipt['database']);
        $this->assertSame($this->runtimeQueue, $receipt['queue']);
        $this->assertSame(config('app.worker_timezone'), $receipt['worker_timezone']);

        return $receipt;
    }

    private function drain(?string $clock = null): array
    {
        $receipt = $this->runRuntime(['mode' => 'worker', 'max_jobs' => 150], $clock);
        $this->assertNotContains('failed', array_column($receipt['job_events'], 'status'));
        $this->assertNotContains('released', array_column($receipt['job_events'], 'status'));
        $this->assertSame(0, DB::table('jobs')->count(), 'The bounded worker must consume all expected work before claiming completeness.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        return $receipt;
    }

    private function processedClasses(array $receipt): array
    {
        return collect($receipt['job_events'])->where('status', 'processed')->pluck('class')->all();
    }

    private function sourceRequest(): WorkforceEligibilityRecheck
    {
        return WorkforceEligibilityRecheck::where('source_type', $this->credential->getTable())
            ->where('source_id', $this->credential->id)->firstOrFail();
    }

    private function assertSerializedVersion(WorkforceEligibilityRecheck $request): void
    {
        $commands = DB::table('jobs')->where('queue', $this->runtimeQueue)->get()->map(function ($row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return unserialize($payload['data']['command'], ['allowed_classes' => [RefreshWorkforceEligibility::class]]);
        })->filter(fn ($job): bool => $job instanceof RefreshWorkforceEligibility);
        $this->assertTrue($commands->contains(fn ($job): bool => $job->recheckId === $request->id && $job->sourceVersion === $request->source_version));
    }

    private function assertPersistentUniqueLease(WorkforceEligibilityRecheck $request): object
    {
        $key = $this->runtimeQueue.'-'.UniqueLock::getKey(new RefreshWorkforceEligibility($request->id, $request->source_version));
        $lease = DB::table('cache_locks')->where('key', $key)->first();
        $this->assertNotNull($lease, 'Uniqueness must persist across producer and worker runtimes.');
        $this->assertGreaterThan(now()->timestamp, (int) $lease->expiration);
        $this->assertNotEmpty($lease->owner);

        return $lease;
    }

    private function compliance(): HrStaffComplianceStatus
    {
        return HrStaffComplianceStatus::where('user_id', $this->worker->id)->where('requirement_id', $this->requirement->id)->firstOrFail();
    }

    private function observationRows(): array
    {
        return WorkforceEligibilityObservation::orderBy('id')->get()->map->getRawOriginal()->all();
    }

    private function employeeHistory(): array
    {
        return ['shifts' => Shift::orderBy('id')->get()->map->getRawOriginal()->all(),
            'timeline' => DB::table('timeline_events')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'attendance' => DB::table((new HrAttendanceSession)->getTable())->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'timesheets' => DB::table('timesheets')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()];
    }

    private function assertCompleted(WorkforceEligibilityRecheck $request, int $count): void
    {
        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame($count, $request->scanned_count);
        $this->assertSame(0, $request->failed_count);
        $this->assertNull($request->error_code);
        $this->assertSame(0, $request->last_shift_id);
        $this->assertNotNull($request->completed_at);
    }

    private function assertUnchangedEmployeeHistory(array $before): void
    {
        $this->assertSame($before, $this->employeeHistory());
        $this->assertSame(0, DB::table('shift_signal_outbox')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
