<?php

use App\Events\FleetSignalEmitted;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token
        || count($argv) !== 4 || ! ctype_digit($argv[1])) {
        throw new RuntimeException('Worker requires its parent disposable IT schema and explicit barriers.');
    }
    [, $assetId, $ready, $release] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Fleet worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    foreach ([$ready, $release] as $path) {
        $normalized = str_replace('\\', '/', $path);
        if (! str_starts_with($normalized, $root)
            || str_contains(substr($normalized, strlen($root)), '/')
            || preg_match('/^'.preg_quote($token, '/').'-fleet-offline-[a-f0-9-]{36}(?:-[01]\.ready|\.release)$/D', basename($normalized)) !== 1) {
            throw new RuntimeException('Fleet barriers must remain in the parent-owned storage directory.');
        }
    }
    Http::preventStrayRequests();
    Notification::fake();
    config()->set('fleet.signals.offline_after_minutes', 15);
    $queue = new class($app) extends QueueFake
    {
        public array $levels = [];

        public function push($job, $data = '', $queue = null)
        {
            if ($job instanceof DispatchFleetSignalOutbox) {
                $this->levels[] = DB::transactionLevel();
            }

            return parent::push($job, $data, $queue);
        }
    };
    Queue::swap($queue);
    $eventLevels = [];
    Event::listen(FleetSignalEmitted::class, function () use (&$eventLevels): void {
        $eventLevels[] = DB::transactionLevel();
    });
    $scanned = false;
    FleetVehicleStateSnapshot::retrieved(function (FleetVehicleStateSnapshot $state) use ($assetId, $ready, $release, &$scanned): void {
        if ($scanned || (int) $state->asset_id !== (int) $assetId) {
            return;
        }
        $scanned = true;
        touch($ready);
        $deadline = microtime(true) + 30;
        while (! is_file($release)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Fleet parent release timed out.');
            }
            usleep(10_000);
        }
    });
    (new DetectFleetOfflineDevices)->handle($app->make(FleetSignalService::class));
    echo json_encode([
        'scanned' => $scanned, 'queue_levels' => $queue->levels,
        'event_levels' => $eventLevels, 'transaction_level' => DB::transactionLevel(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fleet offline concurrency worker failed: '.$exception::class.PHP_EOL);
    exit(1);
}
