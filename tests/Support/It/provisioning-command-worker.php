<?php

use App\Domain\It\Services\ItProvisioningCommandService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token || count($argv) !== 8
        || ! in_array($argv[1], ['create', 'cancel', 'retry'], true)
        || ! ctype_digit($argv[2]) || ! ctype_digit($argv[3]) || ! ctype_digit($argv[4])
        || preg_match('/^[a-f0-9-]{36}$/D', $argv[5]) !== 1 || ! in_array($argv[6], ['first', 'second'], true)) {
        throw new RuntimeException('Provisioning command worker requires its exact parent disposable schema.');
    }
    [, $operation, $actorId, $targetId, $version, $uuid, $label, $ready] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Provisioning command worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    $normalized = str_replace('\\', '/', $ready);
    if (! str_starts_with($normalized, $root) || str_contains(substr($normalized, strlen($root)), '/')
        || preg_match('/^'.preg_quote($token, '/').'-provisioning-command-[a-f0-9-]{36}-[01]\.ready$/D', basename($normalized)) !== 1) {
        throw new RuntimeException('Provisioning barrier is outside the parent run.');
    }
    Http::preventStrayRequests();
    Notification::fake();
    $actor = User::query()->findOrFail((int) $actorId);
    Auth::login($actor);
    $waiting = false;
    $attemptedAt = null;
    DB::connection()->beforeExecuting(function (string $sql) use ($ready, &$waiting, &$attemptedAt): void {
        if (! $waiting && str_contains($sql, '`hr_employee_profiles`') && str_contains(strtolower($sql), 'for update')) {
            $waiting = true;
            $attemptedAt = microtime(true);
            if (file_put_contents($ready, 'waiting') === false) {
                throw new RuntimeException('Provisioning barrier write failed.');
            }
        }
    });
    $identity = ['actor_user_id' => (int) $actorId, 'request_uuid' => $uuid, 'expected_version' => (int) $version];
    $commands = app(ItProvisioningCommandService::class);
    try {
        $result = match ($operation) {
            'cancel' => $commands->lookup($actor, 'manual', (int) $targetId, 'create', $identity, true),
            'create' => $commands->execute($actor, 'manual', (int) $targetId, 'create', $identity + [
                'type' => 'other', 'item' => 'Synthetic concurrent manual task', 'priority' => 'normal',
                'assigned_to_user_id' => (int) $actorId, 'notes' => 'Synthetic private original instructions',
            ]),
            'retry' => $commands->execute($actor, 'request', (int) $targetId, 'retry', $identity + [
                'reason' => 'Synthetic competing '.$label.' retry review.',
            ]),
        };
        $output = ['status' => $result['status'], 'result_id' => $result['data']['result_id'] ?? null,
            'replayed' => $result['data']['replayed'] ?? null];
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }
        $output = ['status' => 'conflict'];
    }
    echo json_encode([...$output, 'waiting' => $waiting, 'attempted_at' => $attemptedAt,
        'completed_at' => microtime(true), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Failure metadata only: never print exception messages, SQL or bindings.
    $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/';
    $locations = [];
    foreach ([$exception, ...$exception->getTrace()] as $frame) {
        $file = str_replace('\\', '/', $frame instanceof Throwable ? $frame->getFile() : ($frame['file'] ?? ''));
        if (str_starts_with($file, $root.'app/') || str_starts_with($file, $root.'tests/')) {
            $locations[] = ['file' => substr($file, strlen($root)),
                'line' => $frame instanceof Throwable ? $frame->getLine() : ($frame['line'] ?? null)];
        }
    }
    $failure = ['exception_class' => $exception::class, 'locations' => array_slice($locations, 0, 8)];
    if ($exception instanceof ValidationException) {
        $failure['validation_keys'] = array_keys($exception->errors());
    }
    if ($exception instanceof QueryException) {
        $failure['sql_state'] = $exception->errorInfo[0] ?? null;
        $failure['driver_error'] = $exception->errorInfo[1] ?? null;
    }
    if ($exception instanceof HttpExceptionInterface) {
        $failure['http_status'] = $exception->getStatusCode();
    }
    fwrite(STDERR, json_encode(['failure' => $failure], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
