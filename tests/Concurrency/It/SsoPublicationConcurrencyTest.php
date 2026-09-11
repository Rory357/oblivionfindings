<?php

namespace Tests\Concurrency\It;

use App\Models\AppSetting;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Models\User;
use App\Services\SsoGroupMappingLockService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run only this file through the isolated wrapper; committed two-worker fixtures. */
final class SsoPublicationConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for standalone SSO concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_empty_mapping_set_serializes_provider_disable_and_releases_on_rollback(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->assertSame(0, SsoGroupMapping::count());
        $this->assertFalse(AppSetting::where('key', SsoGroupMappingLockService::MUTEX_KEY)->exists());
        $committed = $this->race($actor, 'disable');
        $this->assertSame(['disabled', 'denied'], array_column($committed, 'status'));
        $this->assertSame(0, SsoGroupMapping::count());
        $this->assertSame(1, AppSetting::where('key', SsoGroupMappingLockService::MUTEX_KEY)->count());
        $this->assertFalse(AppSetting::where('key', 'settings.sso.google')->sole()->value['staff_enabled']);

        // Only this process's disposable records are changed. No working schema
        // or broader Feature test can enter this standalone process.
        AppSetting::where('key', 'settings.sso.google')->delete();
        $mutex = AppSetting::where('key', SsoGroupMappingLockService::MUTEX_KEY)->sole();
        $mutex->update(['value' => ['preserve' => 'synthetic marker']]);
        $before = $mutex->fresh()->getAttributes();
        $rolledBack = $this->race($actor, 'rollback');
        $this->assertSame(['rolled_back', 'admitted'], array_column($rolledBack, 'status'));
        $this->assertFalse(AppSetting::where('key', 'settings.sso.google')->exists());
        $this->assertSame($before, $mutex->fresh()->getAttributes(), 'No-op mutex acquisition must preserve existing value and timestamps.');
        $this->assertSame(0, DB::transactionLevel());
    }

    private function race(User $actor, string $operation): array
    {
        $barrier = storage_path('framework/testing/sso-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $paths = [$barrier.'.writer-attempt', $barrier.'.writer-acquired', $barrier.'.reader-attempt', $barrier.'.reader-acquired', $barrier.'.release'];
        $processes = [];
        try {
            $processes[] = $writer = new Process([PHP_BINARY, base_path('tests/Support/It/sso-publication-concurrency-worker.php'), $operation, (string) $actor->id, $paths[0], $paths[1], $paths[4]], base_path(), timeout: 45);
            $writer->start();
            $this->waitFor($paths[1], $processes);
            $processes[] = $reader = new Process([PHP_BINARY, base_path('tests/Support/It/sso-publication-concurrency-worker.php'), 'admit', (string) $actor->id, $paths[2], $paths[3], $paths[4]], base_path(), timeout: 45);
            $reader->start();
            $this->waitFor($paths[2], $processes);
            usleep(300_000);
            $this->assertTrue($reader->isRunning());
            $this->assertFileDoesNotExist($paths[3], 'Second connection must not acquire an empty mapping set while the first holds its publication transaction.');
            touch($paths[4]);
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $outcomes[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertFileExists($paths[3]);

            return $outcomes;
        } finally {
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

    private function waitFor(string $path, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (! is_file($path)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('SSO worker exited before barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('SSO worker barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
