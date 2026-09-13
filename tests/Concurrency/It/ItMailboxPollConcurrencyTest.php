<?php

namespace Tests\Concurrency\It;

use App\Domain\It\Services\ItMailboxPollState;
use App\Models\AuditLog;
use App\Models\ItMailboxConnection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone committed fixtures; run through the reviewed wrapper in its own process. */
final class ItMailboxPollConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone mailbox concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_competing_claims_and_disconnect_against_token_refresh_serialize_on_the_connection(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $connection = ItMailboxConnection::create([
            'provider' => 'microsoft', 'status' => 'connected',
            'account_email' => 'synthetic@example.test', 'access_token' => 'synthetic-old',
            'refresh_token' => 'synthetic-refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $results = $this->race($actor, $connection, ['claim', 'claim']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['claimed', 'skipped'], $statuses);
        $claim = $connection->fresh();
        $this->assertNotNull($claim->poll_claim_token);
        $this->assertNull($claim->last_polled_at);
        $this->assertNull(app(ItMailboxPollState::class)->claim($connection->id));

        $results = $this->race($actor, $claim, ['refresh', 'disconnect']);
        $this->assertContains($results[0]['status'], ['refreshed', 'superseded']);
        $this->assertSame('disconnected', $results[1]['status']);
        $this->assertSame(0, ItMailboxConnection::count(), 'A stale refreshed token must never recreate a disconnected connection.');
        $this->assertSame(1, AuditLog::where('action', 'settings.it_mailbox.disconnected')->where('user_id', $actor->id)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function race(User $actor, ItMailboxConnection $connection, array $operations): array
    {
        $barrier = storage_path('framework/testing/mailbox-'.getenv('TEST_TOKEN').'-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $paths = [];
        $workers = [];
        DB::beginTransaction();
        ItMailboxConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $paths[] = $ready = $barrier.'-'.$index.'.ready';
                $paths[] = $attempt = $barrier.'-'.$index.'.attempt';
                $workers[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/mailbox-poll-concurrency-worker.php'),
                    $operation, (string) $actor->id, (string) $connection->id, $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitFor([$barrier.'-0.ready', $barrier.'-1.ready'], $workers);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitFor([$barrier.'-0.attempt', $barrier.'-1.attempt'], $workers);
            usleep(250_000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both workers must overlap behind the held canonical connection lock.');
            }
            $releasedAt = microtime(true);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertLessThan($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
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

    private function waitFor(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new RuntimeException('Mailbox worker exited before its barrier: '.$worker->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Mailbox worker barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
