<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationRoundGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationRoundGenerationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_mysql_workers_converge_on_one_template_date_round(): void
    {
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
        $assignee = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $profile = HrEmployeeProfile::factory()->create([
            'user_id' => $assignee->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $assignee->id,
            'updated_by' => $assignee->id,
        ]);
        $template = MedicationRoundTemplate::query()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'name' => 'Concurrent medication round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'days_of_week' => null,
            'active' => true,
            'default_assigned_to' => $assignee->id,
        ]);
        $date = today()->addDay()->toDateString();
        $token = Str::uuid()->toString();
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-round-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-round-ready-b-{$token}",
        ];
        $processes = [];

        // Publish fixtures, then hold the first canonical Context lock so both
        // independent connections visibly queue before acquiring People/Site
        // evidence or serializing template materialization.
        $connection->commit();

        try {
            $connection->beginTransaction();
            ServiceContext::query()->whereKey($context->id)->lockForUpdate()->firstOrFail();
            foreach ($readyPaths as $readyPath) {
                $processes[] = $this->startWorker($readyPath, $template, $site, $date);
            }
            $this->waitForFiles($readyPaths);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    'Concurrent round generation did not wait for the canonical ServiceContext lock.',
                );
            }
            $connection->commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A round-generation worker failed.',
                );
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = collect($results)->pluck('status')->sort()->values()->all();
            $roundIds = collect($results)->pluck('round_id')->unique()->values()->all();
            $this->assertSame([
                MedicationRoundGenerationService::STATUS_ALREADY_EXISTS,
                MedicationRoundGenerationService::STATUS_CREATED,
            ], $statuses);
            $this->assertCount(1, $roundIds);
            $this->assertSame(1, MedicationRound::query()
                ->where('round_template_id', $template->id)
                ->where('round_date', $date)
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
            foreach ($readyPaths as $readyPath) {
                if (is_file($readyPath)) {
                    unlink($readyPath);
                }
            }

            $roundIds = DB::table('medication_rounds')
                ->where('round_template_id', $template->id)
                ->pluck('id');
            DB::table('audit_logs')
                ->where('auditable_type', MedicationRound::class)
                ->whereIn('auditable_id', $roundIds)
                ->delete();
            foreach ([
                [$site->getMorphClass(), $site->id],
                [$context->getMorphClass(), $context->id],
                [$profile->getMorphClass(), $profile->id],
            ] as [$auditableType, $auditableId]) {
                DB::table('audit_logs')
                    ->where('auditable_type', $auditableType)
                    ->where('auditable_id', $auditableId)
                    ->delete();
            }
            DB::table('medication_rounds')->whereIn('id', $roundIds)->delete();
            DB::table('medication_round_templates')->where('id', $template->id)->delete();
            DB::table('hr_employee_profile_versions')->where('employee_profile_id', $profile->id)->delete();
            DB::table('hr_employee_profiles')->where('id', $profile->id)->delete();
            DB::table('users')->where('id', $assignee->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
        }
    }

    private function startWorker(
        string $readyPath,
        MedicationRoundTemplate $template,
        Site $site,
        string $date,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[5], 'ready');
$result = $app->make(App\Services\Medication\MedicationRoundGenerationService::class)->generate(
    (int) $argv[2],
    $argv[3],
    false,
    [(int) $argv[4]],
);
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $template->id,
                $date,
                (string) $site->id,
                $readyPath,
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
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The round-generation concurrency workers did not become ready.');
            }

            usleep(10_000);
        }
    }
}
