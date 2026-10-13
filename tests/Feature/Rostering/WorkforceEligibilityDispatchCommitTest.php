<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class WorkforceEligibilityDispatchCommitTest extends TestCase
{
    use RefreshDatabase;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(OwnedTestDatabase::isOwnedBy(DB::connection()->getDatabaseName(), getmypid()));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00', 'UTC'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('deliveryFailures')]
    public function test_queue_and_diagnostic_faults_after_the_root_commit_do_not_report_a_failed_source_save(bool $metadataUnavailable): void
    {
        $this->assertSame(0, WorkforceEligibilityRecheck::count(), 'Unexpected committed recovery metadata must fail before fixtures.');
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        WorkforceEligibilityRecheck::query()->delete();
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        $this->committed = true;
        Queue::fake();
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $testManager = $this->app['db.transactions'];
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        try {
            $realBus = Bus::getFacadeRoot();
            $realLog = Log::getFacadeRoot();
            $deliveryBoundaries = [];
            Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$deliveryBoundaries): never {
                $deliveryBoundaries['broker'] = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction()];
                throw new RuntimeException('Private broker detail');
            });
            $log = Mockery::mock($realLog)->makePartial();
            $log->shouldReceive('warning')->once()->andReturnUsing(function () use (&$deliveryBoundaries): never {
                $deliveryBoundaries['logger'] = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction()];
                throw new RuntimeException('Private log detail');
            });
            Log::swap($log);
            $faultActive = true;
            $connection->beforeExecuting(function (string $sql, array $bindings) use ($metadataUnavailable, &$faultActive, &$deliveryBoundaries): void {
                if ($metadataUnavailable && $faultActive && str_starts_with($sql, 'update `workforce_eligibility_rechecks`')
                    && in_array('queue_unavailable', $bindings, true)) {
                    $deliveryBoundaries['metadata'] = [DB::transactionLevel(), DB::connection()->getPdo()->inTransaction()];
                    throw new RuntimeException('Private diagnostic write detail');
                }
            });
            try {
                $credential = DB::transaction(fn () => StaffCredential::create(['user_id' => $worker->id,
                    'type' => 'ROOT-DELIVERY-REGRESSION', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']));
            } finally {
                $faultActive = false;
                Bus::swap($realBus);
                Log::swap($realLog);
            }
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
            $this->assertSame([0, false], $deliveryBoundaries['broker'] ?? null);
            $this->assertSame([0, false], $deliveryBoundaries['logger'] ?? null);
            $this->assertSame($metadataUnavailable ? [0, false] : null, $deliveryBoundaries['metadata'] ?? null);
            $this->assertSame('2027-12-31', $credential->fresh()->expires_at->toDateString());
            $intent = WorkforceEligibilityRecheck::where('source_type', 'staff_credentials')->where('source_id', $credential->id)->sole();
            $this->assertSame(1, $intent->source_version);
            $this->assertSame([$worker->id], $intent->user_ids);
            $this->assertSame($metadataUnavailable ? 'pending' : 'failed', $intent->status);
            $this->assertNull($intent->completed_at);
            $this->assertStringNotContainsString('Private', json_encode($intent->getAttributes()));
            $before = $intent->getRawOriginal();
            $job = new RefreshWorkforceEligibility($intent->id, $intent->source_version);
            $this->assertSame(600, $job->uniqueFor);
            $store = Cache::store()->getStore();
            $this->assertInstanceOf(ArrayStore::class, $store);
            $key = UniqueLock::getKey($job);
            $lease = $store->locks[$key] ?? null;
            $this->assertNotNull($lease, 'A failed broker call retains the actual uncertain-delivery lease.');
            $this->assertNotEmpty($lease['owner']);
            $this->assertTrue($lease['expiresAt']->equalTo(now()->addSeconds($job->uniqueFor)));
            $source = $credential->fresh()->getRawOriginal();
            $initialClock = now()->copy();
            Carbon::setTestNow($initialClock->copy()->addMinutes(2));
            Queue::fake();
            $this->assertSame(1, app(WorkforceEligibilityRefresh::class)->recover(), 'Recovery counts dispatch attempts, not successful delivery.');
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
            $this->assertSame($lease, $store->locks[$key]);
            $this->assertSame($before, $intent->fresh()->getRawOriginal());
            $this->assertSame($source, $credential->fresh()->getRawOriginal());
            Carbon::setTestNow($initialClock->copy()->addMinutes(11));
            $this->assertTrue($lease['expiresAt']->isPast());
            Queue::fake();
            $this->assertSame(1, app(WorkforceEligibilityRefresh::class)->recover());
            Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
            Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($queued) => $queued->recheckId === $intent->id && $queued->sourceVersion === 1);
            $this->assertNotSame($lease['owner'], $store->locks[$key]['owner']);
            $this->assertSame($before, $intent->fresh()->getRawOriginal());
            $this->assertSame($source, $credential->fresh()->getRawOriginal());
        } finally {
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

    public static function deliveryFailures(): array
    {
        return ['queue and logging fail' => [false], 'queue metadata and logging fail' => [true]];
    }
}
