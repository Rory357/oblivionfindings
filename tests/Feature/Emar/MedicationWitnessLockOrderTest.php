<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationWitnessLockOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_opposite_caller_order_locks_the_same_user_rows_in_canonical_order(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $first = User::factory()->create();
        $second = User::factory()->create();
        $expected = collect([$first->id, $second->id])->sort()->values()->all();
        $token = Str::uuid()->toString();
        $readyA = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-witness-ready-a-{$token}";
        $readyB = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-witness-ready-b-{$token}";
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-witness-go-{$token}";
        $processes = [];

        $connection->commit();

        try {
            $processes[] = $this->startWorker($readyA, $go, [$first->id, $second->id]);
            $processes[] = $this->startWorker($readyB, $go, [$second->id, $first->id]);
            $this->waitForFiles([$readyA, $readyB]);
            file_put_contents($go, 'go');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'The opposite-order witness lock worker failed.',
                );
                $this->assertSame(
                    $expected,
                    json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR),
                );
            }
        } finally {
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
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::table('users')->whereIn('id', [$first->id, $second->id])->delete();
            $connection->beginTransaction();
        }
    }

    public function test_opposite_main_and_witness_shift_pairs_share_one_canonical_shift_then_user_prefix(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $first = User::factory()->create(['approved_at' => now()]);
        $second = User::factory()->create(['approved_at' => now()]);
        $firstShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $first->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
        ]);
        $secondShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $second->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
        ]);
        $expectedShifts = collect([$firstShift->id, $secondShift->id])->sort()->values()->all();
        $expectedUsers = collect([$first->id, $second->id])->sort()->values()->all();
        $effectiveAt = now()->utc()->setMicrosecond(0)->toIso8601String();
        $token = Str::uuid()->toString();
        $readyA = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-shift-ready-a-{$token}";
        $readyB = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-shift-ready-b-{$token}";
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-shift-go-{$token}";
        $processes = [];

        $connection->commit();

        try {
            $processes[] = $this->startShiftWorker(
                $readyA,
                $go,
                [$first->id, $second->id],
                $site->id,
                $effectiveAt,
                $firstShift->id,
            );
            $processes[] = $this->startShiftWorker(
                $readyB,
                $go,
                [$second->id, $first->id],
                $site->id,
                $effectiveAt,
                $secondShift->id,
            );
            $this->waitForFiles([$readyA, $readyB]);
            file_put_contents($go, 'go');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'The opposite-pair Shift/User worker failed.',
                );
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expectedShifts, $result['shifts']);
                $this->assertSame($expectedUsers, $result['users']);
            }
        } finally {
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
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::table('shifts')->whereIn('id', [$firstShift->id, $secondShift->id])->delete();
            DB::table('users')->whereIn('id', [$first->id, $second->id])->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
        }
    }

    /** @param array<int, int> $userIds */
    private function startWorker(string $readyPath, string $goPath, array $userIds): Process
    {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$connection->beginTransaction();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the witness-lock race gate.');
    }
    usleep(10_000);
}
$locked = $app->make(App\Services\Medication\MedicationGovernanceScopeService::class)
    ->lockControlledWitnessUsers([(int) $argv[2], (int) $argv[3]]);
usleep(250_000);
$connection->commit();
echo json_encode($locked->keys()->values()->all(), JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $userIds[0],
                (string) $userIds[1],
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

    /** @param array<int, int> $userIds */
    private function startShiftWorker(
        string $readyPath,
        string $goPath,
        array $userIds,
        int $siteId,
        string $effectiveAt,
        int $mainShiftId,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$connection->beginTransaction();
file_put_contents($argv[7], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[8])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the Shift/User race gate.');
    }
    usleep(10_000);
}
$governance = $app->make(App\Services\Medication\MedicationGovernanceScopeService::class);
$shifts = $governance->lockControlledWitnessPresenceShifts(
    [(int) $argv[2], (int) $argv[3]],
    (int) $argv[4],
    Carbon\Carbon::parse($argv[5]),
    [(int) $argv[6]],
);
$users = $governance->lockControlledWitnessUsers([(int) $argv[2], (int) $argv[3]]);
usleep(250_000);
$connection->commit();
echo json_encode([
    'shifts' => $shifts->keys()->values()->all(),
    'users' => $users->keys()->values()->all(),
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $userIds[0],
                (string) $userIds[1],
                (string) $siteId,
                $effectiveAt,
                (string) $mainShiftId,
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
                throw new RuntimeException('The witness-lock concurrency workers did not become ready.');
            }
            usleep(10_000);
        }
    }
}
