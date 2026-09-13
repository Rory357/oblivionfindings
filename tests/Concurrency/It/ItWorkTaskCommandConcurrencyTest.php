<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone: one parent owns the exact wrapper schema; workers never reset it. */
final class ItWorkTaskCommandConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone task command verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_fence_duplicate_commands_cancellation_and_aggregate_mutations(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $actor = $this->actor('hr', $site);
        $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $uuid = (string) Str::uuid();
        $duplicates = $this->race($actor, ['create', 'create'], $uuid, $ticket);
        $this->assertSame(['committed', 'committed'], array_column($duplicates, 'status'));
        $this->assertSame($duplicates[0]['data']['task_id'], $duplicates[1]['data']['task_id']);
        $replayed = array_map(fn ($row) => $row['data']['replayed'], $duplicates);
        sort($replayed);
        $this->assertSame([false, true], $replayed);
        $this->assertSame(1, $ticket->tasks()->count());
        $this->assertSame(2, $ticket->fresh()->lock_version);
        $this->assertSame(1, $ticket->events()->where('type', 'work_task_created')->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $ticket->id)->count());

        $task = $ticket->tasks()->sole();
        $changes = $this->race($actor, ['update', 'complete'], (string) Str::uuid(), $ticket->fresh(), $task);
        $statuses = array_column($changes, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        $this->assertSame(3, $ticket->fresh()->lock_version);
        $this->assertSame(1, $ticket->events()->whereIn('type', ['work_task_updated', 'work_task_completed'])->count());

        $cancelTicket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $cancelUuid = (string) Str::uuid();
        $cancelled = $this->race($actor, ['create', 'cancel-create'], $cancelUuid, $cancelTicket);
        $this->assertSame($cancelled[0]['status'], $cancelled[1]['status']);
        $this->assertContains($cancelled[0]['status'], ['committed', 'cancelled']);
        $created = $cancelled[0]['status'] === 'committed';
        $this->assertSame($created ? 1 : 0, $cancelTicket->tasks()->count());
        $this->assertSame($created ? 2 : 1, $cancelTicket->fresh()->lock_version);
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $cancelTicket->id)->count());
        $proof = app(ItWorkTaskCommandService::class)->recover($cancelTicket, $actor, 'create', $cancelUuid);
        $this->assertSame($cancelled[0]['status'], $proof->status);

        $orderTicket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        ItWorkTask::factory()->create(['ticket_id' => $orderTicket->id, 'sort_order' => 10, 'is_required' => false]);
        ItWorkTask::factory()->create(['ticket_id' => $orderTicket->id, 'sort_order' => 20, 'is_required' => false]);
        $ordered = $this->race($actor, ['reorder', 'create'], (string) Str::uuid(), $orderTicket);
        $statuses = array_column($ordered, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        $this->assertSame(2, $orderTicket->fresh()->lock_version);
        $this->assertSame(1, $orderTicket->events()->count());

        $settled = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $settlement = $this->race($actor, ['create', 'resolve'], (string) Str::uuid(), $settled);
        $statuses = array_column($settlement, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        $this->assertSame($settlement[0]['status'] === 'committed' ? 1 : 0, $settled->tasks()->count());
        $this->assertSame($settlement[1]['status'] === 'committed' ? 'resolved' : 'open', $settled->fresh()->status);

        $required = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $requiredTask = ItWorkTask::factory()->create(['ticket_id' => $required->id, 'is_required' => true]);
        $completion = $this->race($actor, ['complete', 'resolve'], (string) Str::uuid(), $required, $requiredTask);
        $this->assertSame('committed', $completion[0]['status']);
        $this->assertContains($completion[1]['status'], ['stale_ticket', 'task_blocked']);
        $this->assertSame('completed', $requiredTask->fresh()->status);
        $this->assertSame('open', $required->fresh()->status, 'Settlement must separately review completion, never race past required work.');

        $reopened = $this->race($actor, ['reopen', 'resolve'], (string) Str::uuid(), $required->fresh(), $requiredTask->fresh());
        $statuses = array_column($reopened, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        if ($reopened[0]['status'] === 'committed') {
            $this->assertSame('pending', $requiredTask->fresh()->status);
            $this->assertSame('open', $required->fresh()->status);
        } else {
            $this->assertSame('completed', $requiredTask->fresh()->status);
            $this->assertSame('resolved', $required->fresh()->status);
        }
    }

    public function test_a_real_after_commit_failure_recovers_the_original_task_receipt_without_duplicate_work(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $actor = $this->actor('hr', $site);
        $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $input = ['actor_user_id' => $actor->id, 'expected_version' => 1, 'request_uuid' => (string) Str::uuid(), 'title' => 'Original commit proof'];
        $connection = DB::connection();
        $thrown = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($connection, &$thrown) {
            if ($event->connection === $connection && $connection->transactionLevel() === 0 && ! $thrown) {
                $thrown = true;
                throw new RuntimeException('Synthetic outer postcommit failure');
            }
        });
        try {
            $result = app(ItWorkTaskCommandService::class)->execute($ticket, $actor, 'create', $input);
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        $this->assertTrue($thrown);
        $this->assertSame('committed', $result->status);
        $this->assertTrue($result->data['replayed']);
        $this->assertSame(2, $result->data['lock_version']);
        $replayed = app(ItWorkTaskCommandService::class)->execute($ticket, $actor, 'create', $input);
        $this->assertSame($result->data['task_id'], $replayed->data['task_id']);
        $this->assertSame(1, $ticket->tasks()->count());
        $this->assertSame(1, $ticket->events()->where('type', 'work_task_created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.task.created')->where('auditable_id', $ticket->id)->count());
    }

    public function test_uncertain_reconciliation_cannot_acknowledge_a_different_intent_that_won_after_rollback(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $actor = $this->actor('hr', $site);
        $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
        $input = ['actor_user_id' => $actor->id, 'expected_version' => 1, 'request_uuid' => (string) Str::uuid(), 'title' => 'First prepared intent'];
        $connection = DB::connection();
        $replaced = false;
        $winner = null;
        Event::listen(TransactionCommitting::class, function ($event) use ($connection, &$replaced, &$winner, $ticket, $actor, $input) {
            if ($event->connection === $connection && $connection->transactionLevel() === 1 && ! $replaced) {
                $replaced = true;
                // Release the original transaction before another canonical
                // command legitimately acquires this now-unused identity.
                $connection->rollBack();
                $winner = app(ItWorkTaskCommandService::class)->execute($ticket, $actor, 'create', [...$input, 'title' => 'Different winning intent']);
                throw new RuntimeException('Synthetic original commit outcome unavailable');
            }
        });
        try {
            try {
                app(ItWorkTaskCommandService::class)->execute($ticket, $actor, 'create', $input);
                $this->fail('A different receipt must never acknowledge the original proposal.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic original commit outcome unavailable', $exception->getMessage());
            }
        } finally {
            Event::forget(TransactionCommitting::class);
        }
        $this->assertTrue($replaced);
        $this->assertSame('committed', $winner?->status);
        $this->assertSame('Different winning intent', $ticket->tasks()->sole()->title);
        $this->assertSame(1, $ticket->events()->where('type', 'work_task_created')->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $ticket->id)->count());
        $this->assertSame(2, $ticket->fresh()->lock_version);
    }

    private function actor(string $role, Site $site): User
    {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);

        return $actor;
    }

    /** @return list<array<string, mixed>> */
    private function race(User $actor, array $operations, string $uuid, ?ItTicket $ticket = null, ?ItWorkTask $task = null): array
    {
        $barrier = storage_path('framework/testing/it-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        if ($ticket) {
            ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        } else {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        }

        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $processes[] = $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/It/task-command-concurrency-worker.php'),
                    $operation, (string) $actor->id, $uuid,
                    (string) ($ticket?->id ?? 0), (string) ($ticket?->lock_version ?? 0), (string) ($task?->id ?? 0),
                    $ready, $attempt, $barrier.'.release',
                ], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both workers must reach the command while its canonical row remains locked.');
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

    /** @param list<string> $paths @param list<Process> $processes */
    private function waitForBarriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('A concurrency worker stopped before reaching its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The isolated concurrency workers did not reach their barrier in time.');
            }
            usleep(10_000);
        }
    }
}
