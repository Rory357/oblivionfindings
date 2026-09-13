<?php

use App\Domain\It\Services\ItTechnicalDeliveryRecoveryService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token
        || count($argv) !== 6 || ! in_array($argv[1], ['device', 'fleet'], true)
        || ! ctype_digit($argv[2]) || ! ctype_digit($argv[3]) || preg_match('/^[a-f0-9]{64}$/D', $argv[4]) !== 1) {
        throw new RuntimeException('Retry worker requires its parent disposable schema and reviewed intent.');
    }
    [, $source, $id, $actorId, $version, $ready] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Retry worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    $normalized = str_replace('\\', '/', $ready);
    if (! str_starts_with($normalized, $root) || str_contains(substr($normalized, strlen($root)), '/')
        || preg_match('/^'.preg_quote($token, '/').'-technical-retry-[a-f0-9-]{36}-[01]\.ready$/D', basename($normalized)) !== 1) {
        throw new RuntimeException('Retry barrier is not owned by the parent run.');
    }
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    $actor = User::query()->findOrFail((int) $actorId);
    Auth::login($actor);
    $table = $source === 'device' ? 'device_event_signal_outbox' : 'fleet_signal_outbox';
    $waiting = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($table, $ready, &$waiting): void {
        if (! $waiting && str_contains($sql, '`'.$table.'`') && str_contains(strtolower($sql), 'for update')) {
            $waiting = true;
            if (file_put_contents($ready, 'waiting') === false) {
                throw new RuntimeException('Could not publish retry lock barrier.');
            }
        }
    });
    try {
        $result = app(ItTechnicalDeliveryRecoveryService::class)->retry($actor, (int) $actorId, $source, (int) $id, $version);
        $status = 200;
    } catch (HttpExceptionInterface $exception) {
        $status = $exception->getStatusCode();
        $result = [];
    }
    echo json_encode(['status' => $status, 'waiting' => $waiting, 'retry_requested' => $result['retry_requested'] ?? false,
        'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Technical retry worker failed: '.$exception::class.PHP_EOL);
    exit(1);
}
