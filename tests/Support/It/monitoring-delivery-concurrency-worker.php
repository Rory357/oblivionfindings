<?php

use App\Domain\It\Services\ItMonitoringDeliveryService;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ItTicket;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token
        || count($argv) !== 5 || ! ctype_digit($argv[2])
        || ! in_array($argv[1], ['hold_claim', 'hold_ticket', 'hold_commit', 'deliver', 'retry'], true)
        || ! in_array($argv[4], ['0', '1'], true)) {
        throw new RuntimeException('Monitoring worker requires its parent disposable schema and explicit operation.');
    }
    [, $mode, $outboxId, $barrier, $side] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Monitoring worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    $normalized = str_replace('\\', '/', $barrier);
    if (! str_starts_with($normalized, $root) || str_contains(substr($normalized, strlen($root)), '/')
        || preg_match('/^'.preg_quote($token, '/').'-monitoring-delivery-[a-f0-9-]{36}$/D', basename($normalized)) !== 1) {
        throw new RuntimeException('Monitoring barriers must remain within parent-owned storage.');
    }
    $ready = $barrier.'-'.$side.'.ready';
    $release = $barrier.'.release';
    $wait = static function () use ($release): void {
        $deadline = microtime(true) + 30;
        while (! is_file($release)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Monitoring parent release timed out.');
            }
            usleep(10_000);
        }
    };
    Notification::fake();
    Http::preventStrayRequests();
    Queue::fake();
    $held = false;
    if ($mode === 'hold_claim') {
        DeviceEventSignalOutbox::retrieved(function (DeviceEventSignalOutbox $row) use ($outboxId, $ready, $wait, &$held): void {
            if (! $held && (int) $row->id === (int) $outboxId && DB::transactionLevel() > 0) {
                $held = true;
                touch($ready);
                $wait();
            }
        });
    } elseif ($mode === 'hold_ticket') {
        ItTicket::created(function () use ($ready, $wait, &$held): void {
            if (! $held && DB::transactionLevel() > 0) {
                $held = true;
                touch($ready);
                $wait();
            }
        });
    } elseif ($mode === 'deliver') {
        DB::connection()->beforeExecuting(function (string $sql) use ($ready): void {
            if (str_contains(strtolower($sql), 'from `device_event_signal_outbox`') && str_contains(strtolower($sql), 'for update')) {
                touch($ready);
            }
        });
    }

    $result = 'delivered';
    if ($mode === 'retry') {
        touch($ready);
        $wait();
        try {
            $app->make(ItMonitoringDeliveryService::class)->retry((int) $outboxId);
            $result = 'retry_requested';
        } catch (DomainException) {
            $result = 'retry_rejected';
        }
    } else {
        $app->call([new DispatchDeviceMonitoringTicket((int) $outboxId), 'handle']);
        if ($mode === 'hold_commit') {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Monitoring commit barrier is not outside its transaction.');
            }
            touch($ready);
            $wait();
        }
    }
    echo json_encode([
        'result' => $result, 'transaction_level' => DB::transactionLevel(),
        'status' => DeviceEventSignalOutbox::query()->findOrFail($outboxId)->it_status,
        'held' => $held,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Monitoring delivery worker failed: '.$exception::class.PHP_EOL);
    exit(1);
}
