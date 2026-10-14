<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/**
 * eMAR audit Lane A6 (EA-056): two staff record the same person's as-needed
 * dose at the same moment. The second recorder must re-read the dose history
 * after it takes the person's lock, so the minimum interval and the daily
 * limit see the first dose. Real MySQL sessions in separate PHP processes on
 * the committed test database, on the real clock.
 */
class LaneAPrnConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private ClientMedication $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $permissions = Permission::query()->whereIn('key', ['medications.view', 'medications.administer.record'])->get();
        $this->worker->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id, 'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'restricted' => false, 'not_seen_areas' => [],
        ]);
        Shift::factory()->create([
            'user_id' => $this->worker->id, 'client_id' => $this->client->id, 'site_id' => $site->id,
            'service_context_id' => $context->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(6),
            'actual_starts_at' => now()->subHours(2)->addMinutes(5), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
        $this->order = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Synthetic paracetamol 500mg', 'dosage' => '1 tablet',
            'dose_amount' => 1, 'dose_unit' => 'tablet', 'frequency' => 'As needed', 'dose_times' => [],
            'is_prn' => true, 'prn_reason' => 'Pain', 'min_hours_between_doses' => 4, 'max_per_day' => 4,
            'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
    }

    public function test_a_second_as_needed_dose_racing_the_first_sees_it_after_taking_the_persons_lock(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $paths = $this->tempPaths();
        $process = null;

        // Publish the fixtures to the second recorder's session.
        $connection->commit();

        try {
            // The first recorder holds the person while it records its dose.
            $connection->beginTransaction();
            Client::query()->whereKey($this->client->id)->lockForUpdate()->firstOrFail();

            // The second recorder starts, reads, then waits for the person.
            $process = $this->startRecorder($paths['ready'], $connection->getDatabaseName());
            $this->waitForFile($paths['ready'], 'The second recorder did not start.');
            usleep(1_200_000);
            $this->assertTrue($process->isRunning(), 'The second dose did not wait for the person.');

            // The first dose is saved and the person released.
            ClientMedicationAdministration::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $this->order->id,
                'service_context_id' => $this->client->service_context_id, 'administered_by' => $this->worker->id,
                'administered_at' => now()->subMinute(), 'status' => 'given', 'dose_given' => '1 tablet', 'reason' => 'Pain',
            ]);
            $connection->commit();

            $process->wait();
            $result = $this->workerResult($process);

            $this->assertFalse($result['success'], 'The second as-needed dose passed the interval check.');
            $this->assertStringContainsString('INTERVAL NOT ELAPSED', (string) $result['error']);
            $this->assertSame(1, ClientMedicationAdministration::query()
                ->where('client_medication_id', $this->order->id)->where('status', 'given')->count());
        } finally {
            $this->cleanUp($connection, $process, $paths);
        }
    }

    /** @return array{ready: string} */
    private function tempPaths(): array
    {
        return ['ready' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'prn-race-'.Str::uuid()->toString().'-ready'];
    }

    /** The real recording path: the scope decision and the shared recording service. */
    private function startRecorder(string $readyPath, string $database): Process
    {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($argv): void {
    foreach (['App\\' => 'app', 'Database\\' => 'database'] as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $argv[1].'/'.$directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
}, true, true);
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::query()->findOrFail((int) $argv[4]);
Illuminate\Support\Facades\Auth::setUser($user);
$client = App\Models\Client::query()->findOrFail((int) $argv[2]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[5], 'ready');
try {
    $result = $app->make(App\Services\Medication\MedicationScopeDecisionService::class)->forAdministration(
        $user, $client, $medication, now(), null, null, null,
        function (App\Services\Medication\MedicationScopeDecision $scope) use ($app, $user): array {
            return $app->make(App\Services\EnhancedMarService::class)->recordAdministration(
                $scope->client,
                $scope->medication,
                // No submitted time: the dose is at the locked presence time, as
                // the Meds today controller records a "now" dose.
                ['status' => 'given', 'reason' => 'Pain', 'dose_given' => '1 tablet', 'scope_authorized' => true],
                (int) $user->id,
                $scope->shiftId(),
                false,
                prelockedPresenceShifts: $scope->lockedPresenceShifts,
                prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
            );
        },
    );
} catch (Throwable $error) {
    $result = ['success' => false, 'error' => get_class($error).': '.$error->getMessage()];
}
echo json_encode([
    'success' => (bool) ($result['success'] ?? false),
    'error' => $result['error'] ?? null,
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [PHP_BINARY, '-r', $worker, base_path(), (string) $this->client->id, (string) $this->order->id,
                (string) $this->worker->id, $readyPath],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'],
        );
        $process->setTimeout(90);
        $process->start();

        return $process;
    }

    /** @return array{success: bool, error: string|null} */
    private function workerResult(Process $process): array
    {
        $this->assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'The recording worker failed.',
        );

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForFile(string $path, string $message): void
    {
        $deadline = microtime(true) + 60;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }
            usleep(10_000);
        }
    }

    /** @param  array{ready: string}  $paths */
    private function cleanUp($connection, ?Process $process, array $paths): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($process?->isRunning()) {
            $process->stop(1);
        }
        if (is_file($paths['ready'])) {
            unlink($paths['ready']);
        }
        $connection->beginTransaction();
    }
}
