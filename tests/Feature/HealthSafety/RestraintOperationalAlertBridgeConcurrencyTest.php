<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\RestraintEvent;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/** Real native sessions: internal source convergence, not entry-point actor authorization. */
final class RestraintOperationalAlertBridgeConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Parent PHP and each child have explicit limits, never unlimited memory.
        ini_set('memory_limit', '1024M');
        parent::setUp();
        $this->assertSame('1024M', ini_get('memory_limit'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $database = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/^oblivion_hs_goal_test_[0-9]+$/D', $database);
        $this->assertSame($database, getenv('DB_DATABASE'));
        Queue::fake();
    }

    public function test_two_same_uuid_deliveries_block_on_source_then_converge_to_one_alert_receipt_and_audit(): void
    {
        $event = $this->event();
        $payload = $this->payload($event);
        $results = $this->race($event, [$payload, $payload]);

        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertCount(1, array_unique(array_column($results, 'alert_id')));
        $alert = $this->canonicalAlert($event);
        $this->assertSame($alert->id, $results[0]['alert_id']);
        $this->assertSame([$payload['context']['delivery_id']], array_column($alert->context['source_deliveries'], 'delivery_id'));
        $this->assertSame([1], array_column($alert->context['source_deliveries'], 'source_revision'));
        $this->assertSame(1, $this->acceptanceAudits($event));
        $this->assertSame(1, RestraintEvent::findOrFail($event->id)->source_revision);
    }

    public function test_two_distinct_uuids_for_one_revision_block_on_source_and_keep_one_alert_with_both_acceptances(): void
    {
        $event = $this->event();
        $payloads = [$this->payload($event), $this->payload($event)];
        $results = $this->race($event, $payloads);

        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertCount(1, array_unique(array_column($results, 'alert_id')));
        $alert = $this->canonicalAlert($event);
        $this->assertSame($alert->id, $results[0]['alert_id']);
        $this->assertEqualsCanonicalizing(array_column(array_column($payloads, 'context'), 'delivery_id'), array_column($alert->context['source_deliveries'], 'delivery_id'));
        $this->assertSame([1, 1], array_column($alert->context['source_deliveries'], 'source_revision'));
        $this->assertSame(2, $this->acceptanceAudits($event));
        foreach ($payloads as $payload) {
            $this->assertSame(1, AuditLog::where('action', 'control_room.restraint_source_accepted')
                ->where('meta->restraint_event_id', $event->id)->where('meta->delivery_id', $payload['context']['delivery_id'])->count());
        }
    }

    public function test_revision_advance_while_both_workers_wait_rejects_novel_old_revision_without_creation(): void
    {
        $event = $this->event();
        $results = $this->race($event, [$this->payload($event), $this->payload($event)],
            fn () => RestraintEvent::whereKey($event->id)->update(['source_revision' => 2]));

        $this->assertSame(['rejected', 'rejected'], array_column($results, 'status'));
        $this->assertSame([\InvalidArgumentException::class, \InvalidArgumentException::class], array_column($results, 'error_class'));
        $this->assertSame(0, $this->alerts($event)->count());
        $this->assertSame(0, $this->acceptanceAudits($event));
        $this->assertSame(2, RestraintEvent::findOrFail($event->id)->source_revision);
    }

    public function test_blocked_historical_uuid_replay_preserves_terminal_receipt_while_novel_stale_uuid_is_rejected(): void
    {
        $event = $this->event();
        $accepted = $this->payload($event);
        $alert = app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event', $accepted['severity'], $accepted['context']);
        $alert->forceFill(['status' => ControlRoomAlert::STATUS_CLOSED, 'closed_at' => now()])->saveQuietly();
        $before = $alert->fresh()->getRawOriginal();
        $results = $this->race($event, [$accepted, $this->payload($event)],
            fn () => RestraintEvent::whereKey($event->id)->update(['source_revision' => 2, 'injury_occurred' => true]));

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame($alert->id, $results[0]['alert_id']);
        $this->assertSame('rejected', $results[1]['status']);
        $this->assertSame(\InvalidArgumentException::class, $results[1]['error_class']);
        $this->assertSame($before, $this->canonicalAlert($event)->getRawOriginal());
        $this->assertSame(1, $this->acceptanceAudits($event));
        $this->assertSame(2, RestraintEvent::findOrFail($event->id)->source_revision);
        $this->assertTrue(RestraintEvent::findOrFail($event->id)->injury_occurred);
    }

    private function event(): RestraintEvent
    {
        return RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->outsideSupportPlan()->create(['source_revision' => 1, 'injury_occurred' => false]));
    }

    private function payload(RestraintEvent $event): array
    {
        return ['severity' => 'medium', 'context' => ['restraint_event_id' => (int) $event->id,
            'client_id' => $event->client_id, 'site_id' => $event->site_id,
            'source_revision' => (int) $event->source_revision, 'delivery_id' => (string) Str::uuid()]];
    }

    private function alerts(RestraintEvent $event): Builder
    {
        return ControlRoomAlert::where('source', 'operations')->where('alert_type', 'operations.restraint_event')
            ->where('context->restraint_event_id', $event->id)->where('client_id', $event->client_id)->where('site_id', $event->site_id);
    }

    private function canonicalAlert(RestraintEvent $event): ControlRoomAlert
    {
        $this->assertSame(1, $this->alerts($event)->count());

        return $this->alerts($event)->sole();
    }

    private function acceptanceAudits(RestraintEvent $event): int
    {
        return AuditLog::where('action', 'control_room.restraint_source_accepted')->where('meta->restraint_event_id', $event->id)->count();
    }

    /** Each race proves BOTH live MySQL sessions reached the actual held source lock. */
    private function race(RestraintEvent $event, array $payloads, ?callable $whileLocked = null): array
    {
        $this->assertCount(2, $payloads);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());
        $beforeAlerts = $this->alerts($event)->orderBy('id')->get()->map(fn (ControlRoomAlert $alert) => $alert->getRawOriginal())->all();
        $beforeAudits = $this->acceptanceAudits($event);
        $connection->commit();
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-restraint-go-'.$token;
        $ready = $attempt = $processes = [];
        try {
            $connection->beginTransaction();
            RestraintEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            foreach ($payloads as $index => $payload) {
                $ready[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-restraint-ready-'.$token.'-'.$index;
                $attempt[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-restraint-attempt-'.$token.'-'.$index;
                $config = $connection->getConfig();
                $process = new Process([
                    PHP_BINARY, '-d', 'memory_limit=512M', base_path('tests/Support/RestraintOperationalAlertBridgeWorker.php'),
                    $connection->getDatabaseName(), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    $ready[$index], $attempt[$index], $go,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                ]);
                $processes[] = $process;
                $process->setTimeout(120);
                $process->start();
            }
            $this->waitForFiles($ready, $processes);
            $workers = array_map(fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $ready);
            $this->assertCount(2, array_unique(array_column($workers, 'pid')));
            $connectionIds = array_column($workers, 'connection_id');
            $this->assertCount(2, array_unique($connectionIds));
            $this->assertNotContains((int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id, $connectionIds);
            touch($go);
            $this->waitForFiles($attempt, $processes);
            $this->waitForSourceLocks($connectionIds, $processes);
            $this->assertSame($beforeAlerts, $this->alerts($event)->orderBy('id')->get()->map(fn (ControlRoomAlert $alert) => $alert->getRawOriginal())->all());
            $this->assertSame($beforeAudits, $this->acceptanceAudits($event));
            if ($whileLocked !== null) {
                $whileLocked();
            }
            $connection->commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$ready, ...array_map(fn ($path) => $path.'.tmp', $ready), ...$attempt, $go] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $connection->beginTransaction();
        }
    }

    private function waitForFiles(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            $this->assertWorkersAlive($processes);
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Restraint workers did not reach the ready/attempt barrier.');
            }
            usleep(10000);
        }
    }

    private function waitForSourceLocks(array $connectionIds, array $processes): void
    {
        $deadline = microtime(true) + 30;
        do {
            $this->assertWorkersAlive($processes);
            $waiters = DB::select("SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.innodb_trx
                WHERE trx_mysql_thread_id IN (?, ?) AND trx_state = 'LOCK WAIT'", $connectionIds);
            if (count($waiters) === 2) {
                foreach ($waiters as $waiter) {
                    $sql = strtolower(str_replace('`', '', (string) $waiter->trx_query));
                    $this->assertStringContainsString('from restraint_events', $sql);
                    $this->assertStringContainsString('for update', $sql);
                }

                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Both native worker sessions must wait on the canonical restraint event row.');
    }

    private function assertWorkersAlive(array $processes): void
    {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('A restraint worker exited before source-lock release: '.$process->getErrorOutput());
            }
        }
    }
}
