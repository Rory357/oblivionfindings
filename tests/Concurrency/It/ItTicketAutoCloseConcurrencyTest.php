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

/** Standalone isolated wrapper: real committed transactions, never Feature RefreshDatabase. */
final class ItTicketAutoCloseConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone auto-close verification.');
        }

        return parent::createApplication();
    }

    public function test_overlapping_scheduler_runs_and_reopen_preserve_the_latest_canonical_state(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
        $attributes = ['site_id' => $site->id, 'status' => 'resolved', 'workflow_state' => 'resolved',
            'resolved_at' => now()->subDays(8), 'closed_at' => null, 'lock_version' => 1,
            'resolution_code' => 'restored', 'resolution_summary' => 'Synthetic restoration.'];
        $duplicate = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $duplicate, ['auto-close', 'auto-close']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['closed', 'skipped'], $statuses);
        $this->assertSame('closed', $duplicate->fresh()->status);
        $this->assertSame(2, $duplicate->fresh()->lock_version);
        $this->assertSame(1, $duplicate->events()->where('type', 'closed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.auto_closed')->where('auditable_id', $duplicate->id)->count());

        $reopening = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $reopening, ['auto-close', 'ticket-reopen']);
        $this->assertContains($results[0]['status'], ['closed', 'skipped']);
        $this->assertSame('reopened', $results[1]['status']);
        $saved = $reopening->fresh();
        $this->assertSame('open', $saved->status);
        $this->assertSame('submitted', $saved->workflow_state);
        $this->assertNull($saved->closed_at);
        $this->assertNull($saved->resolved_at);
        $this->assertSame(1, $saved->reopened_count);
        $this->assertSame(1, $saved->events()->where('type', 'reopened')->count());
        $this->assertSame(1, $saved->comments()->count());
        $expectedCloses = $results[0]['status'] === 'closed' ? 1 : 0;
        $this->assertSame($expectedCloses, $saved->events()->where('type', 'closed')->count());
        $this->assertSame(2 + $expectedCloses, $saved->lock_version);

        $competing = ItTicket::factory()->create([...$attributes, 'resolution_verification' => 'Original synthetic verification.']);
        $results = $this->race($actor, $competing, ['ticket-versioned-reopen', 'ticket-generic-reopen']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['reopened', 'stale_ticket'], $statuses);
        $saved = $competing->fresh();
        $this->assertSame(1, $saved->reopened_count);
        $this->assertSame(2, $saved->lock_version);
        $this->assertSame('it', $saved->next_response_party);
        $this->assertNull($saved->last_public_comment_id);
        $comment = $saved->comments()->sole();
        $event = $saved->events()->where('type', 'reopened')->sole();
        $this->assertSame($comment->id, $event->payload['comment_id']);
        $this->assertSame('Original synthetic verification.', $event->payload['previous_resolution']['resolution_verification']);
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.reopened')->where('auditable_id', $saved->id)->count());

        $closing = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $closing, ['ticket-close', 'ticket-close']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['closed', 'stale_ticket'], $statuses);
        $this->assertSame(2, $closing->fresh()->lock_version);
        $this->assertSame('closed', $closing->fresh()->status);
        $this->assertSame(1, $closing->events()->where('type', 'closed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.closed')->where('auditable_id', $closing->id)->count());
        $winner = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'closed'))[0];
        $this->assertSame(2, $winner['lock_version']);

        $closingOrReopening = ItTicket::factory()->create($attributes);
        $results = $this->race($actor, $closingOrReopening, ['ticket-close', 'ticket-versioned-reopen']);
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 'stale_ticket')));
        $closed = $results[0]['status'] === 'closed';
        $this->assertSame($closed ? 'stale_ticket' : 'reopened', $results[1]['status']);
        $saved = $closingOrReopening->fresh();
        $this->assertSame(2, $saved->lock_version);
        $this->assertSame($closed ? 'closed' : 'open', $saved->status);
        $this->assertSame($closed ? 0 : 1, $saved->reopened_count);
        $this->assertSame($closed ? 0 : 1, $saved->comments()->count());
        $this->assertSame($closed ? 1 : 0, $saved->events()->where('type', 'closed')->count());
        $this->assertSame($closed ? 0 : 1, $saved->events()->where('type', 'reopened')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.work.transitioned')->where('auditable_id', $saved->id)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** Both real workers read the candidate before blocking on its held parent lock. */
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
                $this->assertTrue($process->isRunning(), 'The command must reach the held parent lock.');
            }
            DB::commit();
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $outcomes;
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
                $this->fail('The isolated auto-close workers did not reach their barrier.');
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
