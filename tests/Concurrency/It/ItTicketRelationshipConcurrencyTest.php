<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone committed transactions; only the wrapper parent prepares/removes its schema. */
final class ItTicketRelationshipConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $prepared = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $prepared)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone relationship concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_serialize_duplicate_opposite_and_cancelled_relationships(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        $site = Site::factory()->create();
        $actor = $this->actor($site, 'hr');
        $requester = $this->actor($site, 'support_worker');
        $attributes = ['site_id' => $site->id, 'requester_user_id' => $requester->id,
            'requested_for_user_id' => $requester->id, 'status' => 'open', 'workflow_state' => 'submitted',
            'lock_version' => 1, 'is_sensitive' => false, 'is_organisation_wide' => false];

        foreach (['duplicate', 'opposite', 'cancel'] as $round) {
            $source = ItTicket::factory()->create($attributes);
            $target = ItTicket::factory()->create($attributes);
            $public = $source->comments()->create(['author_user_id' => $requester->id, 'body' => 'Synthetic public race evidence', 'is_internal' => false]);
            $private = $source->comments()->create(['author_user_id' => $actor->id, 'body' => 'Synthetic internal race evidence', 'is_internal' => true]);
            $first = $this->input($source, $target, $actor);
            $second = $round === 'opposite' ? $this->input($target, $source, $actor) : $first;
            $outcomes = $this->race($actor, $source, $target, $first, $second, $round);
            $statuses = array_column($outcomes, 'status');
            if ($round === 'duplicate') {
                $this->assertSame(['committed', 'committed'], $statuses);
                $replays = array_column(array_column($outcomes, 'data'), 'replayed');
                sort($replays);
                $this->assertSame([false, true], $replays);
            } elseif ($round === 'opposite') {
                sort($statuses);
                $this->assertSame(['committed', 'stale_ticket'], $statuses);
            } else {
                $this->assertSame($statuses[0], $statuses[1]);
                $this->assertContains($statuses[0], ['committed', 'cancelled']);
            }

            $cancelled = $statuses[0] === 'cancelled';
            $this->assertSame($cancelled ? 0 : 2, ItTicketLink::query()
                ->whereIn('ticket_id', [$source->id, $target->id])->where('relationship', 'related_ticket')->count());
            $this->assertSame(1, ItTicketCommandReceipt::query()->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
                ->whereIn('it_ticket_id', [$source->id, $target->id])->count());
            $this->assertSame($cancelled ? 0 : 2, AuditLog::query()->where('action', 'it.ticket.relationship.add')
                ->whereIn('auditable_id', [$source->id, $target->id])->count());
            $this->assertSame($cancelled ? 0 : 2, ItTicketEvent::query()->where('type', 'related_work_linked')
                ->where('subject_type', $source->getMorphClass())->whereIn('subject_id', [$source->id, $target->id])->count());
            foreach ([$source->fresh(), $target->fresh()] as $ticket) {
                $this->assertNull($ticket->merged_into_ticket_id);
                $this->assertSame('open', $ticket->status);
                $this->assertSame($cancelled ? 1 : 2, $ticket->lock_version);
            }
            $this->assertSame($source->id, $public->fresh()->ticket_id);
            $this->assertSame($source->id, $private->fresh()->ticket_id);
            $this->assertFalse($public->fresh()->is_internal);
            $this->assertTrue($private->fresh()->is_internal);
            $this->assertSame($requester->id, $public->fresh()->author_user_id);
            $this->assertSame($actor->id, $private->fresh()->author_user_id);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function actor(Site $site, string $role): User
    {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $actor;
    }

    private function input(ItTicket $source, ItTicket $target, User $actor): array
    {
        return ['actor_user_id' => $actor->id, 'source_version' => 1, 'target_version' => 1,
            'request_uuid' => (string) Str::uuid(), 'relationship' => 'related_ticket', 'action' => 'add'];
    }

    private function race(User $actor, ItTicket $source, ItTicket $target, array $first, array $second, string $round): array
    {
        $barrier = storage_path('framework/testing/it-relationship-concurrency-'.getenv('TEST_TOKEN').'-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $paths = [];
        $processes = [];
        DB::beginTransaction();
        ItTicket::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get();
        try {
            foreach ([$first, $second] as $index => $input) {
                $paths[] = $ready = $barrier.'-'.$index.'.ready';
                $paths[] = $attempt = $barrier.'-'.$index.'.attempt';
                $reverse = $index === 1 && $round === 'opposite';
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/relationship-concurrency-worker.php'),
                    $index === 1 && $round === 'cancel' ? 'cancel' : 'execute', (string) $actor->id,
                    (string) ($reverse ? $target->id : $source->id), (string) ($reverse ? $source->id : $target->id),
                    json_encode($input, JSON_THROW_ON_ERROR), $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            usleep(250_000);
            foreach ($processes as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both relationship commands must wait behind the held canonical pair.');
            }
            $releasedAt = microtime(true);
            DB::commit();
            $results = [];
            foreach ($processes as $worker) {
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
            foreach ($processes as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
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
                $this->fail('The isolated relationship workers did not reach their barrier.');
            }
            foreach ($processes as $worker) {
                if (! $worker->isRunning()) {
                    $this->fail($worker->getErrorOutput().' '.$worker->getOutput());
                }
            }
            usleep(10_000);
        }
    }
}
