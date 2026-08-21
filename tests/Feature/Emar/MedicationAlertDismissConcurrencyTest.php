<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationAlertDismissConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_separate_acknowledgement_workers_serialize_and_replay_the_terminal_result(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $alert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => 'Concurrent acknowledgement fixture',
            'status' => 'active',
        ]);
        $token = Str::uuid()->toString();
        $readyA = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-alert-ready-a-{$token}";
        $readyB = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-alert-ready-b-{$token}";
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-alert-go-{$token}";
        $processes = [];

        // Publish fixtures so both independent MySQL connections can load the
        // same active snapshot before contending on the canonical Site lock.
        $connection->commit();

        try {
            $processes[] = $this->startWorker($readyA, $go, $alert, $actor, $site);
            $processes[] = $this->startWorker($readyB, $go, $alert, $actor, $site);
            $this->waitForFiles([$readyA, $readyB]);

            $connection->beginTransaction();
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            file_put_contents($go, 'go');
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Concurrent alert acknowledgement did not wait for the canonical Site lock.');
            }
            $connection->commit();

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'The alert acknowledgement concurrency worker failed.',
                );
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['success']);
                $this->assertSame('acknowledged', $result['status']);
                $this->assertSame($actor->id, $result['acknowledged_by']);
            }

            $this->assertSame('acknowledged', $alert->fresh()->status);
            $this->assertSame($actor->id, (int) $alert->fresh()->acknowledged_by);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$readyA, $readyB, $go] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            DB::table('audit_logs')->where('client_id', $client->id)->delete();
            DB::table('medication_dashboard_alerts')->where('id', $alert->id)->delete();
            DB::table('client_medications')->where('id', $medication->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
            $connection->beginTransaction();
        }
    }

    private function startWorker(
        string $readyPath,
        string $goPath,
        MedicationDashboardAlert $alert,
        User $actor,
        Site $site,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$alert = App\Models\MedicationDashboardAlert::query()
    ->with('client:id,site_id')
    ->findOrFail((int) $argv[2]);
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the alert acknowledgement race gate.');
    }
    usleep(10_000);
}
$success = $app->make(App\Services\MedicationAlertService::class)->acknowledgeAlert(
    $alert,
    (int) $argv[3],
    [(int) $argv[4]],
);
$fresh = $alert->fresh();
echo json_encode([
    'success' => $success,
    'status' => $fresh->status,
    'acknowledged_by' => (int) $fresh->acknowledged_by,
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $alert->id,
                (string) $actor->id,
                (string) $site->id,
                $readyPath,
                $goPath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The alert acknowledgement concurrency workers did not become ready.');
            }
            usleep(10_000);
        }
    }
}
