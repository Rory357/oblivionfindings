<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone committed transactions; never combine with Feature RefreshDatabase. */
final class ItTicketCsatConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone rating concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_overlapping_ratings_and_confirmation_cannot_overwrite_newer_feedback_or_closure(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', 'support_worker')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
        $attributes = ['requester_user_id' => $actor->id, 'site_id' => $site->id, 'status' => 'resolved',
            'workflow_state' => 'resolved', 'resolved_at' => now(), 'closed_at' => null, 'lock_version' => 1,
            'resolution_code' => 'restored', 'resolution_summary' => 'Synthetic verified restoration.'];
        $competing = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $competing, ['csat-low', 'csat-high']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['rated', 'stale_ticket'], $statuses);
        $winner = array_values(array_filter($results, fn ($result) => $result['status'] === 'rated'))[0];
        $this->assertSame($winner['score'], $competing->fresh()->csat_score);
        $this->assertSame(2, $competing->fresh()->lock_version);
        $this->assertSame(1, $competing->events()->where('type', 'csat_submitted')->count());
        $this->assertSame(0, $competing->events()->where('type', 'csat_updated')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.csat.submitted')->where('auditable_id', $competing->id)->count());

        $settling = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $settling, ['csat-high', 'confirm-resolution']);
        $saved = $settling->fresh();
        $this->assertSame(2, $saved->lock_version);
        if ($results[0]['status'] === 'rated') {
            $this->assertSame('stale_ticket', $results[1]['status']);
            $this->assertSame('resolved', $saved->status);
            $this->assertSame(4, $saved->csat_score);
            $this->assertSame(0, $saved->events()->where('type', 'resolution_confirmed')->count());
        } else {
            $this->assertSame('access_denied', $results[0]['status']);
            $this->assertSame('confirmed', $results[1]['status']);
            $this->assertSame('closed', $saved->status);
            $this->assertNull($saved->csat_score);
            $this->assertSame(0, $saved->events()->where('type', 'csat_submitted')->count());
            $this->assertSame(1, $saved->events()->where('type', 'resolution_confirmed')->count());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    /** Both workers reach a held canonical parent lock before either can commit. */
    private function race(User $actor, ItTicket $ticket, array $operations): array
    {
        $barrier = storage_path('framework/testing/it-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $paths[] = $ready = $barrier.'-'.$index.'.ready';
                $paths[] = $attempt = $barrier.'-'.$index.'.attempt';
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/task-command-concurrency-worker.php'),
                    $operation, (string) $actor->id, (string) Str::uuid(), (string) $ticket->id, (string) $ticket->lock_version,
                    '0', $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Rating commands must reach the held parent lock.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function waitForBarriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            if (microtime(true) > $deadline) {
                $this->fail('The isolated rating workers did not reach their barrier.');
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail($process->getErrorOutput().' '.$process->getOutput());
                }
            }
            usleep(10_000);
        }
    }
}
