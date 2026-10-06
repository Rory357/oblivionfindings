<?php

namespace Tests\Support;

use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Services\LeaveService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Throwable;

/** Actual native Leave writers; owned PID schema only, no schema creation/import. */
final class LeaveReservationProcess
{
    public static function make(array $operation, string $prefix): Process
    {
        $config = DB::connection()->getConfig();
        $database = (string) $config['database'];
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || ! OwnedTestDatabase::isOwnedBy($database, getmypid())
            || dirname($prefix) !== base_path('test-results') || ! preg_match('/^leave-reservation-[a-f0-9-]+$/D', basename($prefix))) {
            throw new RuntimeException('Only this process-owned schema and receipt prefix are permitted.');
        }
        $checkpointDirectory = dirname($prefix);
        if (! is_dir($checkpointDirectory) && ! @mkdir($checkpointDirectory, 0755) && ! is_dir($checkpointDirectory)) {
            throw new RuntimeException('The owned checkpoint directory could not be created.');
        }
        $process = new Process([PHP_BINARY, __FILE__, json_encode($operation, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_DATABASE' => $database, 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'APP_CONFIG_CACHE' => 'test-results/'.basename($prefix).'-absent-config.php',
            'APP_ROUTES_CACHE' => 'test-results/'.basename($prefix).'-absent-routes.php',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'error',
            'LEAVE_RUNTIME_BASE' => base_path(), 'LEAVE_RUNTIME_DATABASE' => $database,
            'LEAVE_RUNTIME_OWNER' => (string) getmypid(), 'LEAVE_RUNTIME_PREFIX' => $prefix,
            'LEAVE_RUNTIME_CLOCK' => now()->toIso8601String(), 'APP_WORKER_TIMEZONE' => config('app.worker_timezone'),
        ]);
        $process->setTimeout(45);

        return $process;
    }

    private static function pause(string $prefix, string $phase): void
    {
        $value = json_encode(['pid' => getmypid(), 'transaction_level' => DB::transactionLevel(), 'phase' => $phase,
            'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id], JSON_THROW_ON_ERROR);
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
            $base = (string) getenv('LEAVE_RUNTIME_BASE');
            $database = (string) getenv('LEAVE_RUNTIME_DATABASE');
            $prefix = (string) getenv('LEAVE_RUNTIME_PREFIX');
            $owner = (string) getenv('LEAVE_RUNTIME_OWNER');
            $configCachePath = 'test-results/'.basename($prefix).'-absent-config.php';
            $routesCachePath = 'test-results/'.basename($prefix).'-absent-routes.php';
            if (getenv('APP_ENV') !== 'testing' || ! ctype_digit($owner) || (string) (int) $owner !== $owner
                || ! OwnedTestDatabase::isOwnedBy($database, (int) $owner)
                || realpath($base) !== realpath(__DIR__.'/../..') || dirname($prefix) !== $base.DIRECTORY_SEPARATOR.'test-results'
                || ! preg_match('/^leave-reservation-[a-f0-9-]+$/D', basename($prefix))
                || getenv('DB_DATABASE') !== $database || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_URL') !== ''
                || getenv('APP_CONFIG_CACHE') !== $configCachePath || getenv('APP_ROUTES_CACHE') !== $routesCachePath
                || is_file($base.DIRECTORY_SEPARATOR.$configCachePath) || is_file($base.DIRECTORY_SEPARATOR.$routesCachePath)) {
                throw new RuntimeException('The exact isolated runtime is required before bootstrap.');
            }
            require $base.'/vendor/autoload.php';
            Carbon::setTestNow(Carbon::parse((string) getenv('LEAVE_RUNTIME_CLOCK')));
            CarbonImmutable::setTestNow(Carbon::parse((string) getenv('LEAVE_RUNTIME_CLOCK')));
            $app = require $base.'/bootstrap/app.php';
            if (realpath($app->basePath()) !== realpath($base)
                || $app->getCachedConfigPath() !== $base.DIRECTORY_SEPARATOR.$configCachePath
                || $app->getCachedRoutesPath() !== $base.DIRECTORY_SEPARATOR.$routesCachePath
                || is_file($app->getCachedConfigPath()) || is_file($app->getCachedRoutesPath())) {
                throw new RuntimeException('The exact local application and absent owned cache paths are required.');
            }
            $app->make(Kernel::class)->bootstrap();
            if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
                || DB::selectOne('SELECT DATABASE() AS selected_database')->selected_database !== $database
                || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('The exact disposable root connection is required.');
            }
            Notification::fake();
            Queue::fake([RefreshWorkforceEligibility::class]);
            $operation = json_decode($arguments[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
            DB::beginTransaction(); // A real enclosing RR snapshot, not a testing callback manager.
            $actor = User::findOrFail((int) $operation['actor_id']);
            $subject = User::findOrFail((int) $operation['subject_id']);
            $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            Auth::setUser($actor);
            if (($operation['pause_phase'] ?? null) === 'before_mutex') {
                $armed = true;
                DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $prefix): void {
                    if (! $armed || ! str_contains($sql, 'hr_payroll_run_mutexes') || ! str_contains(strtolower($sql), 'for update')) {
                        return;
                    }
                    $armed = false;
                    self::pause($prefix, 'before_mutex');
                });
            } elseif (($operation['pause_phase'] ?? null) === 'reserved') {
                $armed = true;
                HrLeaveBalanceLedger::created(function ($ledger) use (&$armed, $prefix): void {
                    if ($armed && $ledger->entry_type === 'reserved') {
                        $armed = false;
                        self::pause($prefix, 'reserved');
                    }
                });
            }
            try {
                $leave = match ($operation['entry']) {
                    'self' => $app->make(LeaveService::class)->submitRequest($subject, $operation['data']),
                    'managed' => $app->make(LeaveService::class)->submitRequest($subject, $operation['data'], $actor),
                    'roster' => $app->make(LeaveService::class)->createRosterLeave($subject, $operation['data'], $actor),
                    default => throw new RuntimeException('Unknown bounded Leave entry.'),
                };
                DB::commit();
                $result = ['status' => 201, 'request_id' => $leave->id, 'created_by' => $leave->created_by,
                    'hours_requested' => (float) $leave->hours_requested, 'transaction_level' => DB::transactionLevel()];
            } catch (ValidationException $exception) {
                DB::rollBack();
                $result = ['status' => $exception->status, 'errors' => $exception->errors()];
            } catch (HttpException $exception) {
                DB::rollBack();
                $result = ['status' => $exception->getStatusCode()];
            } catch (ModelNotFoundException) {
                DB::rollBack();
                $result = ['status' => 404];
            } catch (\InvalidArgumentException $exception) {
                DB::rollBack();
                $result = ['status' => 422, 'invalid_argument' => true];
            }
            echo json_encode($result, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
            exit(1);
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require __DIR__.'/../../vendor/autoload.php';
    LeaveReservationProcess::main($argv);
}
