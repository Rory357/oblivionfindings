<?php

use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->router = app(FrameRouter::class);
    $this->state = new ConnectionState('192.0.2.10:54321');
});

it('auto-creates an unknown IMEI in the pending tray and acknowledges a report frame', function () {
    $frame = '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,0.0,0,118.5,117.129306,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0119$';

    $responses = $this->router->handleInbound($frame, $this->state);

    expect($responses)->toBe(['+SACK:0119$']);

    $device = QueclinkDevice::firstWhere('imei', '864696060004173');
    expect($device)->not->toBeNull()
        ->and($device->status)->toBe(QueclinkDevice::STATUS_PENDING)
        ->and($device->connection_state)->toBe(QueclinkDevice::CONN_CONNECTED)
        ->and($device->first_seen_at)->not->toBeNull()
        ->and($device->model_hint)->toBe('GV500CG');

    expect(QueclinkRawFrame::count())->toBe(2)
        ->and(QueclinkRawFrame::first()->parse_ok)->toBeTrue()
        ->and(QueclinkRawFrame::first()->command_word)->toBe('GTFRI');
    expect(QueclinkRawFrame::outbound()->first()->raw_frame)->toBe('+SACK:0119$');
});

it('answers a heartbeat with +SACK even for an unpaired pending device', function () {
    $hb = '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$';

    $responses = $this->router->handleInbound($hb, $this->state);

    expect($responses)->toHaveCount(1)
        ->and($responses[0])->toBe('+SACK:GTHBD,8020090100,09CF$');

    // Both inbound HB and outbound SACK are logged for debug console visibility.
    expect(QueclinkRawFrame::count())->toBe(2);
    expect(QueclinkRawFrame::inbound()->first()->command_word)->toBe('GTHBD');
    expect(QueclinkRawFrame::outbound()->first()->frame_type)->toBe('SACK');
});

it('routes a paired device into the Fleet telemetry pipeline', function () {
    $asset = Asset::factory()->create(['category' => 'vehicle']);
    AssetTracker::create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => '864696060004173',
        'imei' => '864696060004173',
        'status' => 'paired',
        'paired_at' => now(),
    ]);
    QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);

    $frame = '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,42.5,180,118.5,174.7633,-36.8485,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0120$';

    $this->router->handleInbound($frame, $this->state);

    $event = FleetTelemetryEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->asset_id)->toBe($asset->id)
        ->and($event->vendor)->toBe('queclink')
        ->and($event->event_type)->toBe('location_report')
        ->and($event->consent_blocked)->toBeFalse()
        ->and((float) $event->latitude)->toEqualWithDelta(-36.8485, 0.0001)
        ->and((float) $event->longitude)->toEqualWithDelta(174.7633, 0.0001);
});

it('does not ingest telemetry for unpaired devices but still logs the frame', function () {
    $frame = '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,0.0,0,118.5,117.129306,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0119$';

    $this->router->handleInbound($frame, $this->state);

    expect(FleetTelemetryEvent::count())->toBe(0)
        ->and(QueclinkRawFrame::count())->toBe(2);
    expect(QueclinkRawFrame::outbound()->first()->raw_frame)->toBe('+SACK:0119$');
});

it('marks the device disconnected when the connection drops', function () {
    $this->router->handleInbound('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$', $this->state);
    expect(QueclinkDevice::firstWhere('imei', '864696060004173')->connection_state)->toBe(QueclinkDevice::CONN_CONNECTED);

    $this->router->handleDisconnect($this->state);

    expect(QueclinkDevice::firstWhere('imei', '864696060004173')->connection_state)->toBe(QueclinkDevice::CONN_DISCONNECTED);
});

it('dispatches queued commands when a paired device sends a frame', function () {
    $asset = Asset::factory()->create();
    AssetTracker::create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => '864696060004173',
        'imei' => '864696060004173',
        'status' => 'paired',
        'paired_at' => now(),
    ]);
    $qd = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);

    QueclinkPendingCommand::create([
        'queclink_device_id' => $qd->id,
        'imei' => '864696060004173',
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0001$',
        'serial_number' => '0001',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    // SACK + queued command both sent in this turn.
    expect($responses)->toHaveCount(2)
        ->and($responses[0])->toBe('+SACK:GTHBD,8020090100,09CF$')
        ->and($responses[1])->toBe('AT+GTRTO=gv500cg,1,,,,,0001$');

    expect(QueclinkPendingCommand::first()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and(QueclinkPendingCommand::first()->sent_at)->not->toBeNull();
});

it('correlates an inbound +ACK with the matching pending command by serial number', function () {
    $qd = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $cmd = QueclinkPendingCommand::create([
        'queclink_device_id' => $qd->id,
        'imei' => '864696060004173',
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now()->subSeconds(2),
    ]);

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$',
        $this->state,
    );

    $cmd->refresh();
    expect($cmd->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($cmd->acked_at)->not->toBeNull();
});
