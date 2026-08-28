<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ShiftTimelineService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HandoverFirstSaveConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_mysql_first_saves_converge_to_one_handover_cd_verification_and_timeline(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $presenceClient = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $actor = $this->makeUser('admin', [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $witness = $this->makeUser('support_worker', ['medications.controlled.witness']);
        foreach ([$actor, $witness] as $staff) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staff->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => null,
            ]);
        }
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $actor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth(),
            'can_witness_controlled' => true,
        ]);
        $outgoingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->addMinutes(10),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $actor->id,
            'created_by' => $actor->id,
        ]);
        Shift::factory()->create([
            'client_id' => $presenceClient->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
            'started_by' => $witness->id,
            'created_by' => $witness->id,
        ]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $token = Str::uuid()->toString();
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-ready-b-{$token}",
        ];
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-go-{$token}";
        $processes = [];
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            foreach ($readyPaths as $readyPath) {
                $processes[] = $this->startWorker(
                    $readyPath,
                    $goPath,
                    $outgoingShift,
                    $actor,
                    $witness,
                    $connection->getDatabaseName(),
                );
            }
            $this->waitForFiles($readyPaths);
            touch($goPath);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    'Concurrent handover first-save worker did not wait for the canonical Client lock.',
                );
            }
            $connection->commit();

            $ids = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'Concurrent handover first-save worker failed.',
                );
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $ids[] = (int) $result['id'];
            }

            $this->assertCount(1, array_unique($ids));
            $handover = ShiftHandover::query()->where('outgoing_shift_id', $outgoingShift->id)->sole();
            $this->assertSame(1, (int) $handover->version);
            $this->assertSame('verified', data_get($handover->cd_verification, 'result'));
            $this->assertSame($witness->id, (int) data_get($handover->cd_verification, 'witness_id'));
            $this->assertSame(1, DB::table('audit_logs')
                ->where('action', 'shift.handover.cdVerification.created')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->where('action', 'shift.handover.created')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count());
            $this->assertSame(0, DB::table('audit_logs')
                ->where('action', 'shift.handover.updated')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count());
            $this->assertSame(0, DB::table('audit_logs')
                ->where('action', 'shift.handover.cdVerification.replaced')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count());
            $this->assertSame(1, TimelineEvent::query()
                ->where('type', ShiftTimelineService::HANDOVER_CREATED_EVENT_TYPE)
                ->where('source_type', ShiftHandover::class)
                ->where('source_id', $handover->id)
                ->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $goPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            DB::table('timeline_events')->whereIn('shift_id', [$outgoingShift->id])->delete();
            DB::table('audit_logs')->where('client_id', $client->id)->delete();
            DB::table('shift_handovers')->where('outgoing_shift_id', $outgoingShift->id)->delete();
            DB::table('client_medications')->where('id', $medication->id)->delete();
            DB::table('medication_competency_assessments')->where('user_id', $witness->id)->delete();
            DB::table('shifts')->whereIn('client_id', [$client->id, $presenceClient->id])->delete();
            DB::table('clients')->whereIn('id', [$client->id, $presenceClient->id])->delete();
            DB::table('hr_employee_profiles')->whereIn('user_id', [$actor->id, $witness->id])->delete();
            DB::table('permission_user')->whereIn('user_id', [$actor->id, $witness->id])->delete();
            DB::table('role_user')->whereIn('user_id', [$actor->id, $witness->id])->delete();
            DB::table('users')->whereIn('id', [$actor->id, $witness->id])->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
        }
    }

    private function startWorker(
        string $readyPath,
        string $goPath,
        Shift $outgoingShift,
        User $actor,
        User $witness,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$shift = App\Models\Shift::query()->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$witness = App\Models\User::query()->findOrFail((int) $argv[4]);
Illuminate\Support\Facades\Auth::setUser($actor);
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the handover first-save barrier.');
    }
    usleep(10_000);
}
$result = $app->make(App\Services\ShiftHandoverService::class)->save($shift, $actor, [
    'handover_notes' => 'Concurrent controlled-drug handover.',
    'cd_verification_input' => [
        'result' => 'verified',
        'witness_id' => $witness->id,
        'witness_credential' => 'password',
        'notes' => 'Concurrent count matched.',
    ],
    'submit' => false,
]);
echo json_encode(['id' => (int) $result['handover']->id], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $outgoingShift->id,
                (string) $actor->id,
                (string) $witness->id,
                $readyPath,
                $goPath,
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
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrent handover first-save workers did not become ready.');
            }
            usleep(10_000);
        }
    }

    /** @param array<int, string> $permissionKeys */
    private function makeUser(string $roleName, array $permissionKeys): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $overrides = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->syncWithoutDetaching($overrides);

        return $user;
    }
}
