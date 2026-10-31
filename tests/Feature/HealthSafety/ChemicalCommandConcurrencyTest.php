<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Real independent MySQL connections; authored only while runtime admission is held. */
final class ChemicalCommandConcurrencyTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;

    public function test_parallel_exact_uuid_retry_converges_without_duplicate_revision_receipt_or_audit(): void
    {
        $root = $this->catalogue(); $payload = $this->command($root, ['common_name' => 'Concurrent accepted fact']);
        $job = ['actor' => $this->manager->id, 'parent' => $root->id, 'operation' => 'update', 'payload' => $payload];
        $results = $this->race([$job, $job]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame($results[0]['receipt'], $results[1]['receipt']); $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalCommandReceipt::where('idempotency_key', $payload['idempotency_key'])->count());
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $payload['idempotency_key'])->count());
    }

    public function test_distinct_concurrent_intents_at_same_reviewed_revision_preserve_one_stale_draft(): void
    {
        $root = $this->catalogue(); $other = $this->staff(['hazards.manage']);
        $results = $this->race([
            ['actor' => $this->manager->id, 'parent' => $root->id, 'operation' => 'update', 'payload' => $this->command($root, ['name' => 'First intent'])],
            ['actor' => $other->id, 'parent' => $root->id, 'operation' => 'update', 'payload' => $this->command($root, ['name' => 'Second intent'])],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertContains($root->fresh()->name, ['First intent', 'Second intent']);
    }

    public function test_globally_absent_uuid_different_parents_and_actors_has_only_one_immutable_winner(): void
    {
        $first = $this->catalogue(); $second = $this->catalogue(); $actor = $this->staff(['hazards.manage']); $key = (string) Str::uuid();
        $results = $this->race([
            ['actor' => $this->manager->id, 'parent' => $first->id, 'operation' => 'update', 'payload' => $this->command($first, ['common_name' => 'First root'], $key)],
            ['actor' => $actor->id, 'parent' => $second->id, 'operation' => 'update', 'payload' => $this->command($second, ['common_name' => 'Second root'], $key)],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['idempotency_key'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $key)->count());
        $this->assertSame(1, ChemicalCommandReceipt::where('idempotency_key', $key)->count());
        $this->assertSame(3, $first->fresh()->source_revision + $second->fresh()->source_revision);
    }

    public function test_global_uuid_catalogue_and_new_storage_race_cannot_bind_two_source_families(): void
    {
        $first = $this->catalogue(); $second = $this->catalogue(); $key = (string) Str::uuid();
        $results = $this->race([
            ['actor' => $this->manager->id, 'parent' => $first->id, 'operation' => 'update', 'payload' => $this->command($first, ['common_name' => 'Catalogue intent'], $key)],
            ['actor' => $this->staff(['hazards.manage'])->id, 'parent' => $second->id, 'operation' => 'storage',
                'payload' => ['idempotency_key' => $key, 'parent_expected_version' => 1, 'site_id' => $this->site->id, 'location_description' => 'Storage intent']],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['idempotency_key'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $receipt = ChemicalCommandReceipt::where('idempotency_key', $key)->sole();
        $this->assertContains($receipt->source_type, ['catalogue', 'storage']);
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $key)->count());
    }

    public function test_committed_failed_sds_intent_cannot_be_claimed_by_parallel_ordinary_commands(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private'); $root = $this->catalogue(); $other = $this->catalogue(); $key = (string) Str::uuid();
        AuditLog::creating(fn ($row) => $row->action === 'chemical.upload_sds' ? false : null);
        try { $this->integrity(fn () => $this->service()->uploadSds($root, $this->manager,
            \Illuminate\Http\UploadedFile::fake()->create('retained.pdf', 4, 'application/pdf'),
            $this->command($root, ['version' => 'Retained attempt', 'issue_date' => '2026-10-01'], $key))); }
        finally { AuditLog::flushEventListeners(); }
        $stage = \App\Models\ChemicalEvidenceStaging::where('idempotency_key', $key)->sole(); $identity = $stage->attributesToArray();
        $results = $this->race([
            ['actor' => $this->manager->id, 'parent' => $root->id, 'operation' => 'update', 'payload' => $this->command($root, ['name' => 'Cannot steal SDS key'], $key)],
            ['actor' => $this->staff(['hazards.manage'])->id, 'parent' => $other->id, 'operation' => 'update', 'payload' => $this->command($other, ['name' => 'Cannot steal actor key'], $key)],
        ]);
        $this->assertSame(['rejected', 'rejected'], array_column($results, 'status'));
        $this->assertSame([['idempotency_key'], ['idempotency_key']], array_column($results, 'fields'));
        $this->assertSame($identity, $stage->fresh()->attributesToArray()); $this->assertNull($stage->receipt_id);
        $this->assertSame(0, ChemicalCommandReceipt::where('idempotency_key', $key)->count());
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(1, $other->fresh()->source_revision);
    }

    private function race(array $jobs): array
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Chemical races require the admitted physical MySQL test schema.');
        }
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-chemical-race-'.Str::uuid(); $go = $prefix.'-go'; $ready = []; $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $ready[] = $prefix.'-ready-'.$index; $config = $connection->getConfig();
                $p = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/ChemicalCommandWorker.php'),
                    $connection->getDatabaseName(), (string) $job['actor'], (string) $job['parent'], $job['operation'],
                    base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $ready[$index], $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $p->setTimeout(60); $p->start(); $processes[] = $p;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($jobs)) {
                foreach ($processes as $p) { if (! $p->isRunning()) { throw new \RuntimeException('Chemical worker exited before readiness: '.$p->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Chemical readiness timed out.'); } usleep(10000);
            }
            touch($go); $results = [];
            foreach ($processes as $p) { $p->wait(); $this->assertTrue($p->isSuccessful(), $p->getErrorOutput()); $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($processes as $p) { if ($p->isRunning()) { $p->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }
}
