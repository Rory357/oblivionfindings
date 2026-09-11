<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketApprovalCommandService;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
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

/** Standalone: only the parent prepares and disposes its exact wrapper schema. */
final class ItTicketApprovalCommandConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone approval command verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_serialize_duplicate_request_opposing_decisions_cancel_and_settlement(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $raiser = $this->actor($site);
        $first = $this->actor($site);
        $second = $this->actor($site);
        $ticket = $this->ticket($site);
        $duplicate = $this->race([$raiser, $raiser], ['request', 'request'], (string) Str::uuid(), $ticket, primary: $first);
        $this->assertSame(['committed', 'committed'], array_column($duplicate, 'status'));
        $this->assertSame($duplicate[0]['data']['approval_id'], $duplicate[1]['data']['approval_id']);
        $replays = array_column(array_column($duplicate, 'data'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertSame(2, $ticket->fresh()->lock_version);
        $this->assertSame(1, $ticket->approvals()->count());
        $this->assertSame(1, $ticket->events()->where('type', 'approval_requested')->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count());

        $approval = $ticket->approvals()->sole();
        $opposed = $this->race([$first, $first], ['approve', 'reject'], (string) Str::uuid(), $ticket->fresh(), $approval, independentCommands: true);
        $statuses = array_column($opposed, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        $this->assertSame(3, $ticket->fresh()->lock_version);
        $this->assertContains($approval->fresh()->status, ['approved', 'rejected']);
        $this->assertSame(1, $ticket->events()->whereIn('type', ['approval_approved', 'approval_rejected'])->count());
        $this->assertSame(2, ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count());

        $cancelTicket = $this->ticket($site);
        $cancelled = $this->race([$raiser, $raiser], ['request', 'cancel-request'], (string) Str::uuid(), $cancelTicket, primary: $first);
        $this->assertSame($cancelled[0]['status'], $cancelled[1]['status']);
        $committed = $cancelled[0]['status'] === 'committed';
        $this->assertContains($cancelled[0]['status'], ['committed', 'cancelled']);
        $this->assertSame($committed ? 2 : 1, $cancelTicket->fresh()->lock_version);
        $this->assertSame($committed ? 1 : 0, $cancelTicket->approvals()->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $cancelTicket->id)->count());

        $settlement = $this->ticket($site);
        $pending = ItTicketApproval::query()->create(['it_ticket_id' => $settlement->id, 'requested_by' => $raiser->id, 'status' => 'pending']);
        $outcomes = $this->race([$first, $second], ['approve', 'resolve'], (string) Str::uuid(), $settlement, $pending);
        $this->assertSame('committed', $outcomes[0]['status']);
        $this->assertContains($outcomes[1]['status'], ['approval_blocked', 'stale_ticket']);
        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame('open', $settlement->fresh()->status);
        $this->assertSame(2, $settlement->fresh()->lock_version);
        $this->assertSame(0, $settlement->comments()->count());
    }

    public function test_actual_postcommit_failure_recovers_exact_approval_without_duplicate_outbox(): void
    {
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $actor = $this->actor($site);
        $primary = $this->actor($site);
        $ticket = $this->ticket($site);
        $input = ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'reason' => 'Synthetic exact request', 'primary_approver_user_id' => $primary->id];
        $connection = DB::connection();
        $thrown = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($connection, &$thrown): void {
            if ($event->connection === $connection && $connection->transactionLevel() === 0 && ! $thrown) {
                $thrown = true;
                throw new RuntimeException('Synthetic outer approval acknowledgement failure');
            }
        });
        try {
            $result = app(ItTicketApprovalCommandService::class)->execute($ticket, $actor, 'request', $input);
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        $this->assertTrue($thrown);
        $this->assertSame('committed', $result->status);
        $this->assertTrue($result->data['replayed']);
        $this->assertSame(2, $result->data['lock_version']);
        $this->assertSame(1, $ticket->approvals()->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.approval.requested')->where('auditable_id', $ticket->id)->count());
    }

    public function test_timing_and_withdrawal_races_preserve_one_canonical_outcome(): void
    {
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $raiser = $this->actor($site);
        $primary = $this->actor($site);
        $ticket = $this->ticket($site);
        $approval = ItTicketApproval::query()->create(['it_ticket_id' => $ticket->id, 'requested_by' => $raiser->id,
            'status' => 'pending', 'primary_approver_user_id' => $primary->id, 'assignment_recorded_at' => now(),
            'expires_at' => now()->addDay(), 'remind_at' => now()->subMinute()]);
        $reminders = $this->race([$raiser, $raiser], ['timing', 'timing'], (string) Str::uuid(), $ticket, $approval);
        $outcomes = array_column($reminders, 'status');
        sort($outcomes);
        $this->assertSame(['reminder_prepared', 'skipped'], $outcomes);
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count());
        $this->assertSame(1, $ticket->events()->where('type', 'approval_reminder_prepared')->count());
        $this->assertSame(2, $ticket->fresh()->lock_version);

        $decisions = $this->race([$raiser, $primary], ['withdraw', 'approve'], (string) Str::uuid(), $ticket->fresh(), $approval);
        $outcomes = array_column($decisions, 'status');
        sort($outcomes);
        $this->assertSame(['committed', 'stale_ticket'], $outcomes);
        $this->assertContains($approval->fresh()->status, ['cancelled', 'approved']);
        $this->assertSame(1, $ticket->events()->whereIn('type', ['approval_cancelled', 'approval_approved'])->count());
        $this->assertSame(3, $ticket->fresh()->lock_version);

        $expiredTicket = $this->ticket($site);
        $expired = ItTicketApproval::query()->create(['it_ticket_id' => $expiredTicket->id, 'requested_by' => $raiser->id,
            'status' => 'pending', 'primary_approver_user_id' => $primary->id, 'assignment_recorded_at' => now(),
            'expires_at' => now()->subMinute()]);
        $timing = $this->race([$raiser, $primary], ['timing', 'approve'], (string) Str::uuid(), $expiredTicket, $expired);
        $this->assertSame('expired', $timing[0]['status']);
        $this->assertContains($timing[1]['status'], ['approval_blocked', 'stale_ticket']);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNull($expired->fresh()->approver_id);
        $this->assertNull($expired->fresh()->decided_at);
        $this->assertSame(1, $expiredTicket->events()->where('type', 'approval_expired')->count());
        $this->assertSame(2, $expiredTicket->fresh()->lock_version);
    }

    public function test_postrollback_different_hash_winner_cannot_acknowledge_original_approval(): void
    {
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $actor = $this->actor($site);
        $ticket = $this->ticket($site);
        $primary = $this->actor($site);
        $input = ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'reason' => 'Original proposal', 'primary_approver_user_id' => $primary->id];
        $connection = DB::connection();
        $replaced = false;
        Event::listen(TransactionCommitting::class, function ($event) use ($connection, &$replaced, $ticket, $actor, $input): void {
            if ($event->connection === $connection && $connection->transactionLevel() === 1 && ! $replaced) {
                $replaced = true;
                $connection->rollBack();
                app(ItTicketApprovalCommandService::class)->execute($ticket, $actor, 'request', [...$input, 'reason' => 'Different winning proposal']);
                throw new RuntimeException('Synthetic original approval commit unavailable');
            }
        });
        try {
            try {
                app(ItTicketApprovalCommandService::class)->execute($ticket, $actor, 'request', $input);
                $this->fail('A different proposal must not acknowledge the original approval intent.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic original approval commit unavailable', $exception->getMessage());
            }
        } finally {
            Event::forget(TransactionCommitting::class);
        }
        $this->assertTrue($replaced);
        $this->assertSame('Different winning proposal', $ticket->approvals()->sole()->request_reason);
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $ticket->id)->count());
        $this->assertSame(2, $ticket->fresh()->lock_version);
    }

    private function actor(Site $site): User
    {
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $actor;
    }

    private function ticket(Site $site): ItTicket
    {
        return ItTicket::factory()->create(['site_id' => $site->id, 'requires_approval' => true, 'status' => 'open', 'workflow_state' => 'submitted', 'lock_version' => 1]);
    }

    private function race(array $actors, array $operations, string $uuid, ItTicket $ticket, ?ItTicketApproval $approval = null, ?User $primary = null, bool $independentCommands = false): array
    {
        $barrier = storage_path('framework/testing/it-approval-concurrency-'.getenv('TEST_TOKEN').'-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/approval-command-concurrency-worker.php'),
                    $operation, (string) $actors[$index]->id, $independentCommands ? (string) Str::uuid() : $uuid, (string) $ticket->id, (string) $ticket->lock_version,
                    (string) ($approval?->id ?? 0), $ready, $attempt, $barrier.'.release', (string) ($primary?->id ?? 0)], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
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
                    throw new RuntimeException('An isolated approval worker stopped before its barrier.');
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The isolated approval workers did not reach their barrier.');
            }
            usleep(10_000);
        }
    }
}
