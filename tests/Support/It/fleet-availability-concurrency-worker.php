<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Events\FleetSignalEmitted;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use App\Services\Fleet\FleetTelemetryIngestService;
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
        || count($argv) !== 5 || ! ctype_digit($argv[2])
        || ! in_array($argv[1], ['detect_state', 'detect_commit', 'heartbeat_state', 'heartbeat_device', 'deliver'], true)
        || ! in_array($argv[4], ['0', '1'], true)) {
        throw new RuntimeException('Fleet worker requires its parent disposable schema and explicit operation.');
    }
    [, $mode, $recordId, $barrier, $side] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Fleet worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    $normalized = str_replace('\\', '/', $barrier);
    if (! str_starts_with($normalized, $root) || str_contains(substr($normalized, strlen($root)), '/')
        || preg_match('/^'.preg_quote($token, '/').'-fleet-availability-[a-f0-9-]{36}$/D', basename($normalized)) !== 1) {
        throw new RuntimeException('Fleet barriers must remain within parent-owned storage.');
    }
    $ready = $barrier.'-'.$side.'.ready';
    $release = $barrier.'.release';
    $wait = static function () use ($release): void {
        $deadline = microtime(true) + 30;
        while (! is_file($release)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Fleet parent release timed out.');
            }
            usleep(10_000);
        }
    };
    Notification::fake();
    Http::preventStrayRequests();
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
    $stateLocks = 0;
    DB::connection()->beforeExecuting(function (string $sql) use ($mode, $ready, &$stateLocks): void {
        $sql = strtolower($sql);
        if (str_contains($sql, 'from `fleet_vehicle_state_snapshots`') && str_contains($sql, 'for update')) {
            $stateLocks++;
            if ($mode === 'heartbeat_state') {
                touch($ready);
            }
        }
        if ($mode === 'heartbeat_device' && str_contains($sql, 'from `devices`') && str_contains($sql, 'for update')) {
            touch($ready);
        }
    });
    $held = false;
    if (str_starts_with($mode, 'detect_')) {
        $hold = function (FleetVehicleStateSnapshot $state) use ($recordId, $ready, $wait, &$held): void {
            if (! $held && (int) $state->asset_id === (int) $recordId && DB::transactionLevel() > 0) {
                $held = true;
                touch($ready);
                $wait();
            }
        };
        if ($mode === 'detect_state') {
            FleetVehicleStateSnapshot::retrieved($hold);
        } else {
            FleetVehicleStateSnapshot::updated($hold);
        }
        (new DetectFleetOfflineDevices)->handle($app->make(FleetSignalService::class));
    } elseif (str_starts_with($mode, 'heartbeat_')) {
        $link = DeviceAssetLink::query()->where('asset_id', (int) $recordId)->active()->sole();
        $device = Device::query()->findOrFail($link->device_id);
        $result = $app->make(FleetTelemetryIngestService::class)->ingest('queclink', [
            'imei' => $device->imei, 'gps_time' => now()->toISOString(), 'event_type' => 'heartbeat',
        ], (int) $device->id);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Concurrent heartbeat did not succeed.');
        }
    } else {
        touch($ready);
        $wait();
        (new DispatchFleetSignalOutbox((int) $recordId))->handle($app->make(SignalProcessingService::class));
    }
    echo json_encode([
        'queue_levels' => $queue->levels, 'event_levels' => $eventLevels,
        'state_locks' => $stateLocks, 'transaction_level' => DB::transactionLevel(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fleet availability worker failed: '.$exception::class.PHP_EOL);
    exit(1);
}
