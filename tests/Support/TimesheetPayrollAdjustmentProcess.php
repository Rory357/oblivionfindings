<?php

namespace Tests\Support;

use App\Domain\Shifts\Timesheets\TimesheetPayrollAdjustmentService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\TimesheetAmendment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/** Two actual marker writers on the exact owning test schema; no worker/import. */
final class TimesheetPayrollAdjustmentProcess
{
    public static function make(array $operation, string $prefix): Process
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $database = (string) $config['database'];
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! OwnedTestDatabase::isOwnedBy($database, getmypid())
            || $connection->selectOne('SELECT DATABASE() AS db')->db !== $database
            || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || ! empty($config['unix_socket'])
            || dirname($prefix) !== base_path('test-results') || ! preg_match('/^payroll-adjustment-[a-f0-9-]+$/D', basename($prefix))) {
            throw new RuntimeException('Only the exact local process-owned test schema and receipt prefix are permitted.');
        }
        WorkforceEligibilityRuntimeProcess::assertEnvironmentFiles(base_path(), $database, getmypid(), getenv('CI'), getenv('GITHUB_ACTIONS'));
        if (! is_dir(dirname($prefix)) && ! mkdir(dirname($prefix), 0777)) {
            throw new RuntimeException('The owned payroll runtime artifact directory could not be created.');
        }
        $process = new Process([PHP_BINARY, __FILE__, json_encode($operation, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_BASE_PATH' => base_path(),
            'LARAVEL_STORAGE_PATH' => storage_path(), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_SOCKET' => '',
            'DB_TIMEZONE' => '+00:00', 'DB_EMULATE_PREPARES' => 'true',
            'DB_DATABASE' => $database, 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'APP_CONFIG_CACHE' => 'test-results/'.basename($prefix).'-absent-config.php',
            'APP_ROUTES_CACHE' => 'test-results/'.basename($prefix).'-absent-routes.php',
            'APP_SERVICES_CACHE' => 'test-results/'.basename($prefix).'-services.php',
            'APP_PACKAGES_CACHE' => 'test-results/'.basename($prefix).'-packages.php',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error',
            'PAYROLL_MARKER_BASE' => base_path(), 'PAYROLL_MARKER_DATABASE' => $database,
            'PAYROLL_MARKER_OWNER' => (string) getmypid(), 'PAYROLL_MARKER_PREFIX' => $prefix,
            'PAYROLL_MARKER_CLOCK' => now()->toIso8601String(), 'APP_WORKER_TIMEZONE' => config('app.worker_timezone'),
        ]);
        $process->setTimeout(45);

        return $process;
    }

    private static function pause(string $prefix): void
    {
        $value = json_encode(['pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
            'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR);
        if (file_put_contents($prefix.'-ready.writing', $value, LOCK_EX) !== strlen($value)
            || ! rename($prefix.'-ready.writing', $prefix.'-ready.json')) {
            throw new RuntimeException('The owned checkpoint could not be published.');
        }
        $deadline = microtime(true) + 30;
        while (! is_file($prefix.'-release') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($prefix.'-release')) {
            throw new RuntimeException('The owned checkpoint release timed out.');
        }
    }

    public static function main(array $arguments): void
    {
        require_once __DIR__.'/OwnedTestDatabase.php';
        try {
            $base = (string) getenv('PAYROLL_MARKER_BASE');
            $database = (string) getenv('PAYROLL_MARKER_DATABASE');
            $prefix = (string) getenv('PAYROLL_MARKER_PREFIX');
            $owner = (string) getenv('PAYROLL_MARKER_OWNER');
            $configPath = 'test-results/'.basename($prefix).'-absent-config.php';
            $routesPath = 'test-results/'.basename($prefix).'-absent-routes.php';
            if (getenv('APP_ENV') !== 'testing' || ! ctype_digit($owner) || (string) (int) $owner !== $owner
                || ! OwnedTestDatabase::isOwnedBy($database, (int) $owner)
                || realpath($base) !== realpath(__DIR__.'/../..') || dirname($prefix) !== $base.DIRECTORY_SEPARATOR.'test-results'
                || ! preg_match('/^payroll-adjustment-[a-f0-9-]+$/D', basename($prefix))
                || getenv('APP_BASE_PATH') !== $base || getenv('LARAVEL_STORAGE_PATH') !== $base.DIRECTORY_SEPARATOR.'storage'
                || getenv('DB_DATABASE') !== $database || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_URL') !== '' || getenv('DB_SOCKET') !== ''
                || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)
                || getenv('APP_CONFIG_CACHE') !== $configPath || getenv('APP_ROUTES_CACHE') !== $routesPath
                || is_file($base.DIRECTORY_SEPARATOR.$configPath) || is_file($base.DIRECTORY_SEPARATOR.$routesPath)) {
                throw new RuntimeException('The exact owned environment is required before bootstrap.');
            }
            $cachePaths = ['APP_CONFIG_CACHE' => $configPath, 'APP_ROUTES_CACHE' => $routesPath,
                'APP_SERVICES_CACHE' => 'test-results/'.basename($prefix).'-services.php',
                'APP_PACKAGES_CACHE' => 'test-results/'.basename($prefix).'-packages.php'];
            foreach ($cachePaths as $key => $relative) {
                if (getenv($key) !== $relative || is_file($base.DIRECTORY_SEPARATOR.$relative)) {
                    throw new RuntimeException('Exact absent owned metadata cache paths are required before bootstrap.');
                }
            }
            require $base.'/vendor/autoload.php';
            WorkforceEligibilityRuntimeProcess::assertEnvironmentFiles($base, $database, (int) $owner, getenv('CI'), getenv('GITHUB_ACTIONS'));
            foreach ([TimesheetPayrollAdjustmentService::class, TestCase::class] as $class) {
                $file = (new \ReflectionClass($class))->getFileName();
                if (! $file || ! str_starts_with(str_replace('\\', '/', (string) realpath($file)), str_replace('\\', '/', (string) realpath($base)).'/')) {
                    throw new RuntimeException('Application and test classes must resolve from the owned checkout.');
                }
            }
            Carbon::setTestNow(Carbon::parse((string) getenv('PAYROLL_MARKER_CLOCK')));
            CarbonImmutable::setTestNow(Carbon::parse((string) getenv('PAYROLL_MARKER_CLOCK')));
            $app = require $base.'/bootstrap/app.php';
            if (realpath($app->basePath()) !== realpath($base)
                || $app->getCachedConfigPath() !== $base.DIRECTORY_SEPARATOR.$configPath
                || $app->getCachedRoutesPath() !== $base.DIRECTORY_SEPARATOR.$routesPath
                || is_file($app->getCachedConfigPath()) || is_file($app->getCachedRoutesPath())) {
                throw new RuntimeException('The exact local application and absent cache files are required.');
            }
            $actual = ['APP_CONFIG_CACHE' => $app->getCachedConfigPath(), 'APP_ROUTES_CACHE' => $app->getCachedRoutesPath(),
                'APP_SERVICES_CACHE' => $app->getCachedServicesPath(), 'APP_PACKAGES_CACHE' => $app->getCachedPackagesPath()];
            foreach ($actual as $key => $path) {
                if (str_replace('\\', '/', $path) !== str_replace('\\', '/', $base.'/'.$cachePaths[$key]) || is_file($path)) {
                    throw new RuntimeException('Actual cache getters must match exact owned absent paths before Kernel bootstrap.');
                }
            }
            if (str_replace('\\', '/', $app->storagePath()) !== str_replace('\\', '/', $base.'/storage')) {
                throw new RuntimeException('The local storage root is required before Kernel bootstrap.');
            }
            $app->make(Kernel::class)->bootstrap();
            if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
                || DB::selectOne('SELECT DATABASE() AS db')->db !== $database
                || ! empty(DB::connection()->getConfig('unix_socket')) || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('The exact disposable physical root is required.');
            }
            if (config('cache.default') !== 'array' || config('session.driver') !== 'array' || config('queue.default') !== 'sync'
                || config('mail.default') !== 'array' || ! in_array(config('broadcasting.default'), [null, 'null'], true)
                || config('app.worker_timezone') !== getenv('APP_WORKER_TIMEZONE')) {
                throw new RuntimeException('The pinned test-only transports and worker timezone are required.');
            }
            Notification::fake();
            Queue::fake([RefreshWorkforceEligibility::class]);
            $operation = json_decode($arguments[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $actor = User::findOrFail((int) $operation['actor_id']);
            $amendment = TimesheetAmendment::findOrFail((int) $operation['amendment_id']);
            Auth::setUser($actor);
            DB::beginTransaction();
            $armed = true;
            if ($operation['phase'] === 'before_mutex') {
                DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $prefix): void {
                    if ($armed && str_contains($sql, 'hr_payroll_run_mutexes') && str_contains(strtolower($sql), 'for update')) {
                        $armed = false;
                        self::pause($prefix);
                    }
                });
            } elseif ($operation['phase'] === 'marker_saved') {
                TimesheetAmendment::updated(function ($saved) use (&$armed, $prefix, $amendment): void {
                    if ($armed && (int) $saved->id === (int) $amendment->id && $saved->applied_at !== null) {
                        $armed = false;
                        self::pause($prefix);
                    }
                });
            } else {
                throw new RuntimeException('The named test checkpoint is required.');
            }
            $result = app(TimesheetPayrollAdjustmentService::class)->process($amendment, $actor);
            DB::commit();
            if ($result->error !== null || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('An actual committed marker result is required.');
            }
            echo json_encode(['changed' => $result->changed, 'applied_at' => $result->amendment->applied_at->toISOString(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Owned payroll marker process failed: '.$exception::class.PHP_EOL);
            exit(1);
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require __DIR__.'/../../vendor/autoload.php';
    TimesheetPayrollAdjustmentProcess::main($argv);
}
