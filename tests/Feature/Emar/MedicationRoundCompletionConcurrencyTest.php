<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationRoundCompletionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_completion_waits_for_a_concurrent_verification_and_sees_the_new_canonical_item(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $workerToday = Carbon::today(config('app.worker_timezone', 'Pacific/Auckland'));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'is_active' => true,
        ]);
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'medications.orders.manage'],
            [
                'description' => 'Create/update medication orders',
                'group' => 'medications',
                'module' => 'Clinical',
            ],
        );
        $worker->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $profile = HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => $workerToday->copy()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $worker->id,
            'created_by' => $worker->id,
            'status' => 'in_progress',
        ]);
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'created_by' => $worker->id,
            'name' => 'Verification race dose',
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => ['10:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'pending_verification',
            'start_date' => $workerToday->copy()->subMonth(),
        ]);
        $round = MedicationRound::query()->create([
            'service_context_id' => $context->id,
            'site_id' => $site->id,
            'name' => 'Verification race round',
            'round_type' => 'scheduled',
            'scheduled_time' => '10:00',
            'window_minutes' => 60,
            'round_date' => $workerToday,
            'status' => 'in_progress',
            'assigned_to' => $worker->id,
            'started_by' => $worker->id,
            'started_at' => now()->subHour(),
        ]);
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'med-round-complete-ready-'.Str::uuid();
        $process = null;

        // Publish the fixture, then retain the canonical Client/medication locks
        // around the verification transition on this first connection.
        $connection->commit();

        try {
            $connection->beginTransaction();
            app(MedicationScopeDecisionService::class)->forMedication(
                $worker,
                $medication,
                now(),
                function (MedicationScopeDecision $scope) use ($worker): void {
                    $scope->medication->forceFill([
                        'approval_status' => 'verified',
                        'verified_by' => $worker->id,
                        'verified_at' => now(),
                    ])->save();
                },
            );

            $process = $this->startCompletionWorker($readyPath, $worker, $round);
            $this->waitForFile($readyPath);
            usleep(250_000);
            $this->assertTrue(
                $process->isRunning(),
                'Round completion did not wait for the canonical medication membership lock.',
            );

            $connection->commit();
            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The round-completion worker failed.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

            $this->assertFalse($result['can_complete']);
            $this->assertSame('in_progress', $result['status']);
            $this->assertSame('in_progress', MedicationRound::query()->findOrFail($round->id)->status);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if (is_file($readyPath)) {
                unlink($readyPath);
            }

            foreach ([$site, $context, $profile, $client, $shift, $medication, $round] as $auditable) {
                DB::table('audit_logs')
                    ->where('auditable_type', $auditable->getMorphClass())
                    ->where('auditable_id', $auditable->id)
                    ->delete();
            }
            DB::table('medication_rounds')->where('id', $round->id)->delete();
            DB::table('client_medications')->where('id', $medication->id)->delete();
            DB::table('shifts')->where('id', $shift->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('hr_employee_profile_versions')->where('employee_profile_id', $profile->id)->delete();
            DB::table('hr_employee_profiles')->where('id', $profile->id)->delete();
            DB::table('permission_user')->where('user_id', $worker->id)->delete();
            DB::table('users')->where('id', $worker->id)->delete();
            if ($permission->wasRecentlyCreated) {
                DB::table('permissions')->where('id', $permission->id)->delete();
            }
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    private function startCompletionWorker(
        string $readyPath,
        User $worker,
        MedicationRound $round,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[5], config('app.worker_timezone', 'Pacific/Auckland')));
file_put_contents($argv[4], 'ready');
$performer = App\Models\User::query()->findOrFail((int) $argv[2]);
$round = App\Models\MedicationRound::query()->findOrFail((int) $argv[3]);
$result = $app->make(App\Services\Medication\MedicationScopeDecisionService::class)->forRound(
    $performer,
    $round,
    now(),
    function (App\Services\Medication\MedicationScopeDecision $scope) use ($app): array {
        $canComplete = $app->make(App\Services\GuidedRoundService::class)
            ->canCompleteCanonicalRoundUnderLock($scope->round);
        if ($canComplete) {
            $scope->round->update(['status' => 'completed']);
        }

        return [
            'can_complete' => $canComplete,
            'status' => $scope->round->fresh()->status,
        ];
    },
    ['in_progress', 'completed'],
    requireAssignment: false,
    requireWorkScope: false,
    lockCanonicalMembership: true,
);
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $worker->id,
                (string) $round->id,
                $readyPath,
                now()->toIso8601String(),
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

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The round-completion concurrency worker did not become ready.');
            }

            usleep(10_000);
        }
    }
}
