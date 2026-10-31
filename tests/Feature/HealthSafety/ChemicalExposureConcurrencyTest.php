<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidenceStaging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Genuine simultaneous first-claim barriers; no process is launched until the sole runner admits this whole suite. */
final class ChemicalExposureConcurrencyTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function receiver() { return $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); }

    public function test_absent_uuid_private_different_root_and_actor_first_claim_race_has_one_immutable_winner(): void
    {
        $first = $this->exposure(); $second = $this->exposure(); $key = (string) Str::uuid(); $other = $this->receiver();
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $first->id, 'operation' => 'correct', 'payload' => $this->privateCommand($first,
                ['reason' => 'First actual intent', 'circumstances' => 'First source fact'], $key)],
            ['actor' => $other->id, 'source' => $second->id, 'operation' => 'correct', 'payload' => $this->privateCommand($second,
                ['reason' => 'Second actual intent', 'circumstances' => 'Second source fact'], $key)],
        ], $key);
        $this->oneWinner($results, $key); $this->assertSame(3, $first->fresh()->source_revision + $second->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureCommandReceipt::where('idempotency_key', $key)->count());
    }

    public function test_absent_uuid_public_private_race_cannot_bind_two_source_families(): void
    {
        $private = $this->exposure(); $public = $this->catalogue(); $key = (string) Str::uuid(); $other = $this->receiver();
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $private->id, 'operation' => 'correct', 'payload' => $this->privateCommand($private,
                ['reason' => 'Private intent', 'symptoms' => 'Private reported fact'], $key)],
            ['actor' => $other->id, 'source' => $public->id, 'operation' => 'public_update',
                'payload' => $this->command($public, ['common_name' => 'Public intended label'], $key)],
        ], $key);
        $this->oneWinner($results, $key);
        $this->assertSame(1, DB::table('chemical_command_receipts')->where('idempotency_key', $key)->count()
            + ChemicalExposureCommandReceipt::where('idempotency_key', $key)->count());
    }

    public function test_absent_uuid_file_reservation_and_ordinary_intent_race_cannot_stage_losing_bytes(): void
    {
        $first = $this->exposure(); $second = $this->exposure(); $key = (string) Str::uuid(); $other = $this->receiver();
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $first->id, 'operation' => 'upload',
                'payload' => $this->privateCommand($first, ['description' => 'Exact provided evidence'], $key)],
            ['actor' => $other->id, 'source' => $second->id, 'operation' => 'correct', 'payload' => $this->privateCommand($second,
                ['reason' => 'Ordinary original intent', 'circumstances' => 'Actual correction'], $key)],
        ], $key);
        $this->oneWinner($results, $key); $intent = ChemicalCommandIntent::where('idempotency_key', $key)->sole();
        $this->assertSame($intent->command === 'upload_evidence' ? 1 : 0, ChemicalExposureEvidenceStaging::where('intent_id', $intent->id)->count());
        $this->assertSame(3, $first->fresh()->source_revision + $second->fresh()->source_revision);
    }

    public function test_absent_uuid_two_prospective_captures_cannot_certify_two_private_occurrences(): void
    {
        $first = $this->catalogue(); $second = $this->catalogue(); $key = (string) Str::uuid(); $other = $this->receiver();
        $payload = ['idempotency_key' => $key, 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'inhalation'];
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $first->id, 'operation' => 'capture', 'payload' => $payload],
            ['actor' => $other->id, 'source' => $second->id, 'operation' => 'capture', 'payload' => $payload],
        ], $key);
        $this->oneWinner($results, $key); $this->assertSame(1, \App\Models\SubstanceExposureRecord::count());
    }

    public function test_distinct_current_intents_on_same_aggregate_preserve_one_stale_draft(): void
    {
        $root = $this->exposure(); $other = $this->receiver();
        $first = $this->privateCommand($root, ['reason' => 'First actual intent', 'circumstances' => 'First']);
        $second = $this->privateCommand($root, ['reason' => 'Second actual intent', 'circumstances' => 'Second']);
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $root->id, 'operation' => 'correct', 'payload' => $first],
            ['actor' => $other->id, 'source' => $root->id, 'operation' => 'correct', 'payload' => $second],
        ], $first['idempotency_key'], false);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalCommandIntent::whereIn('idempotency_key', [$first['idempotency_key'], $second['idempotency_key']])->count());
    }

    public function test_parallel_exact_actor_payload_retry_converges_to_one_revision_audit_and_receipt(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'One retained intent', 'symptoms' => 'Actual reported fact']);
        $job = ['actor' => $this->manager->id, 'source' => $root->id, 'operation' => 'correct', 'payload' => $input];
        $results = $this->race([$job, $job], $input['idempotency_key'], false);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status')); $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_two_distinct_followup_transfers_at_one_revision_have_one_authoritative_named_receiver(): void
    {
        $root = $this->exposure(); $old = $this->receiver(); $firstReceiver = $this->receiver(); $secondReceiver = $this->receiver(); $otherActor = $this->receiver();
        $assigned = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $old->id, 'instruction' => 'Actual pending action', 'due_at' => '2026-10-13T01:00:00Z']));
        $first = $this->privateCommand($root, ['followup_id' => $assigned['source_id'], 'assigned_to' => $firstReceiver->id, 'reason' => 'First transfer intent']);
        $second = $this->privateCommand($root, ['followup_id' => $assigned['source_id'], 'assigned_to' => $secondReceiver->id, 'reason' => 'Second transfer intent']);
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $root->id, 'operation' => 'reassign', 'payload' => $first],
            ['actor' => $otherActor->id, 'source' => $root->id, 'operation' => 'reassign', 'payload' => $second],
        ], $first['idempotency_key'], false);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $winner = collect($results)->firstWhere('status', 'accepted')['receipt'];
        $version = \App\Models\ChemicalExposureSourceVersion::findOrFail($winner['source_version_id']);
        $child = \App\Models\ChemicalExposureFollowup::findOrFail($assigned['source_id']);
        $this->assertSame($version->content['facts']['recipient_id'], $child->assigned_to); $this->assertSame(3, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureCommandReceipt::whereIn('idempotency_key', [$first['idempotency_key'], $second['idempotency_key']])->count());
        $this->assertSame('superseded', \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $assigned['source_version_id'])->sole()->state);
    }
    public function test_transfer_racing_named_completion_preserves_one_exact_domain_outcome_and_stale_loser(): void
    {
        $root = $this->exposure(); $old = $this->receiver(); $new = $this->receiver();
        $assigned = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $old->id, 'instruction' => 'Actual pending action', 'due_at' => '2026-10-13T01:00:00Z']));
        $transfer = $this->privateCommand($root, ['followup_id' => $assigned['source_id'], 'assigned_to' => $new->id, 'reason' => 'Reviewed transfer']);
        $complete = $this->privateCommand($root, ['followup_id' => $assigned['source_id'], 'completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completion']);
        $results = $this->race([
            ['actor' => $this->manager->id, 'source' => $root->id, 'operation' => 'reassign', 'payload' => $transfer],
            ['actor' => $old->id, 'source' => $root->id, 'operation' => 'complete_followup', 'payload' => $complete],
        ], $transfer['idempotency_key'], false);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $winner = collect($results)->firstWhere('status', 'accepted')['receipt']; $child = \App\Models\ChemicalExposureFollowup::findOrFail($assigned['source_id']);
        if ($winner['command'] === 'reassign_followup') { $this->assertSame($new->id, $child->assigned_to); $this->assertNull($child->completed_at); }
        else { $this->assertSame('complete_followup', $winner['command']); $this->assertSame($old->id, $child->completed_by); $this->assertSame($old->id, $child->assigned_to); }
        $this->assertSame(3, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureCommandReceipt::whereIn('idempotency_key', [$transfer['idempotency_key'], $complete['idempotency_key']])->count());
    }
    private function oneWinner(array $results, string $key): void
    {
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['idempotency_key'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $key)->count());
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $key)->count());
    }

    private function race(array $jobs, string $key, bool $firstClaim = true): array
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Private chemical races require the admitted physical MySQL test schema.');
        }
        $this->assertSame(0, ChemicalCommandIntent::where('idempotency_key', $key)->count());
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-private-chemical-race-'.Str::uuid(); $go = $prefix.'-go'; $bytes = $prefix.'-bytes';
        $ready = []; $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $ready[] = $prefix.'-ready-'.$index; $config = $connection->getConfig();
                $p = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/ChemicalExposureCommandWorker.php'),
                    $connection->getDatabaseName(), (string) $job['actor'], (string) $job['source'], $job['operation'],
                    base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $ready[$index], $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                        'EXPOSURE_TEST_STORAGE_ROOT' => $bytes, 'EXPOSURE_TEST_BARRIER_MODE' => $firstClaim ? 'first_claim' : 'start']);
                $p->setTimeout(60); $p->start(); $processes[] = $p;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($jobs)) {
                foreach ($processes as $p) { if (! $p->isRunning()) { throw new \RuntimeException('Private worker exited before first claim: '.$p->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Private first-claim readiness timed out.'); } usleep(10000);
            }
            // First-claim cases pause both actual inserts; same-root cases pause before command admission.
            $this->assertSame(0, ChemicalCommandIntent::where('idempotency_key', $key)->count());
            $this->assertSame(0, ChemicalExposureCommandReceipt::where('idempotency_key', $key)->count());
            $this->assertSame(0, DB::table('chemical_command_receipts')->where('idempotency_key', $key)->count());
            touch($go); $results = [];
            foreach ($processes as $p) { $p->wait(); $this->assertTrue($p->isSuccessful(), $p->getErrorOutput()); $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($processes as $p) { if ($p->isRunning()) { $p->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
            if (dirname($bytes) !== sys_get_temp_dir() || ! str_starts_with($bytes, $prefix) || ! str_ends_with($bytes, '-bytes')) {
                throw new \RuntimeException('Private test byte cleanup escaped its exact generated root.');
            }
            if (is_dir($bytes)) { File::deleteDirectory($bytes); }
        }
    }
}
