<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItProvisioningCommandService;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone through the isolated wrapper; no RefreshDatabase transaction is shared. */
final class ItProvisioningCommandConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || getenv('DB_HOST') !== '127.0.0.1' || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1) {
            throw new RuntimeException('Use the isolated IT wrapper for provisioning command concurrency.');
        }

        return parent::createApplication();
    }

    public function test_duplicate_creation_cancellation_and_stale_retries_converge_without_replacing_original_work(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $actor->roles()->sync(Role::where('name', 'hr')->pluck('id'));
        $profile = HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id, 'position_role' => 'hr',
            'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null,
        ]);
        foreach ([['create', 'create'], ['create', 'cancel']] as $operations) {
            $uuid = (string) Str::uuid();
            $before = ItProvisioningRequest::count();
            $results = $this->race($actor, $profile, (int) $profile->id, 1, [$uuid, $uuid], $operations);
            $this->assertSame($results[0]['status'], $results[1]['status']);
            $this->assertContains($results[0]['status'], ['committed', 'cancelled']);
            if ($operations === ['create', 'create']) {
                $this->assertSame('committed', $results[0]['status']);
                $replays = array_column($results, 'replayed');
                sort($replays);
                $this->assertSame([false, true], $replays);
            }
            $committed = $results[0]['status'] === 'committed';
            $this->assertSame($before + (int) $committed, ItProvisioningRequest::count());
            $this->assertSame(1, ItTicketCommandReceipt::where('request_uuid', $uuid)->count());
            $lookup = app(ItProvisioningCommandService::class)->lookup($actor, 'manual', (int) $profile->id, 'create', [
                'actor_user_id' => $actor->id, 'request_uuid' => $uuid,
            ]);
            $this->assertSame($results[0]['status'], $lookup['status']);
            if ($committed) {
                $this->assertSame($results[0]['result_id'], $results[1]['result_id']);
                $request = ItProvisioningRequest::findOrFail($results[0]['result_id']);
                $this->assertSame(1, $request->events()->where('type', 'created')->count());
                $this->assertSame('Synthetic private original instructions', $request->notes);
            } else {
                $late = app(ItProvisioningCommandService::class)->execute($actor, 'manual', (int) $profile->id, 'create', [
                    'actor_user_id' => $actor->id, 'request_uuid' => $uuid, 'expected_version' => 1,
                    'type' => 'other', 'item' => 'Late synthetic submission', 'priority' => 'normal',
                ]);
                $this->assertSame('cancelled', $late['status']);
                $this->assertSame($before, ItProvisioningRequest::count());
            }
        }
        $request = ItProvisioningRequest::firstOrFail();
        $request->update(['status' => 'failed', 'failure_reason' => 'Synthetic provider unavailable', 'failed_at' => now()]);
        $before = (int) $request->lock_version;
        $results = $this->race($actor, $profile, (int) $request->id, $before,
            [(string) Str::uuid(), (string) Str::uuid()], ['retry', 'retry']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'conflict'], $statuses);
        $this->assertSame($before + 1, $request->fresh()->lock_version);
        $this->assertSame(1, $request->events()->where('type', 'retried')->count());
        $this->assertSame('Synthetic private original instructions', $request->fresh()->notes);
        // Retained results remain recoverable after the beneficiary leaves;
        // this must not depend on eligibility to create fresh work for them.
        $former = HrEmployeeProfile::factory()->create([
            'user_id' => User::factory()->create(['approved_at' => now()])->id,
            'primary_site_id' => $site->id, 'is_active' => true,
            'start_date' => now()->subMonth(), 'end_date' => null,
        ]);
        $retainedInput = ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(),
            'expected_version' => 1, 'type' => 'other', 'item' => 'Synthetic retained employee work', 'priority' => 'normal'];
        $commands = app(ItProvisioningCommandService::class);
        $created = $commands->execute($actor, 'manual', (int) $former->id, 'create', $retainedInput);
        $former->update(['is_active' => false, 'end_date' => today()->subDay()]);
        $recovered = $commands->lookup($actor, 'manual', (int) $former->id, 'create', [
            'actor_user_id' => $actor->id, 'request_uuid' => $retainedInput['request_uuid'],
        ]);
        $this->assertSame('committed', $recovered['status']);
        $this->assertSame($created['data']['result_id'], $recovered['data']['result_id']);
        $replayed = $commands->execute($actor, 'manual', (int) $former->id, 'create', $retainedInput);
        $this->assertTrue($replayed['data']['replayed']);
        $this->assertSame($created['data']['result_id'], $replayed['data']['result_id']);
        $this->assertSame(1, ItProvisioningRequest::where('employee_profile_id', $former->id)->count());
        Notification::assertNothingSent();
    }

    private function race(User $actor, HrEmployeeProfile $profile, int $target, int $version, array $uuids, array $operations): array
    {
        $prefix = storage_path('framework/testing/'.getenv('TEST_TOKEN').'-provisioning-command-'.Str::uuid());
        if (! is_dir(dirname($prefix))) {
            mkdir(dirname($prefix), 0775, true);
        }
        $paths = [$prefix.'-0.ready', $prefix.'-1.ready'];
        $workers = [];
        DB::beginTransaction();
        HrEmployeeProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $workers[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/provisioning-command-worker.php'),
                    $operation, (string) $actor->id, (string) $target, (string) $version, $uuids[$index],
                    $index === 0 ? 'first' : 'second', $paths[$index]], base_path(), timeout: 45);
                $worker->start();
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($paths, 'is_file')) !== 2) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        throw new RuntimeException('Provisioning worker stopped before the lock barrier: '.trim($worker->getErrorOutput()));
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Provisioning lock barrier timed out.');
                }
                usleep(10_000);
            }
            usleep(250_000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both workers must reach the held canonical profile lock.');
            }
            $releasedAt = microtime(true);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                if (! $worker->isSuccessful()) {
                    $diagnostic = json_decode(trim($worker->getErrorOutput()), true);
                    fwrite(STDERR, json_encode(['worker_exit' => $worker->getExitCode(),
                        'failure' => is_array($diagnostic) ? ($diagnostic['failure'] ?? null) : null,
                    ], JSON_THROW_ON_ERROR).PHP_EOL);
                }
                $this->assertTrue($worker->isSuccessful(), 'Worker failed; see bounded stderr diagnostics.');
                $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['waiting']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
