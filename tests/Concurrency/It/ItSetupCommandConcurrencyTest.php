<?php

namespace Tests\Concurrency\It;

use App\Domain\It\Services\ItSetupCommandService;
use App\Models\AuditLog;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItSetupCommandReceipt;
use App\Models\ItTeam;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone with run-isolated-it-tests.ps1: committed fixtures belong to this disposable schema. */
final class ItSetupCommandConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for standalone Setup concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_serialize_identical_setup_creates_and_explicit_cancellation(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $actor->roles()->sync(Role::where('name', 'admin')->pluck('id'));
        foreach (['teams' => ItTeam::class, 'queues' => ItQueue::class, 'services' => ItService::class,
            'provisioning-templates' => ItProvisioningTemplate::class] as $resource => $model) {
            $uuid = (string) Str::uuid();
            $before = $model::count();
            $action = $resource === 'provisioning-templates' ? 'it.provisioning.template.created'
                : 'it.setup.'.['teams' => 'team', 'queues' => 'queue', 'services' => 'service'][$resource].'.created';
            $audits = AuditLog::where('action', $action)->count();
            $race = $this->race($actor, $resource, $uuid, ['create', 'create']);
            $this->assertSame(['committed', 'committed'], array_column($race, 'status'));
            $this->assertSame($race[0]['data']['id'], $race[1]['data']['id']);
            $replays = array_column(array_column($race, 'data'), 'replayed');
            sort($replays);
            $this->assertSame([false, true], $replays);
            $this->assertSame($before + 1, $model::count());
            $this->assertSame($audits + 1, AuditLog::where('action', $action)->count());
            $this->assertSame(1, ItSetupCommandReceipt::where('request_uuid', $uuid)->count());
            if ($resource === 'provisioning-templates') {
                $this->assertSame(1, ItProvisioningTemplateVersion::where('provisioning_template_id', $race[0]['data']['id'])->count());
            }

            $uuid = (string) Str::uuid();
            $before = $model::count();
            $audits = AuditLog::where('action', $action)->count();
            $cancelAudits = AuditLog::where('action', 'it.setup.create.cancelled')->count();
            $versions = ItProvisioningTemplateVersion::count();
            $race = $this->race($actor, $resource, $uuid, ['create', 'cancel']);
            $this->assertSame($race[0]['status'], $race[1]['status']);
            $this->assertContains($race[0]['status'], ['committed', 'cancelled']);
            $committed = $race[0]['status'] === 'committed';
            if ($committed) {
                $this->assertSame($race[0]['data']['id'], $race[1]['data']['id']);
            }
            $this->assertSame($before + (int) $committed, $model::count());
            $this->assertSame($audits + (int) $committed, AuditLog::where('action', $action)->count());
            $this->assertSame($cancelAudits + (int) ! $committed, AuditLog::where('action', 'it.setup.create.cancelled')->count());
            $this->assertSame(1, ItSetupCommandReceipt::where('request_uuid', $uuid)->count());
            $this->assertSame($versions + (int) ($committed && $resource === 'provisioning-templates'), ItProvisioningTemplateVersion::count());
            $recovered = app(ItSetupCommandService::class)->recover($actor, $resource, $uuid, (int) $actor->id);
            $this->assertSame($race[0]['status'], $recovered['status']);
        }
    }

    private function race(User $actor, string $resource, string $uuid, array $operations): array
    {
        $barrier = storage_path('framework/testing/it-setup-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/setup-command-concurrency-worker.php'),
                    $operation, (string) $actor->id, $resource, $uuid, $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->barriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->barriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both workers reached the command while its actor remained locked.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
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

    private function barriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Setup worker stopped before its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Setup worker barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
