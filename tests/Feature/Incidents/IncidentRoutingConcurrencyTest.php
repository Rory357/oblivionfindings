<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentOwnerChange;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Services\Incidents\IncidentRoutingService;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Real physical two-worker declarations; execution requires sole runner admission. */
final class IncidentRoutingConcurrencyTest extends IncidentRoutingTestCase
{
    protected bool $physicalCommits = true;

    public function test_two_physical_consumers_converge_to_one_assignment_and_decision(): void
    {
        $this->settings(); $root = $this->incident(); $id = app(IncidentRoutingService::class)->stageForSubmitted($root->id, $this->reporter);
        $job = ['operation' => 'consume', 'actor' => 0, 'source' => $id, 'payload' => []]; $results = $this->race([$job, $job]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status')); $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(1, IncidentOwnerChange::where('incident_id', $root->id)->count()); $this->assertSame(1, IncidentRoutingDecision::where('intent_id', $id)->count());
    }

    public function test_manual_assignment_and_configured_routing_cannot_overwrite_each_other(): void
    {
        $this->settings(); $root = $this->incident(); $id = app(IncidentRoutingService::class)->stageForSubmitted($root->id, $this->reporter);
        $results = $this->race([
            ['operation' => 'consume', 'actor' => 0, 'source' => $id, 'payload' => []],
            ['operation' => 'manual', 'actor' => $this->manager->id, 'source' => $root->id, 'payload' => ['owner_user_id' => $this->cover->id, 'expected_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => null]],
        ]);
        $this->assertContains('accepted', array_column($results, 'status')); $this->assertSame(1, IncidentOwnerChange::where('incident_id', $root->id)->count());
        $this->assertSame(1, $root->fresh()->owner_revision); $this->assertContains($root->fresh()->incident_owner_user_id, [$this->lead->id, $this->cover->id]);
    }

    public function test_simultaneous_settings_changes_preserve_one_current_revision_and_stale_payload(): void
    {
        $second = $this->staff([IncidentRoutingSettings::PERMISSION]);
        $a = ['configuration' => $this->config(), 'expected_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'First explicit route'];
        $b = $a; $b['configuration']['routes'][0]['lead_user_id'] = $this->cover->id; $b['request_uuid'] = (string) Str::uuid(); $b['reason'] = 'Other explicit route';
        $results = $this->race([['operation' => 'settings', 'actor' => $this->manager->id, 'source' => 0, 'payload' => $a], ['operation' => 'settings', 'actor' => $second->id, 'source' => 0, 'payload' => $b]]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_revision'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, \App\Models\HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_simultaneous_exact_settings_uuid_retains_one_receipt_and_audit(): void
    {
        $input = ['configuration' => $this->config(), 'expected_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'One original explicit route'];
        $job = ['operation' => 'settings', 'actor' => $this->manager->id, 'source' => 0, 'payload' => $input]; $results = $this->race([$job, $job]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status')); $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(1, \App\Models\HealthSafetySettingChange::where('command_key', $input['request_uuid'])->count());
        $this->assertSame(1, \App\Models\AuditLog::where('meta->request_uuid', $input['request_uuid'])->count());
    }

    public function test_intent_cursor_advances_past_failed_batch_and_wrap_retries_original_intent(): void
    {
        $this->settings(); $service = app(IncidentRoutingService::class); $blocked = $this->incident(); $healthy = $this->incident();
        $firstId = $service->stageForSubmitted($blocked->id, $this->reporter); $secondId = $service->stageForSubmitted($healthy->id, $this->reporter);
        $dispatcher = \Illuminate\Database\Eloquent\Model::getEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(clone $dispatcher);
        IncidentRoutingDecision::saving(fn ($row) => (int) $row->intent_id !== $firstId);
        try {
            $first = $service->sweep(1);
            $this->assertSame(1, $first['failed']); $this->assertSame($firstId, $first['next_intent_cursor']);
            $this->assertSame('error', IncidentRoutingIntent::findOrFail($firstId)->state);
            $next = $service->sweep(1, $first['next_incident_cursor'] ?? 0, $first['next_intent_cursor']);
            $this->assertSame(0, $next['failed']); $this->assertSame($secondId, $next['next_intent_cursor']);
            $this->assertSame($this->lead->id, $healthy->fresh()->incident_owner_user_id); $this->assertNull($blocked->fresh()->incident_owner_user_id);
            $end = $service->sweep(1, $next['next_incident_cursor'] ?? 0, $next['next_intent_cursor']);
            $this->assertNull($end['next_intent_cursor']);
        } finally { \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher); }
        $wrapped = $service->sweep(1, 0, 0);
        $this->assertSame(0, $wrapped['failed']); $this->assertSame($firstId, $wrapped['next_intent_cursor']);
        $this->assertSame($this->lead->id, $blocked->fresh()->incident_owner_user_id);
        $this->assertSame(2, IncidentRoutingDecision::count()); $this->assertSame(2, IncidentOwnerChange::count());
    }

    private function race(array $jobs): array
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql' || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Routing races require the admitted physical testing schema.');
        }
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-incident-routing-race-'.Str::uuid(); $go = $prefix.'-go'; $ready = []; $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $ready[] = $prefix.'-ready-'.$index; $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/IncidentRoutingCommandWorker.php'), $connection->getDatabaseName(),
                    $job['operation'], (string) $job['actor'], (string) $job['source'], base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $ready[$index], $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(), 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(60); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($jobs)) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new \RuntimeException('Routing worker failed before barrier: '.$process->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Routing worker barrier timed out.'); } usleep(10000);
            }
            touch($go); $results = [];
            foreach ($processes as $process) { $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()); $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }
}
