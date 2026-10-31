<?php

namespace Tests\Feature\HealthSafety;

use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

class RestraintCommandConcurrencyTest extends RestraintEventCommandTestCase
{
    public function test_two_exact_concurrent_corrections_return_one_retained_acceptance(): void
    {
        $row = $this->event();
        $payload = $this->command($row, ['reason' => 'Actual correction', 'person_response' => 'Factual response']);
        $results = $this->race($row, [$payload, $payload]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame([false, true], collect($results)->pluck('receipt.replayed')->sort()->values()->all());
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame(2, RestraintCommandReceipt::count());
    }

    public function test_two_distinct_commands_accept_one_and_preserve_stale_draft_without_partial_revision(): void
    {
        $row = $this->event();
        $results = $this->race($row, [$this->command($row, ['reason' => 'First factual correction', 'person_response' => 'First']), $this->command($row, ['reason' => 'Second factual correction', 'person_response' => 'Second'])]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $row->fresh()->source_revision);
        $this->assertSame(2, RestraintCommandReceipt::count());
    }

    /** Real MySQL sessions, with the existing committed-fixture cleanup contract. */
    private function race(RestraintEvent $event, array $payloads): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());

        $connection->commit();
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-restraint-go-'.$token;
        $ready = [];
        $processes = [];
        try {
            $connection->beginTransaction();
            RestraintEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            foreach ($payloads as $index => $payload) {
                $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-restraint-ready-'.$token.'-'.$index;
                $ready[] = $path;
                $config = $connection->getConfig();
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/RestraintEventCommandWorker.php'),
                    $connection->getDatabaseName(), (string) $this->actor->id, (string) $event->id,
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $path, $go,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                ]);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($ready)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('A restraint worker exited before its barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Restraint workers did not become ready.');
                }
                usleep(10000);
            }
            touch($go);
            usleep(250000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'The event command bypassed its canonical parent lock.');
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
            foreach ([...$ready, $go] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $connection->beginTransaction();
        }
    }
}
