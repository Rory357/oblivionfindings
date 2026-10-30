<?php

namespace Tests\Support;

use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\StaffCredential;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/** Test-only, separate ordinary runtime; never creates or imports a database. */
final class WorkforceEligibilityRuntimeProcess
{
    public static function make(array $operation, string $queue, ?string $clock = null): Process
    {
        $connection = DB::connection()->getConfig();
        $database = (string) $connection['database'];
        self::assertOwnedConnection($database, getmypid(), (string) $connection['host'], (string) ($connection['unix_socket'] ?? ''));
        if (DB::connection()->getDriverName() !== 'mysql' || ! empty($connection['url'])
            || (string) DB::connection()->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== $database) {
            throw new RuntimeException('The actual parent connection must use its exact PID-owned MySQL schema.');
        }
        self::assertEnvironmentFiles(base_path(), $database, getmypid(), getenv('CI'), getenv('GITHUB_ACTIONS'));
        if (! is_dir(base_path('test-results')) && ! mkdir(base_path('test-results'), 0777)) {
            throw new RuntimeException('The calling checkout runtime artifact directory could not be created.');
        }
        if (! preg_match('/^workforce-eligibility-runtime-[a-f0-9-]+$/D', $queue)) {
            throw new RuntimeException('A unique runtime test queue is required.');
        }
        $process = new Process([PHP_BINARY, __FILE__, json_encode($operation, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'APP_CONFIG_CACHE' => 'test-results/'.$queue.'-absent-config.php',
            'APP_ROUTES_CACHE' => 'test-results/'.$queue.'-absent-routes.php',
            'APP_SERVICES_CACHE' => 'test-results/'.$queue.'-services.php',
            'APP_PACKAGES_CACHE' => 'test-results/'.$queue.'-packages.php',
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_SOCKET' => '', 'DB_DATABASE' => $database,
            'DB_TIMEZONE' => '+00:00', 'DB_EMULATE_PREPARES' => 'true',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_QUEUE_CONNECTION' => 'mysql', 'DB_QUEUE_TABLE' => 'jobs', 'DB_QUEUE' => $queue, 'DB_QUEUE_RETRY_AFTER' => '90',
            'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_TABLE' => 'cache',
            'DB_CACHE_LOCK_CONNECTION' => 'mysql', 'DB_CACHE_LOCK_TABLE' => 'cache_locks',
            'CACHE_STORE' => 'database', 'CACHE_PREFIX' => $queue.'-',
            'QUEUE_CONNECTION' => 'database', 'QUEUE_FAILED_DRIVER' => 'database-uuids',
            'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error',
            'APP_WORKER_TIMEZONE' => config('app.worker_timezone', 'Pacific/Auckland'),
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            'WORKFORCE_RUNTIME_DATABASE' => $database, 'WORKFORCE_RUNTIME_OWNER_PID' => (string) getmypid(),
            'WORKFORCE_RUNTIME_QUEUE' => $queue, 'WORKFORCE_RUNTIME_CLOCK' => $clock ?? '',
            'WORKFORCE_RUNTIME_BASE' => base_path(),
        ]);
        $process->setTimeout(60);

        return $process;
    }

    /** Remove only this scenario's exact owned cache artifacts after children exit. */
    public static function cleanup(string $queue): void
    {
        if (! preg_match('/^workforce-eligibility-runtime-[a-f0-9-]+$/D', $queue)
            || realpath(base_path()) !== realpath(__DIR__.'/../..')) {
            throw new RuntimeException('Only the calling checkout and owned runtime queue can be cleaned.');
        }
        foreach (['-absent-config.php', '-absent-routes.php', '-services.php', '-packages.php'] as $suffix) {
            $path = base_path('test-results/'.$queue.$suffix);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('An owned runtime cache artifact could not be removed.');
            }
        }
    }

