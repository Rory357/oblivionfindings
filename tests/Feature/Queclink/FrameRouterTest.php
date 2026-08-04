<?php

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    $canonicalDevice = Device::factory()->tracking()->create([
        'provider' => 'queclink',
        'imei' => '864696060004173',
        'device_uid' => '864696060004173',
        'category' => 'vehicle_tracker',
    ]);
    DeviceAssetLink::create([
        'device_id' => $canonicalDevice->id,
        'asset_id' => $asset->id,
        'link_type' => LinkType::InstalledIn,
        'linked_at' => now(),
    ]);
    QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $canonicalDevice->id,
    ]);

    $frame = '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,42.5,180,118.5,174.7633,-36.8485,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0120$';

    $this->router->handleInbound($frame, $this->state);

    $event = FleetTelemetryEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->asset_id)->toBe($asset->id)
        ->and($event->asset_tracker_id)->toBeNull()
        ->and($event->device_id)->toBe($canonicalDevice->id)
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

it('does not ingest a paired provider projection without its canonical Device binding', function () {
    QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => null,
    ]);

    $responses = $this->router->handleInbound(
        '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,42.5,180,118.5,174.7633,-36.8485,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0120$',
        $this->state,
    );

    expect($responses)->toBe(['+SACK:0120$'])
        ->and(FleetTelemetryEvent::count())->toBe(0)
        ->and(QueclinkRawFrame::inbound()->count())->toBe(1);
});

it('stores binary probe frames as hex text instead of throwing while logging them', function () {
    $responses = $this->router->handleInbound("\x16\x03\x01\x02\x97\xFF$", $this->state);

    expect($responses)->toBe([]);

    $rawFrame = QueclinkRawFrame::first();
    expect($rawFrame)->not->toBeNull()
        ->and($rawFrame->parse_ok)->toBeFalse()
        ->and($rawFrame->parse_error)->toContain('HEX frame detected')
        ->and($rawFrame->raw_frame)->toBe('0x1603010297FF24');
});

it('protects inbound configuration bytes while retaining an internal configuration projection', function () {
    $raw = '+RESP:GTALM,970204,867963069916998,GL30MEU,1,1,CFG,NewPass99,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,20260518031500,0A10$';

    $this->router->handleInbound($raw, $this->state);

    $device = QueclinkDevice::query()->where('imei', '867963069916998')->firstOrFail();
    $frame = QueclinkRawFrame::query()->inbound()->where('command_word', 'GTALM')->sole();
    $stored = DB::table('queclink_raw_frames')->where('id', $frame->id)->first();
    expect($stored)->not->toBeNull()
        ->and((string) $stored->raw_frame)->toBe('[encrypted sensitive frame]')
        ->and((string) $stored->encrypted_raw_frame)->not->toContain('NewPass99')
        ->and((string) $stored->parsed_payload)->not->toContain('NewPass99')
        ->and((string) $stored->encrypted_parsed_payload)->not->toContain('NewPass99')
        ->and($frame->toArray())->not->toHaveKeys([
            'raw_frame',
            'encrypted_raw_frame',
            'parsed_payload',
            'encrypted_parsed_payload',
        ])
        ->and($frame->raw_frame)->toBe($raw)
        ->and(data_get($frame->protectedParsedPayload(), 'config_text'))->toContain('NewPass99');

    $snapshot = app(ConfigurationSnapshotService::class)->latestForDevice($device);
    expect($snapshot['available'])->toBeTrue()
        ->and((array) data_get($snapshot, 'summary.global'))->not->toHaveKey('new_password');
});

it('marks the device disconnected when the connection drops', function () {
    $this->router->handleInbound('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$', $this->state);
    expect(QueclinkDevice::firstWhere('imei', '864696060004173')->connection_state)->toBe(QueclinkDevice::CONN_CONNECTED);

    $this->router->handleDisconnect($this->state);

    expect(QueclinkDevice::firstWhere('imei', '864696060004173')->connection_state)->toBe(QueclinkDevice::CONN_DISCONNECTED);
});

it('dispatches queued commands when a paired device sends a frame', function () {
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
