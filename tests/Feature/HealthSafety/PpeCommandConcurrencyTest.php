<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeCommandReceipt;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Whole real MySQL races; workers run only after coordinator admission. */
final class PpeCommandConcurrencyTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    public function test_parallel_exact_uuid_converges_to_same_acceptance_without_duplicate_audit(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Exact concurrent rack']);
        $job = ['actor' => $this->manager->id, 'operation' => 'update', 'payload' => $input];
        $results = $this->race($item, [$job, $job]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(2, $item->fresh()->source_revision);
        $this->assertSame(1, AuditLog::where('action', 'ppe.update_inventory')->where('meta->idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_parallel_distinct_intents_same_revision_accept_one_and_preserve_stale_other(): void
    {
        $item = $this->item(); $other = $this->staff(['hazards.view', 'hazards.manage']);
        $results = $this->race($item, [
            ['actor' => $this->manager->id, 'operation' => 'update', 'payload' => $this->command($item, ['location' => 'First intent'])],
            ['actor' => $other->id, 'operation' => 'update', 'payload' => $this->command($item, ['location' => 'Second intent'])],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $item->fresh()->source_revision);
        $this->assertContains($item->fresh()->location, ['First intent', 'Second intent']);
    }
    public function test_parallel_issue_cannot_allocate_whole_record_twice_or_reduce_quantity(): void
    {
        $item = $this->item();
        $results = $this->race($item, [
            ['actor' => $this->manager->id, 'operation' => 'allocate', 'payload' => $this->command($item, ['user_id' => $this->worker->id])],
            ['actor' => $this->manager->id, 'operation' => 'allocate', 'payload' => $this->command($item, ['user_id' => $this->worker->id])],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(1, $item->allocations()->whereNull('returned_at')->count());
        $this->assertSame(3, $item->fresh()->quantity); $this->assertSame('allocated', $item->fresh()->status);
        $this->assertSame(2, PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $item->id)->count());
    }
    public function test_parallel_other_commands_cannot_claim_uuid_of_a_physically_committed_failed_file_attempt(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $item = $this->item(); $key = (string) Str::uuid();
        AuditLog::creating(fn ($row) => $row->action === 'ppe.upload_attachment' ? false : null);
        try {
            $this->integrity(fn () => $this->service()->uploadAttachment('inventory', $item->id, $this->manager,
                $this->command($item, ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('race.txt', 'Retained UUID intent')], $key)));
        } finally { AuditLog::flushEventListeners(); }
        $attempt = \App\Models\PpeEvidenceStaging::where('idempotency_key', $key)->sole();
        $identity = $attempt->identity(); $other = $this->staff(['hazards.manage']);
        $results = $this->race($item, [
            ['actor' => $this->manager->id, 'operation' => 'update', 'payload' => $this->command($item, ['location' => 'First rewrite'], $key)],
            ['actor' => $other->id, 'operation' => 'update', 'payload' => $this->command($item, ['location' => 'Second rewrite'], $key)],
        ]);
        $this->assertSame(['rejected', 'rejected'], array_column($results, 'status'));
        $this->assertSame([['idempotency_key'], ['idempotency_key']], array_column($results, 'fields'));
        $this->assertSame($identity, $attempt->fresh()->identity()); $this->assertNull($attempt->fresh()->receipt_id);
        $this->assertSame(1, $item->fresh()->source_revision); $this->assertNull($item->fresh()->location);
        $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $key)->count());
        $this->assertSame(0, AuditLog::where('action', 'ppe.update_inventory')->where('meta->idempotency_key', $key)->count());
    }
    private function race(PpeInventory $item, array $jobs): array
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('PPE races require the admitted physical MySQL test schema.');
        }
        $token = (string) Str::uuid(); $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-go-'.$token;
        $ready = []; $processes = [];
        try {
            $connection->beginTransaction(); PpeInventory::whereKey($item->id)->lockForUpdate()->firstOrFail();
            foreach ($jobs as $index => $job) {
                $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-ready-'.$token.'-'.$index; $ready[] = $path;
                $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/PpeCommandWorker.php'),
                    $connection->getDatabaseName(), (string) $job['actor'], (string) $item->id, $job['operation'],
                    base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $path, $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(60); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($ready)) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new RuntimeException('PPE worker exited before the ready barrier.'); } }
                if (microtime(true) >= $deadline) { throw new RuntimeException('PPE ready barrier timed out.'); }
                usleep(10000);
            }
            touch($go); usleep(250000);
            foreach ($processes as $process) { $this->assertTrue($process->isRunning(), 'PPE command bypassed its held root lock.'); }
            $connection->commit(); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            while ($connection->transactionLevel() > 0) { $connection->rollBack(); }
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }
    public function test_absent_uuid_two_file_reservations_on_different_roots_and_actors_have_one_durable_winner(): void
    {
        $this->firstClaimRace(['upload', 'upload']);
    }
    public function test_absent_uuid_file_reservation_and_ordinary_command_cannot_claim_different_intents(): void
    {
        $this->firstClaimRace(['upload', 'update']);
    }
    public function test_absent_uuid_ordinary_commands_on_different_roots_and_actors_have_one_accepted_intent(): void
    {
        $this->firstClaimRace(['update', 'update']);
    }
    private function firstClaimRace(array $operations): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('First-claim races require the admitted physical MySQL test schema.');
        }
        \Illuminate\Support\Facades\Storage::fake('private');
        $items = [$this->item(), $this->item()]; $actors = [$this->manager, $this->staff(['hazards.view', 'hazards.manage'])];
        $key = strtolower((string) Str::uuid()); $token = (string) Str::uuid();
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-intent-'.$token;
        $go = $prefix.'-go'; $release = $prefix.'-release'; $ready = []; $claimed = []; $processes = [];
        $this->assertSame(0, \App\Models\PpeCommandIntentClaim::where('idempotency_key', $key)->count());
        try {
            foreach ($operations as $index => $operation) {
                $ready[] = $prefix.'-ready-'.$index; $claimed[] = $prefix.'-claimed-'.$index;
                $input = $this->command($items[$index], $operation === 'upload'
                    ? ['_worker_bytes' => 'Distinct actor upload '.$index] : ['location' => 'Distinct actor rack '.$index], $key);
                $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/PpeIntentClaimWorker.php'),
                    $connection->getDatabaseName(), (string) $actors[$index]->id, (string) $items[$index]->id, $operation,
                    base64_encode(json_encode($input, JSON_THROW_ON_ERROR)), $ready[$index], $go, $claimed[$index], $release,
                    \Illuminate\Support\Facades\Storage::disk('private')->path('')], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(70); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== 2) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new RuntimeException('First-claim worker exited before readiness: '.$process->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new RuntimeException('First-claim ready barrier timed out.'); } usleep(10000);
            }
            touch($go); $deadline = microtime(true) + 30;
            while (count(array_filter($claimed, 'is_file')) === 0) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new RuntimeException('First-claim worker exited before unique insertion: '.$process->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new RuntimeException('First unique claim timed out.'); } usleep(10000);
            }
            usleep(250000);
            $this->assertSame(1, count(array_filter($claimed, 'is_file')), 'Only the unique insert winner reaches the held pre-receipt barrier.');
            foreach ($processes as $process) { $this->assertTrue($process->isRunning(), 'Both genuinely concurrent intents must remain pending at the first-claim barrier.'); }
            $this->assertSame(0, PpeCommandReceipt::where('idempotency_key', $key)->count());
            $this->assertSame(0, \App\Models\PpeEvidenceStaging::where('idempotency_key', $key)->count());
            touch($release); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
            $loser = $results[0]['status'] === 'rejected' ? 0 : 1; $winner = 1 - $loser;
            $this->assertSame(['idempotency_key'], $results[$loser]['fields']);
            $claim = \App\Models\PpeCommandIntentClaim::where('idempotency_key', $key)->sole();
            $this->assertSame($actors[$winner]->id, $claim->user_id); $this->assertSame($items[$winner]->id, $claim->root_id);
            $this->assertSame($results[$winner]['receipt'], PpeCommandReceipt::where('idempotency_key', $key)->sole()->accepted_result);
            $this->assertSame(2, $items[$winner]->fresh()->source_revision); $this->assertSame(1, $items[$loser]->fresh()->source_revision);
            $this->assertSame(1, AuditLog::where('meta->idempotency_key', $key)->count());
            $stages = \App\Models\PpeEvidenceStaging::where('idempotency_key', $key)->get();
            $this->assertCount($operations[$winner] === 'upload' ? 1 : 0, $stages);
            if ($stages->isNotEmpty()) { $this->assertSame('accepted', $stages->sole()->state); }
            $this->assertCount($operations[$winner] === 'upload' ? 1 : 0, \Illuminate\Support\Facades\Storage::disk('private')->allFiles());
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, ...$claimed, $go, $release] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }
}