    public static function receipt(Process $process): array
    {
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Isolated runtime failed: '.$process->getErrorOutput().' '.$process->getOutput());
        }
        $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($receipt) || ($receipt['exit_code'] ?? -1) !== 0) {
            throw new RuntimeException('A successful isolated runtime receipt is required.');
        }

        return $receipt;
    }

    public static function main(array $arguments): void
    {
        try {
            $base = (string) getenv('WORKFORCE_RUNTIME_BASE');
            $database = (string) getenv('WORKFORCE_RUNTIME_DATABASE');
            $owner = (string) getenv('WORKFORCE_RUNTIME_OWNER_PID');
            $queue = (string) getenv('WORKFORCE_RUNTIME_QUEUE');
            require_once __DIR__.'/OwnedTestDatabase.php';
            if (getenv('APP_ENV') !== 'testing' || ! ctype_digit($owner)
                || realpath($base) !== realpath(__DIR__.'/../..')
                || getenv('DB_DATABASE') !== $database || getenv('DB_CONNECTION') !== 'mysql'
                || ! in_array(getenv('DB_URL'), [false, ''], true) || ! preg_match('/^workforce-eligibility-runtime-[a-f0-9-]+$/D', $queue)) {
                throw new RuntimeException('The exact isolated runtime environment is required before bootstrap.');
            }
            self::assertOwnedConnection($database, (int) $owner, (string) getenv('DB_HOST'), (string) getenv('DB_SOCKET'));
            self::assertEnvironmentFiles($base, $database, (int) $owner, getenv('CI'), getenv('GITHUB_ACTIONS'));
            self::assertPinnedEnvironment($queue);
            $cachePaths = [
                'APP_CONFIG_CACHE' => 'test-results/'.$queue.'-absent-config.php',
                'APP_ROUTES_CACHE' => 'test-results/'.$queue.'-absent-routes.php',
                'APP_SERVICES_CACHE' => 'test-results/'.$queue.'-services.php',
                'APP_PACKAGES_CACHE' => 'test-results/'.$queue.'-packages.php',
            ];
            foreach ($cachePaths as $key => $relative) {
                if (getenv($key) !== $relative || (in_array($key, ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'], true)
                    && is_file($base.'/'.$relative))) {
                    throw new RuntimeException('Exact owned runtime cache paths and absent config/routes are required.');
                }
            }
            require $base.'/vendor/autoload.php';
            foreach ([WorkforceEligibilityRefresh::class, TestCase::class] as $class) {
                $file = (new \ReflectionClass($class))->getFileName();
                if (! $file || ! str_starts_with(str_replace('\\', '/', (string) realpath($file)),
                    str_replace('\\', '/', (string) realpath($base)).'/')) {
                    throw new RuntimeException('Runtime application and tests must resolve from the calling checkout.');
                }
            }
            $operation = json_decode($arguments[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $clock = (string) getenv('WORKFORCE_RUNTIME_CLOCK');
            if ($clock !== '') {
                Carbon::setTestNow(Carbon::parse($clock)->utc());
                CarbonImmutable::setTestNow(CarbonImmutable::parse($clock)->utc());
            }
            $app = require $base.'/bootstrap/app.php';
            if (realpath($app->basePath()) !== realpath($base)) {
                throw new RuntimeException('The current local application root is required before Kernel bootstrap.');
            }
            $actual = [
                'APP_CONFIG_CACHE' => $app->getCachedConfigPath(),
                'APP_ROUTES_CACHE' => $app->getCachedRoutesPath(),
                'APP_SERVICES_CACHE' => $app->getCachedServicesPath(),
                'APP_PACKAGES_CACHE' => $app->getCachedPackagesPath(),
            ];
            foreach ($actual as $key => $path) {
                if (str_replace('\\', '/', $path) !== str_replace('\\', '/', $base.'/'.$cachePaths[$key])
                    || (in_array($key, ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'], true) && is_file($path))) {
                    throw new RuntimeException('Actual local cache getters must match guarded owned paths before bootstrap.');
                }
            }
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
            self::assertConnections($database, $queue);
            $receipt = ['pid' => getmypid(), 'database' => $database, 'queue' => $queue,
                'worker_timezone' => config('app.worker_timezone'), 'exit_code' => 0];
            $mode = $operation['mode'] ?? null;
            if ($mode === 'producer') {
                $receipt += self::produce($operation, $queue);
            } elseif ($mode === 'nightly') {
                RecalculateFutureShiftEligibility::dispatch();
            } elseif ($mode === 'duplicate') {
                $request = WorkforceEligibilityRecheck::query()->findOrFail((int) $operation['recheck_id']);
                Queue::connection('database')->push(new RefreshWorkforceEligibility((int) $request->id, (int) $operation['version']), '', $queue);
            } elseif ($mode === 'scheduled_recovery') {
                if ($clock !== '') {
                    throw new RuntimeException('The unchanged scheduled event requires real time.');
                }
                $events = collect($app->make(Schedule::class)->events())
                    ->filter(fn ($event): bool => preg_match('/(?:^|\s)workforce:recover-eligibility-refresh(?:\s|$)/', $event->command) === 1);
                if ($events->count() !== 1) {
                    throw new RuntimeException('One exact existing recovery event is required.');
                }
                $event = $events->sole();
                if ($event->expression !== '* * * * *' || ! $event->withoutOverlapping || ! $event->isDue($app) || ! $event->filtersPass($app)) {
                    throw new RuntimeException('The existing every-minute recovery event must be due.');
                }
                // The ordinary Artisan grandchild inherits the same pinned environment.
                self::assertEnvironmentFiles($base, $database, (int) $owner, getenv('CI'), getenv('GITHUB_ACTIONS'));
                self::assertPinnedEnvironment($queue);
                $event->run($app);
                if ($event->exitCode !== 0) {
                    throw new RuntimeException('The real scheduled recovery command did not complete.');
                }
                $receipt += ['event_expression' => $event->expression, 'without_overlapping' => $event->withoutOverlapping,
                    'event_exit_code' => $event->exitCode, 'event_mutex' => $event->mutexName()];
            } elseif ($mode === 'recovery') {
                $output = new BufferedOutput;
                $input = new ArgvInput(['artisan', 'workforce:recover-eligibility-refresh']);
                $exit = $kernel->handle($input, $output);
                $kernel->terminate($input, $exit);
                $receipt += ['recover_dispatch_attempts' => (int) trim($output->fetch())];
                $receipt['exit_code'] = $exit;
            } elseif ($mode === 'worker') {
                $receipt += self::work($operation, $kernel);
            } else {
                throw new RuntimeException('Unknown isolated runtime operation.');
            }
            echo json_encode($receipt, JSON_THROW_ON_ERROR);
            exit($receipt['exit_code']);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage());
            exit(1);
        }
    }

    /** The same deliberately disposable schema whitelist used by committed fixtures. */
    public static function assertOwnedConnection(string $database, int $owner, string $host, string $socket): void
    {
        if (! OwnedTestDatabase::isOwnedBy($database, $owner)
            || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || $socket !== '') {
            throw new RuntimeException('An exact PID-owned test schema on a loopback MySQL host is required.');
        }
    }

    /** Never permit arbitrary dotenv fallback in the ordinary scheduled Artisan child. */
    public static function assertEnvironmentFiles(string $base, string $database, int $owner, string|false $ci, string|false $github): void
    {
        if (is_file($base.'/.env.testing')) {
            throw new RuntimeException('A testing dotenv override cannot replace the pinned runtime environment.');
        }
        if (! is_file($base.'/.env')) {
            return;
        }
        if ($ci !== 'true' || $github !== 'true' || $database !== 'oblivion_findings_codex_test_'.$owner || $owner < 1) {
            throw new RuntimeException('Only the existing PID-isolated GitHub CI dotenv contract is supported.');
        }
        // Anchor the template to this helper's known checkout, never the supplied environment directory.
        $checkout = realpath(__DIR__.'/../..');
        $example = realpath(__DIR__.'/../../.env.example');
        if (! $checkout || ! $example || dirname($example) !== $checkout) {
            throw new RuntimeException('The independently known checkout environment template is required.');
        }
        $template = file_get_contents($example);
        $actual = file_get_contents($base.'/.env');
        $pattern = '/^APP_KEY=([^\r\n]*)(?=\r?$)/m';
        if ($template === false || $actual === false
            || preg_match_all($pattern, $template, $templateKeys) !== 1 || $templateKeys[1][0] !== ''
            || preg_match_all($pattern, $actual, $actualKeys) !== 1
            || ! str_starts_with($actualKeys[1][0], 'base64:')) {
            throw new RuntimeException('The unchanged CI environment template and one generated key are required.');
        }
        $encoded = substr($actualKeys[1][0], 7);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== 32 || base64_encode($decoded) !== $encoded
            || preg_replace($pattern, 'APP_KEY=', $actual) !== $template) {
            throw new RuntimeException('Only the actual key-generation difference may alter the CI dotenv template.');
        }
    }

    private static function assertPinnedEnvironment(string $queue): void
    {
        foreach (['DB_QUEUE_CONNECTION' => 'mysql', 'DB_QUEUE_TABLE' => 'jobs', 'DB_QUEUE' => $queue,
            'DB_QUEUE_RETRY_AFTER' => '90', 'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_TABLE' => 'cache',
            'DB_CACHE_LOCK_CONNECTION' => 'mysql', 'DB_CACHE_LOCK_TABLE' => 'cache_locks',
            'CACHE_STORE' => 'database', 'CACHE_PREFIX' => $queue.'-', 'QUEUE_CONNECTION' => 'database',
            'QUEUE_FAILED_DRIVER' => 'database-uuids', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error',
            'DB_TIMEZONE' => '+00:00', 'DB_EMULATE_PREPARES' => 'true',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException('Every runtime storage and delivery transport must be explicitly pinned.');
            }
        }
        if (! is_string(getenv('APP_KEY')) || getenv('APP_KEY') === '' || ! is_string(getenv('APP_WORKER_TIMEZONE'))
            || getenv('APP_WORKER_TIMEZONE') === '' || ! ctype_digit((string) getenv('DB_PORT'))
            || (int) getenv('DB_PORT') < 1 || (int) getenv('DB_PORT') > 65535 || (string) getenv('DB_USERNAME') === '') {
            throw new RuntimeException('Explicit runtime key, worker timezone and connection values are required.');
        }
    }

    private static function assertConnections(string $database, string $queue): void
    {
        if (config('queue.default') !== 'database' || config('cache.default') !== 'database'
            || config('queue.connections.database.queue') !== $queue || config('cache.prefix') !== $queue.'-'
            || config('mail.default') !== 'array' || config('session.driver') !== 'array'
            || ! in_array(config('broadcasting.default'), [null, 'null'], true) || config('app.worker_timezone') !== getenv('APP_WORKER_TIMEZONE')) {
            throw new RuntimeException('The queue, persistent cache and safe mail runtime must be isolated.');
        }
        $connections = [config('database.default'), config('queue.connections.database.connection'),
            config('cache.stores.database.connection'), config('cache.stores.database.lock_connection'), config('queue.failed.database')];
        foreach ($connections as $name) {
            $connection = DB::connection($name);
            self::assertOwnedConnection($connection->getDatabaseName(), (int) getenv('WORKFORCE_RUNTIME_OWNER_PID'),
                (string) $connection->getConfig('host'), (string) $connection->getConfig('unix_socket'));
            if ($connection->getDriverName() !== 'mysql' || ! empty($connection->getConfig('url')) || $connection->getDatabaseName() !== $database
                || (string) $connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== $database) {
                throw new RuntimeException('Every runtime storage connection must use the exact disposable schema.');
            }
        }
    }

    private static function produce(array $operation, string $queue): array
    {
        $credential = StaffCredential::query()->findOrFail((int) $operation['credential_id']);
        $beforeCount = DB::table('jobs')->where('queue', $queue)->count();
        $insideCount = null;
        $intent = null;
        try {
            DB::transaction(function () use ($credential, $operation, $queue, $beforeCount, &$insideCount, &$intent): void {
                if ($operation['touch'] ?? false) {
                    app(WorkforceEligibilityRefresh::class)->sourceChanged($credential);
                } else {
                    $credential->update(['expires_at' => $operation['expires_at']]);
                }
                $intent = WorkforceEligibilityRecheck::query()->where('source_type', $credential->getTable())
                    ->where('source_id', $credential->id)->firstOrFail();
                $insideCount = DB::table('jobs')->where('queue', $queue)->count();
                if ($insideCount !== $beforeCount) {
                    throw new RuntimeException('Refresh work was queued before its true outer commit.');
                }
                if ($operation['rollback'] ?? false) {
                    throw new RuntimeException('Controlled source rollback.');
                }
            });
        } catch (RuntimeException $exception) {
            if (! ($operation['rollback'] ?? false) || $exception->getMessage() !== 'Controlled source rollback.') {
                throw $exception;
            }
        }

        return ['rolled_back' => (bool) ($operation['rollback'] ?? false), 'jobs_before' => $beforeCount,
            'jobs_inside_transaction' => $insideCount, 'jobs_after' => DB::table('jobs')->where('queue', $queue)->count(),
            'recheck_id' => $intent?->id, 'source_version' => $intent?->source_version];
    }

    private static function work(array $operation, Kernel $kernel): array
    {
        $events = [];
        foreach ([JobProcessing::class => 'started', JobProcessed::class => 'processed',
            JobReleasedAfterException::class => 'released', JobFailed::class => 'failed'] as $class => $status) {
            app('events')->listen($class, function ($event) use (&$events, $status): void {
                $name = $event->job->resolveName();
                $events[] = ['job_id' => (string) $event->job->getJobId(), 'class' => $name, 'status' => $status];
                if ($status === 'started' && ! in_array($name, [RefreshWorkforceEligibility::class, RecalculateFutureShiftEligibility::class], true)) {
                    throw new RuntimeException('Unexpected job in the isolated eligibility queue.');
                }
            });
        }
        if (isset($operation['pause_recheck_id'])) {
            self::pauseAfterCommittedCursor((int) $operation['pause_recheck_id'], (int) $operation['pause_version'], (string) $operation['pause_file']);
        }
        $jobs = max(1, min(150, (int) ($operation['max_jobs'] ?? 100)));
        $input = new ArgvInput(['artisan', 'queue:work', 'database', '--queue='.(string) getenv('WORKFORCE_RUNTIME_QUEUE'),
            '--stop-when-empty', '--sleep=0', '--max-jobs='.$jobs, '--max-time=45', '--json']);
        $output = new BufferedOutput;
        $exit = $kernel->handle($input, $output);
        $kernel->terminate($input, $exit);

        return ['exit_code' => $exit, 'job_events' => $events];
    }

    private static function pauseAfterCommittedCursor(int $id, int $version, string $file): void
    {
        if (realpath(dirname($file)) !== realpath(base_path('test-results'))
            || ! preg_match('/^workforce-eligibility-runtime-[a-f0-9-]+-pause\.json$/D', basename($file))) {
            throw new RuntimeException('A task-owned pause receipt path is required.');
        }
        $paused = false;
        DB::listen(function (QueryExecuted $query) use ($id, $version, $file, &$paused): void {
            if ($paused || ! str_contains(strtolower($query->sql), 'update `workforce_eligibility_rechecks`')
                || ! str_contains($query->sql, '`last_shift_id`') || count($query->bindings) < 5
                || (int) $query->bindings[count($query->bindings) - 2] !== $id
                || (int) $query->bindings[count($query->bindings) - 1] !== $version) {
                return;
            }
            $paused = true;
            $connection = DB::connection();
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new RuntimeException('Cursor interruption must follow an actual committed checkpoint.');
            }
            $statement = $connection->getPdo()->prepare('SELECT status, source_version, last_shift_id, scanned_count, attempts FROM workforce_eligibility_rechecks WHERE id = ?');
            $statement->execute([$id]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (! $row || $row['status'] !== 'processing' || (int) $row['source_version'] !== $version
                || (int) $row['scanned_count'] !== 50 || (int) $row['last_shift_id'] < 1) {
                throw new RuntimeException('The exact durable first cursor chunk is required.');
            }
            $temporary = $file.'.writing';
            $json = json_encode(['pid' => getmypid(), 'recheck_id' => $id, 'source_version' => $version,
                'last_shift_id' => (int) $row['last_shift_id'], 'scanned_count' => 50, 'transaction_level' => 0], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $file)) {
                throw new RuntimeException('The complete owned cursor receipt could not be published.');
            }
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline) {
                usleep(10000);
            }
            throw new RuntimeException('The owned paused worker was not stopped within its bounded checkpoint.');
        });
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    WorkforceEligibilityRuntimeProcess::main($argv);
}
