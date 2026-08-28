<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\Permission;
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

        $fixture = $this->alertFixture('Concurrent acknowledgement fixture');
        [$site, $client, $medication, $actor, $alert] = [
            $fixture['site'],
            $fixture['client'],
            $fixture['medication'],
            $fixture['actor'],
            $fixture['alert'],
        ];
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

            $this->deleteAlertFixture($fixture);
            $connection->beginTransaction();
        }
    }

    public function test_permission_revoked_while_alert_transition_waits_denies_without_writing(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $fixture = $this->alertFixture('Revoked acknowledgement fixture');
        /** @var User $actor */
        $actor = $fixture['actor'];
        /** @var MedicationDashboardAlert $alert */
        $alert = $fixture['alert'];
        /** @var Site $site */
        $site = $fixture['site'];
        $token = Str::uuid()->toString();
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-alert-ready-revoke-{$token}";
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-alert-go-revoke-{$token}";
        $process = null;

        $connection->commit();
        try {
            $connection->beginTransaction();
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $process = $this->startWorker($ready, $go, $alert, $actor, $site);
            $this->waitForFiles([$ready]);
            file_put_contents($go, 'go');
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Alert transition did not wait for the current actor mutex.');

            $actor->permissionOverrides()->updateExistingPivot(
                $fixture['permission_ids']['medications.administer.correct'],
                ['allowed' => false],
            );
            $connection->commit();

            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The alert revocation worker failed unexpectedly.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['success']);
            $this->assertSame(403, $result['status_code']);
            $this->assertSame('active', $alert->fresh()->status);
            $this->assertNull($alert->fresh()->acknowledged_by);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            foreach ([$ready, $go] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->deleteAlertFixture($fixture);
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
    ->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the alert acknowledgement race gate.');
    }
    usleep(10_000);
}
$statusCode = 200;
try {
    $success = $app->make(App\Services\MedicationAlertService::class)->acknowledgeAlert($alert, $actor);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    $success = false;
    $statusCode = $exception->getStatusCode();
}
$fresh = $alert->fresh();
echo json_encode([
    'success' => $success,
    'status_code' => $statusCode,
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

    /**
     * @return array{site: Site, client: Client, medication: ClientMedication, actor: User, alert: MedicationDashboardAlert, permission_ids: array<string, int>}
     */
    private function alertFixture(string $message): array
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'controlled_drug' => false,
        ]);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);
        $permissionIds = collect([
            'medications.view',
            'medications.administer.correct',
            'medications.controlled.view',
            'medications.controlled.record',
        ])->mapWithKeys(function (string $key): array {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'Medications', 'module' => 'medications'],
            );

            return [$key => (int) $permission->id];
        })->all();
        $actor->permissionOverrides()->sync(
            collect($permissionIds)->mapWithKeys(
                fn (int $permissionId): array => [$permissionId => ['allowed' => true]],
            )->all(),
        );
        $alert = MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => $message,
            'status' => 'active',
        ]);

        return compact('site', 'client', 'medication', 'actor', 'alert') + [
            'permission_ids' => $permissionIds,
        ];
    }

    /** @param array{site: Site, client: Client, medication: ClientMedication, actor: User, alert: MedicationDashboardAlert, permission_ids: array<string, int>} $fixture */
    private function deleteAlertFixture(array $fixture): void
    {
        DB::table('audit_logs')->where('client_id', $fixture['client']->id)->delete();
        DB::table('medication_dashboard_alerts')->where('id', $fixture['alert']->id)->delete();
        DB::table('client_medications')->where('id', $fixture['medication']->id)->delete();
        DB::table('clients')->where('id', $fixture['client']->id)->delete();
        DB::table('hr_employee_profiles')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('permission_user')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('users')->where('id', $fixture['actor']->id)->delete();
        DB::table('permissions')->whereIn('id', array_values($fixture['permission_ids']))->delete();
        DB::table('sites')->where('id', $fixture['site']->id)->delete();
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
