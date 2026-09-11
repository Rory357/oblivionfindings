<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Explicit standalone wrapper only; committed fixtures never share a Feature process. */
final class ItWorkTaskHistoryConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone task history verification.');
        }

        return parent::createApplication();
    }

    public function test_real_completion_reopen_settlement_races_and_after_commit_recovery_preserve_each_history_generation(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
        $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $task = ItWorkTask::factory()->create(['ticket_id' => $ticket->id, 'status' => 'pending',
            'is_required' => true, 'evidence_required' => false]);

        $completed = $this->race($actor, $ticket, $task, ['complete', 'complete']);
        $this->assertSame(['committed', 'committed'], array_column($completed, 'status'));
        $this->assertSame([2, 2], array_column(array_column($completed, 'data'), 'lock_version'));
        $this->assertSame(1, $task->completions()->count());
        $original = $task->completions()->sole()->getRawOriginal();
        $reopened = $this->race($actor, $ticket->fresh(), $task->fresh(), ['reopen', 'reopen']);
        $this->assertSame(['committed', 'committed'], array_column($reopened, 'status'));
        $this->assertSame([3, 3], array_column(array_column($reopened, 'data'), 'lock_version'));
        $this->assertNull($task->fresh()->current_completion_id);
        $this->assertSame($original, $task->completions()->sole()->getRawOriginal());
        $this->assertSame(1, $ticket->events()->where('type', 'work_task_completed')->count());
        $this->assertSame(1, $ticket->events()->where('type', 'work_task_reopened')->count());

        app(ItWorkTaskCommandService::class)->execute($ticket->fresh(), $actor, 'complete', [
            'actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 3,
            'completion_note' => 'Second verified generation',
        ], $task->fresh());
        $second = $task->fresh()->currentCompletion;
        $this->assertSame(2, $second->sequence);
        $settlement = $this->race($actor, $ticket->fresh(), $task->fresh(), ['reopen', 'resolve']);
        $statuses = array_column($settlement, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        if ($settlement[0]['status'] === 'committed') {
            $this->assertSame('open', $ticket->fresh()->status);
            $this->assertSame('pending', $task->fresh()->status);
            $this->assertNull($task->fresh()->current_completion_id);
        } else {
            $this->assertSame('resolved', $ticket->fresh()->status);
            $this->assertSame('completed', $task->fresh()->status);
            $this->assertSame($second->id, $task->fresh()->current_completion_id);
        }
        $this->assertSame(2, $task->completions()->count());
        $this->assertSame($original, $task->completions()->where('sequence', 1)->sole()->getRawOriginal());

        $recoveryTicket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $legacy = ItWorkTask::factory()->create(['ticket_id' => $recoveryTicket->id, 'status' => 'completed',
            'completed_at' => null, 'completed_by_user_id' => null, 'completion_note' => 'Extant legacy text', 'evidence' => ['Extant legacy reference']]);
        $input = ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => 'Recheck the old verification'];
        $connection = DB::connection();
        $thrown = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($connection, &$thrown): void {
            if ($event->connection === $connection && $connection->transactionLevel() === 0 && ! $thrown) {
                $thrown = true;
                throw new RuntimeException('Synthetic history postcommit acknowledgement failure');
            }
        });
        try {
            $result = app(ItWorkTaskCommandService::class)->execute($recoveryTicket, $actor, 'reopen', $input, $legacy);
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        $this->assertTrue($thrown);
        $this->assertSame('committed', $result->status);
        $this->assertTrue($result->data['replayed']);
        $this->assertSame(2, $result->data['lock_version']);
        $again = app(ItWorkTaskCommandService::class)->execute($recoveryTicket, $actor, 'reopen', $input, $legacy);
        $this->assertSame($result->data, $again->data);
        $history = $legacy->completions()->sole();
        $this->assertSame('legacy_snapshot', $history->source);
        $this->assertNull($history->completed_at);
        $this->assertNull($history->completed_by_user_id);
        $this->assertNull($history->prerequisite_completions);
        $this->assertSame(['Extant legacy reference'], $history->evidence);
        $this->assertSame('Extant legacy text', $history->completion_note);
        $this->assertSame('pending', $legacy->fresh()->status);
        $this->assertNull($legacy->fresh()->current_completion_id);
        $this->assertSame(1, $recoveryTicket->events()->where('type', 'work_task_reopened')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.task.reopened')->where('auditable_id', $recoveryTicket->id)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** Reuses the existing guarded task worker; workers never migrate or drop the schema. */
    private function race(User $actor, ItTicket $ticket, ItWorkTask $task, array $operations): array
    {
        $barrier = storage_path('framework/testing/it-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $paths[] = $ready = $barrier.'-'.$index.'.ready';
                $paths[] = $attempt = $barrier.'-'.$index.'.attempt';
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/task-command-concurrency-worker.php'),
                    $operation, (string) $actor->id, $uuid, (string) $ticket->id, (string) $ticket->lock_version,
                    (string) $task->id, $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both commands must reach the held canonical ticket lock.');
            }
            DB::commit();
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $outcomes[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            foreach ($outcomes as $outcome) {
                $this->assertLessThanOrEqual($releasedAt, $outcome['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $outcome['completed_at']);
            }

            return $outcomes;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
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
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Task worker stopped before its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The isolated task history workers did not reach their barrier.');
            }
            usleep(10_000);
        }
    }
}
