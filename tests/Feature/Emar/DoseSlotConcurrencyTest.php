<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseSlot;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/**
 * P01 foundation C3: concurrent writers on one dose slot. Real MySQL sessions
 * (separate PHP processes) on the committed test database; the real clock.
 */
class DoseSlotConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private ClientMedication $order;

    private CarbonImmutable $slotUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => User::factory()->create(['role' => 'manager', 'approved_at' => now()])->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
        $this->client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        // The slot is this minute on the NZ clock, so a dose recorded now is on time.
        $nzMinute = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $this->slotUtc = $nzMinute->utc();
        // Entered an hour earlier: a dose due before an order's entry is not owed.
        Carbon::setTestNow(Carbon::instance($nzMinute->subHour())->utc());
        $this->order = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => [$nzMinute->format('H:i')],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        Carbon::setTestNow();
    }

    public function test_a_dose_recorded_while_the_generator_runs_waits_for_it_and_keeps_its_outcome(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $paths = $this->tempPaths(1);
        $process = null;

        // Publish the fixtures to the worker's session.
        $connection->commit();

        try {
            // The generator holds the order (the slots' mutex) and its slot rows.
            $connection->beginTransaction();
            app(DoseSlotGenerator::class)->generateAhead($this->order->fresh());

            $process = $this->startRecorder($paths['ready'][0], null, $connection->getDatabaseName());
            $this->waitForFile($paths['ready'][0], 'The recording worker did not start.');
            usleep(500_000);
            $this->assertTrue($process->isRunning(), 'The dose was recorded without waiting for the generator.');

            // Another reconcile while the recorder waits, then release.
            app(DoseSlotGenerator::class)->generateAhead($this->order->fresh());
            $connection->commit();

            $process->wait();
            $result = $this->workerResult($process);
            $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

            $administration = ClientMedicationAdministration::query()->where('client_medication_id', $this->order->id)->sole();
            $slot = $this->theSlot();
            $this->assertSame('given', $slot->outcome);
            $this->assertSame((int) $administration->id, (int) $slot->outcome_administration_id);
        } finally {
            $this->cleanUp($connection, [$process], $paths);
        }
    }

    public function test_a_correction_approval_racing_a_new_recording_takes_the_order_lock_before_its_own_row(): void
    {
        $original = $this->recordedOriginal();
        $correction = $this->pendingCorrection($original, 'refused');
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $paths = $this->tempPaths(2);
        $processes = [];

        $connection->commit();

        try {
            // Hold the order row so both writers queue on it.
            $connection->beginTransaction();
            ClientMedication::query()->whereKey($this->order->id)->lockForUpdate()->firstOrFail();

            $processes[0] = $this->startRecorder($paths['ready'][0], $paths['go'], $connection->getDatabaseName(), 'approve', $correction->id);
            $processes[1] = $this->startRecorder($paths['ready'][1], $paths['go'], $connection->getDatabaseName());
            foreach ([0, 1] as $i) {
                $this->waitForFile($paths['ready'][$i], 'A worker did not start.');
            }
            file_put_contents($paths['go'], 'go');
            usleep(800_000);
            $this->assertTrue($processes[0]->isRunning(), 'The approval did not wait for the order.');
            $this->assertTrue($processes[1]->isRunning(), 'The recording did not wait for the order.');

            // Neither has locked an administration row yet: the approval took
            // no lock on its own row before the order's (NOWAIT fails at once
            // if it had).
            $this->assertCount(2, ClientMedicationAdministration::query()
                ->whereKey([$original->id, $correction->id])
                ->lock('for update nowait')
                ->get());
            $connection->commit();

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($this->workerResult($process)['success']);
            }

            // Whichever ran first, the approved correction is the evidence.
            $slot = $this->theSlot();
            $this->assertSame('refused', $slot->outcome);
            $this->assertSame((int) $correction->id, (int) $slot->outcome_administration_id);
            $this->assertSame(2, ClientMedicationAdministration::query()->where('client_medication_id', $this->order->id)->count());
        } finally {
            $this->cleanUp($connection, $processes, $paths);
        }
    }

    public function test_deleting_a_record_racing_a_new_recording_on_its_slot_completes_both_without_deadlock(): void
    {
        $original = $this->recordedOriginal();
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $paths = $this->tempPaths(2);
        $processes = [];

        $connection->commit();

        try {
            $processes[0] = $this->startRecorder($paths['ready'][0], $paths['go'], $connection->getDatabaseName(), 'delete', $original->id);
            $processes[1] = $this->startRecorder($paths['ready'][1], $paths['go'], $connection->getDatabaseName());
            foreach ([0, 1] as $i) {
                $this->waitForFile($paths['ready'][$i], 'A worker did not start.');
            }
            file_put_contents($paths['go'], 'go');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($this->workerResult($process)['success']);
            }

            // Either order is valid; the slot always matches the evidence.
            $effective = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->where('client_medication_id', $this->order->id)
                ->get();
            $this->assertLessThanOrEqual(1, $effective->count());
            $slot = $this->theSlot();
            $this->assertSame($effective->first()?->id, $slot->outcome_administration_id === null ? null : (int) $slot->outcome_administration_id);
            $this->assertSame($effective->isEmpty() ? null : 'given', $slot->outcome);
        } finally {
            $this->cleanUp($connection, $processes, $paths);
        }
    }

    public function test_two_people_recording_the_same_dose_at_once_leave_one_record_and_one_outcome(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $paths = $this->tempPaths(2);
        $processes = [];

        $connection->commit();

        try {
            foreach ([0, 1] as $i) {
                $processes[$i] = $this->startRecorder($paths['ready'][$i], $paths['go'], $connection->getDatabaseName());
            }
            foreach ([0, 1] as $i) {
                $this->waitForFile($paths['ready'][$i], 'A recording worker did not start.');
            }
            file_put_contents($paths['go'], 'go');

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $results[] = $this->workerResult($process);
            }

            // Neither attempt failed with an error or a deadlock.
            $this->assertCount(2, $results);
            $effective = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->where('client_medication_id', $this->order->id)
                ->get();
            $this->assertCount(1, $effective);
            $slot = $this->theSlot();
            $this->assertSame('given', $slot->outcome);
            $this->assertSame((int) $effective->sole()->id, (int) $slot->outcome_administration_id);
            $this->assertSame(1, MedicationDoseSlot::query()
                ->where('client_medication_id', $this->order->id)
                ->where('due_at', $this->slotUtc->format('Y-m-d H:i:s'))
                ->count());
        } finally {
            $this->cleanUp($connection, $processes, $paths);
        }
    }

    private function theSlot(): MedicationDoseSlot
    {
        return MedicationDoseSlot::query()
            ->where('client_medication_id', $this->order->id)
            ->where('due_at', $this->slotUtc->format('Y-m-d H:i:s'))
            ->sole();
    }

    /**
     * @return array{ready: list<string>, go: string}
     */
    private function tempPaths(int $workers): array
    {
        $token = Str::uuid()->toString();
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR."dose-slot-{$token}";

        return [
            'ready' => array_map(fn (int $i): string => "{$base}-ready-{$i}", range(0, $workers - 1)),
            'go' => "{$base}-go",
        ];
    }

    /** A given dose already recorded on the slot (committed with the fixtures). */
    private function recordedOriginal(): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->order->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $this->slotUtc,
            'administered_at' => $this->slotUtc,
            'status' => 'given',
        ]);
    }

    /** A correction raised as MedicationAdministrationCorrectionController::store raises it. */
    private function pendingCorrection(ClientMedicationAdministration $original, string $status): ClientMedicationAdministration
    {
        $correction = $original->replicate(['id', 'client_request_uuid', 'deleted_at', 'created_at', 'updated_at']);
        $correction->forceFill([
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'status' => $status,
            'reason' => 'Declined',
            'correction_requested_by' => $this->worker->id,
            'correction_status' => 'pending',
        ])->save();

        return $correction;
    }

    /**
     * A worker process: "record" records the slot's dose through the shared
     * recording service; "approve" approves a correction and "delete" deletes
     * a record, each as a plain model write in its own transaction (no order
     * lock taken by the caller).
     */
    private function startRecorder(string $readyPath, ?string $goPath, string $database, string $action = 'record', ?int $targetId = null): Process
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
$client = App\Models\Client::query()->findOrFail((int) $argv[2]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[5], 'ready');
if ($argv[7] !== '') {
    $deadline = microtime(true) + 15;
    while (! is_file($argv[7]) && microtime(true) < $deadline) {
        usleep(5_000);
    }
}
if ($argv[8] === 'approve') {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
        App\Models\ClientMedicationAdministration::query()->findOrFail((int) $argv[9])->update([
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
            'correction_approved_by' => (int) $argv[4],
        ]);
    });
    $result = ['success' => true];
} elseif ($argv[8] === 'delete') {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
        App\Models\ClientMedicationAdministration::query()->findOrFail((int) $argv[9])->delete();
    });
    $result = ['success' => true];
} else {
    $result = $app->make(App\Services\EnhancedMarService::class)->recordAdministration(
        $client,
        $medication,
        [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'scheduled_for' => $argv[6],
            'administered_at' => $argv[6],
        ],
        (int) $argv[4],
    );
}
echo json_encode([
    'success' => (bool) ($result['success'] ?? false),
    'duplicate' => (bool) ($result['duplicate'] ?? false),
    'error' => $result['error'] ?? null,
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $this->client->id,
                (string) $this->order->id,
                (string) $this->worker->id,
                $readyPath,
                $this->slotUtc->toIso8601String(),
                $goPath ?? '',
                $action,
                (string) ($targetId ?? 0),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    /**
     * @return array{success: bool, duplicate: bool, error: string|null}
     */
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
        $deadline = microtime(true) + 30;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }

            usleep(10_000);
        }
    }

    /**
     * @param  list<Process|null>  $processes
     * @param  array{ready: list<string>, go: string}  $paths
     */
    private function cleanUp($connection, array $processes, array $paths): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process?->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$paths['ready'], $paths['go']] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $connection->beginTransaction();
    }
}
