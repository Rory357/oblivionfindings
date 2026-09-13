<?php

use App\Domain\It\Services\ItControlRoomHandoffService;
use App\Domain\It\Services\ItFleetDeliveryService;
use App\Domain\It\Services\ItMonitoringDeliveryService;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Jobs\DispatchFleetMonitoringTicket;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignalOutbox;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    $handoffMode = in_array($argv[1] ?? null, ['handoff', 'hold_handoff'], true);
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token
        || ($handoffMode ? (count($argv) !== 8 || ! ctype_digit($argv[6]) || ! ctype_digit($argv[7])) : ! in_array(count($argv), [5, 6], true))
        || ! ctype_digit($argv[2])
        || ! in_array($argv[5] ?? 'device', ['device', 'fleet'], true)
        || ! in_array($argv[1], ['hold_claim', 'hold_ticket', 'hold_commit', 'deliver', 'retry', 'handoff', 'hold_handoff'], true)
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
    $fleet = ($argv[5] ?? 'device') === 'fleet';
    $outboxClass = $fleet ? FleetSignalOutbox::class : DeviceEventSignalOutbox::class;
    $deliveryClass = $fleet ? ItFleetDeliveryService::class : ItMonitoringDeliveryService::class;
    $jobClass = $fleet ? DispatchFleetMonitoringTicket::class : DispatchDeviceMonitoringTicket::class;
    $outboxTable = (new $outboxClass)->getTable();
    $held = false;
    if ($mode === 'hold_claim') {
        $outboxClass::retrieved(function (Model $row) use ($outboxId, $ready, $wait, &$held): void {
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
    } elseif ($mode === 'hold_handoff') {
        ItTicketLink::created(function (ItTicketLink $link) use ($ready, $wait, &$held): void {
            if (! $held && $link->relationship === 'source_alert' && DB::transactionLevel() > 0) {
                $held = true;
                touch($ready);
                $wait();
            }
        });
    } elseif ($mode === 'handoff') {
        DB::connection()->beforeExecuting(function (string $sql) use ($ready): void {
            if (str_contains(strtolower($sql), 'from `control_room_alerts`') && str_contains(strtolower($sql), 'for update')) {
                touch($ready);
            }
        });
    } elseif ($mode === 'deliver') {
        DB::connection()->beforeExecuting(function (string $sql) use ($ready, $outboxTable): void {
            if (str_contains(strtolower($sql), 'from `'.$outboxTable.'`') && str_contains(strtolower($sql), 'for update')) {
                touch($ready);
            }
        });
    }

    $result = 'delivered';
    if ($handoffMode) {
        $outbox = $outboxClass::query()->findOrFail($outboxId);
        $alert = ControlRoomAlert::query()->findOrFail($outbox->it_scope['alert_id'] ?? $outbox->it_scope['correlated_alert_id']);
        $actor = User::query()->findOrFail($argv[6]);
        $target = ItTicket::query()->findOrFail($argv[7]);
        $service = app(ItControlRoomHandoffService::class);
        $version = $service->preview($alert, $actor)['alert_version'];
        try {
            $result = $service->execute($alert, $actor, ['viewer_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(),
                'alert_version' => $version, 'action' => 'link', 'ticket_id' => $target->id,
                'ticket_version' => $target->lock_version, 'reason' => 'Isolated competing technical handoff.'])['data']['outcome'];
        } catch (ValidationException) {
            $result = 'handoff_rejected';
        }
    } elseif ($mode === 'retry') {
        touch($ready);
        $wait();
        try {
            $app->make($deliveryClass)->retry((int) $outboxId);
            $result = 'retry_requested';
        } catch (DomainException) {
            $result = 'retry_rejected';
        }
    } else {
        $app->call([new $jobClass((int) $outboxId), 'handle']);
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
        'status' => $outboxClass::query()->findOrFail($outboxId)->it_status,
        'held' => $held,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Monitoring delivery worker failed: '.$exception::class.PHP_EOL);
    // Metadata only: no exception message, SQL bindings or submitted content.
    fwrite(STDERR, json_encode(['mode' => $mode ?? null,
        'file' => $exception->getFile(), 'line' => $exception->getLine(),
        'frames' => array_map(static fn (array $frame): array => array_intersect_key($frame,
            array_flip(['file', 'line', 'class', 'function'])), array_slice($exception->getTrace(), 0, 8)),
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
