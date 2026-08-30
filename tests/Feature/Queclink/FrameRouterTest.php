<?php

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Queclink\Exceptions\IntakeRejected;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use App\Services\Queclink\SerialNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->router = app(FrameRouter::class);
    $this->state = new ConnectionState('192.0.2.10:54321');
    $this->pendingCommandSnapshot = static fn (int $id): ?object => DB::table('queclink_pending_commands')
        ->where('id', $id)
        ->first([
            'raw_command',
            'raw_command_encrypted',
            'serial_number',
            'status',
            'sent_at',
            'sent_session_id',
            'acked_at',
            'ack_response',
        ]);
});

it('makes the first connection identity immutable', function () {
    $state = new ConnectionState('192.0.2.10:54321');
    $state->bind('864696060004173', 10);

    // Repeating the same resolved identity is idempotent.
    $state->bind('864696060004173', 10);

    expect($state->imei)->toBe('864696060004173')
        ->and($state->queclinkDeviceId)->toBe(10)
        ->and($state->isBoundTo('864696060004173'))->toBeTrue()
        ->and($state->isBoundTo('867963069916998'))->toBeFalse()
        ->and(fn () => $state->bind('867963069916998', 11))
        ->toThrow(LogicException::class, 'A Queclink connection identity cannot be rebound.');
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

it('accepts repeated frames from the device already bound to the connection', function () {
    $first = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );
    $boundDeviceId = $this->state->queclinkDeviceId;

    $second = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075752,09D0$',
        $this->state,
    );

    expect($first)->toBe(['+SACK:GTHBD,8020090100,09CF$'])
        ->and($second)->toBe(['+SACK:GTHBD,8020090100,09D0$'])
        ->and($this->state->imei)->toBe('864696060004173')
        ->and($this->state->queclinkDeviceId)->toBe($boundDeviceId)
        ->and($this->state->framesIn)->toBe(2)
        ->and($this->state->framesOut)->toBe(2)
        ->and(QueclinkRawFrame::query()->count())->toBe(4)
        ->and(QueclinkDevice::query()->count())->toBe(1);
});

it('rejects a second IMEI before device state ACK telemetry or command side effects', function () {
    $otherAsset = Asset::factory()->create(['category' => 'vehicle']);
    $otherCanonicalDevice = Device::factory()->tracking()->create([
        'provider' => 'queclink',
        'imei' => '867963069916998',
        'device_uid' => '867963069916998',
        'category' => 'vehicle_tracker',
    ]);
    DeviceAssetLink::query()->create([
        'device_id' => $otherCanonicalDevice->id,
        'asset_id' => $otherAsset->id,
        'link_type' => LinkType::InstalledIn,
        'linked_at' => now(),
    ]);
    $otherDevice = QueclinkDevice::query()->create([
        'imei' => '867963069916998',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $otherCanonicalDevice->id,
    ]);
    $queued = QueclinkPendingCommand::query()->create([
        'queclink_device_id' => $otherDevice->id,
        'imei' => $otherDevice->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gl30meu,1,,,,,0041$',
        'serial_number' => '0041',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);
    $sent = QueclinkPendingCommand::query()->create([
        'queclink_device_id' => $otherDevice->id,
        'imei' => $otherDevice->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gl30meu,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now()->subSecond(),
    ]);

    $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $boundDeviceId = $this->state->queclinkDeviceId;
    $lastActivityAt = $this->state->lastActivityAt;
    $framesIn = $this->state->framesIn;
    $framesOut = $this->state->framesOut;
    $rawFrameCount = QueclinkRawFrame::query()->count();

    expect(fn () => $this->router->handleInbound(
        '+RESP:GTFRI,970204,867963069916998,GL30MEU,11985,10,1,1,42.5,180,118.5,174.7633,-36.8485,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0120$',
        $this->state,
    ))->toThrow(IntakeRejected::class, 'Queclink intake rejected.');

    expect(fn () => $this->router->handleInbound(
        '+ACK:GTRTO,970204,867963069916998,GL30MEU,GPS,0042,20230811125617,0A6A$',
        $this->state,
    ))->toThrow(IntakeRejected::class, 'Queclink intake rejected.');

    $otherDevice->refresh();
    $queued->refresh();
    $sent->refresh();

    expect($this->state->imei)->toBe('864696060004173')
        ->and($this->state->queclinkDeviceId)->toBe($boundDeviceId)
        ->and($this->state->lastActivityAt)->toBe($lastActivityAt)
        ->and($this->state->framesIn)->toBe($framesIn)
        ->and($this->state->framesOut)->toBe($framesOut)
        ->and(QueclinkRawFrame::query()->count())->toBe($rawFrameCount)
        ->and(QueclinkDevice::query()->count())->toBe(2)
        ->and($otherDevice->connection_state)->toBe(QueclinkDevice::CONN_DISCONNECTED)
        ->and($otherDevice->current_session_id)->toBeNull()
        ->and($otherDevice->last_seen_at)->toBeNull()
        ->and($queued->status)->toBe(QueclinkPendingCommand::STATUS_QUEUED)
        ->and($queued->sent_at)->toBeNull()
        ->and($sent->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($sent->acked_at)->toBeNull()
        ->and(FleetTelemetryEvent::query()->count())->toBe(0);
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

it('rejects malformed binary probe frames before persistence', function () {
    expect(fn () => $this->router->handleInbound("\x16\x03\x01\x02\x97\xFF$", $this->state))
        ->toThrow(IntakeRejected::class);

    expect(QueclinkRawFrame::count())->toBe(0)
        ->and(QueclinkDevice::count())->toBe(0);
});

it('rejects server-originated frame types before any intake persistence', function () {
    expect(fn () => $this->router->handleInbound(
        'AT+GTRTO,Secret42,1,0001$',
        $this->state,
    ))->toThrow(IntakeRejected::class);

    expect(fn () => $this->router->handleInbound(
        '+SACK:GTHBD,8020090100,09CF$',
        $this->state,
    ))->toThrow(IntakeRejected::class);

    expect(QueclinkRawFrame::count())->toBe(0)
        ->and(QueclinkDevice::count())->toBe(0);
});

it('rejects overlong persisted protocol fields before resolving a provider device', function () {
    expect(fn () => $this->router->handleInbound(
        '+RESP:GTTOOLONG12,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    ))->toThrow(IntakeRejected::class);

    expect(QueclinkRawFrame::count())->toBe(0)
        ->and(QueclinkDevice::count())->toBe(0);
});

it('does not let an empty delimiter extend the accepted-frame idle deadline', function () {
    $this->state->lastActivityAt = 100.0;

    expect(fn () => $this->router->handleInbound('$', $this->state))
        ->toThrow(IntakeRejected::class)
        ->and($this->state->lastActivityAt)->toBe(100.0)
        ->and(QueclinkRawFrame::count())->toBe(0);
});

it('captures immutable canonical assignment and binding lineage on every stored frame', function () {
    $staff = User::factory()->create();
    $canonicalDevice = Device::factory()->tracking()->create();
    $assignment = DeviceAssignment::query()->create([
        'device_id' => $canonicalDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $staff->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
        'retention_days' => 30,
    ]);
    $bindingUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    QueclinkDevice::query()->create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $canonicalDevice->id,
        'binding_uuid' => $bindingUuid,
    ]);

    $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    QueclinkRawFrame::query()->get()->each(function (QueclinkRawFrame $frame) use (
        $canonicalDevice,
        $assignment,
        $bindingUuid,
    ): void {
        expect($frame->canonical_device_id)->toBe($canonicalDevice->id)
            ->and($frame->device_assignment_id)->toBe($assignment->id)
            ->and($frame->binding_uuid)->toBe($bindingUuid);
    });
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

    $repeatResponses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    expect($repeatResponses)->toBe(['+SACK:GTHBD,8020090100,09CF$']);
});

it('reserializes same-device queued collisions before dispatch', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $commands = collect([1, 2])->map(fn (int $sequence) => QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => "AT+GTRTO=gv500cg,{$sequence},,,,,0042$",
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]));

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $commands = $commands->map->fresh();
    expect($responses)->toBe([
        '+SACK:GTHBD,8020090100,09CF$',
        $commands[0]->raw_command,
        $commands[1]->raw_command,
    ])
        ->and($commands->pluck('status')->unique()->all())->toBe([QueclinkPendingCommand::STATUS_SENT])
        ->and($commands->pluck('serial_number')->unique()->count())->toBe(2);

    $commands->each(function (QueclinkPendingCommand $command): void {
        expect($command->raw_command)->toEndWith(','.$command->serial_number.'$')
            ->and(DB::table('queclink_pending_commands')->where('id', $command->id)->value('raw_command'))
            ->toBe('[encrypted command payload]')
            ->and(DB::table('queclink_pending_commands')->where('id', $command->id)->value('raw_command_encrypted'))
            ->not->toBeNull();
    });
});

it('reserializes a queued collision with a current transmitted command', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $current = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);
    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $queued->refresh();
    expect($current->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->not->toBe('0042')
        ->and($queued->raw_command)->toEndWith(','.$queued->serial_number.'$')
        ->and($responses)->toBe(['+SACK:GTHBD,8020090100,09CF$', $queued->raw_command]);
});

it('never reuses a serial retained by any transmission provenance', function (
    string $previousStatus,
    bool $hasSentAt,
    bool $hasSentSession,
    bool $hasAckedAt,
    bool $hasAckResponse,
    ?int $expiryMinutes,
) {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $previous = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => $previousStatus,
        'sent_at' => $hasSentAt ? now()->subDays(30)->subMinute() : null,
        'sent_session_id' => $hasSentSession ? $this->state->sessionId : null,
        'acked_at' => $hasAckedAt ? now()->subDays(30) : null,
        'ack_response' => $hasAckResponse ? '+ACK:GTRTO,legacy$' : null,
        'expires_at' => $expiryMinutes === null ? null : now()->addMinutes($expiryMinutes),
    ]);
    expect($previous->sent_at !== null)->toBe($hasSentAt)
        ->and($previous->sent_session_id !== null)->toBe($hasSentSession)
        ->and($previous->acked_at !== null)->toBe($hasAckedAt)
        ->and($previous->ack_response !== null)->toBe($hasAckResponse);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $queued->refresh();
    expect($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->not->toBe('0042')
        ->and($queued->raw_command)->toEndWith(','.$queued->serial_number.'$')
        ->and($responses)->toContain($queued->raw_command);
})->with([
    'old acknowledgement with future expiry' => [QueclinkPendingCommand::STATUS_ACKED, true, false, true, true, 60],
    'old acknowledgement with legacy null expiry' => [QueclinkPendingCommand::STATUS_ACKED, true, false, true, true, null],
    'expired row with sent timestamp only' => [QueclinkPendingCommand::STATUS_EXPIRED, true, false, false, false, -1],
    'expired row with sent session only' => [QueclinkPendingCommand::STATUS_EXPIRED, false, true, false, false, null],
    'expired row with old ack timestamp only' => [QueclinkPendingCommand::STATUS_EXPIRED, false, false, true, false, 60],
    'expired row with ack response only' => [QueclinkPendingCommand::STATUS_EXPIRED, false, false, false, true, -1],
    'legacy acknowledged status only' => [QueclinkPendingCommand::STATUS_ACKED, false, false, false, false, null],
    'legacy sent status only' => [QueclinkPendingCommand::STATUS_SENT, false, false, false, false, 60],
]);

it('does not reuse a serial while an expired sent row can still correlate', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now()->subHour(),
        'expires_at' => now()->subMinute(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $queued->refresh();
    expect($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->not->toBe('0042')
        ->and($queued->raw_command)->toEndWith(','.$queued->serial_number.'$');
});

it('never reuses a timed-out transmitted serial for a delayed acknowledgement', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $timedOut = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_EXPIRED,
        'sent_at' => now()->subHours(2),
        'expires_at' => now()->subHour(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $queued->refresh();
    expect($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->not->toBe('0042');

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125618,0A6B$',
        $this->state,
    );

    expect($timedOut->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_EXPIRED)
        ->and($timedOut->fresh()->acked_at)->toBeNull()
        ->and($queued->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->fresh()->acked_at)->toBeNull();
});

it('keeps an expired command serial reserved after same-turn acknowledgement', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $previous = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now()->subHour(),
        'expires_at' => now()->subMinute(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $responses = $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$',
        $this->state,
    );

    $previous->refresh();
    $queued->refresh();
    expect($previous->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($previous->acked_at)->not->toBeNull()
        ->and($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->not->toBe('0042')
        ->and($responses)->toContain($queued->raw_command);

    $originalAckedAt = $previous->acked_at->copy();
    $originalAckResponse = $previous->ack_response;
    $this->travel(2)->seconds();

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125618,0A6B$',
        $this->state,
    );

    expect($previous->fresh()->acked_at)->toEqual($originalAckedAt)
        ->and($previous->fresh()->ack_response)->toBe($originalAckResponse)
        ->and($queued->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->fresh()->acked_at)->toBeNull();
});

it('keeps serial reuse scoped to the current device', function () {
    $other = QueclinkDevice::create([
        'imei' => '867963069916998',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    QueclinkPendingCommand::create([
        'queclink_device_id' => $other->id,
        'imei' => $other->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
    ]);
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $queued->refresh();
    expect($queued->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($queued->serial_number)->toBe('0042')
        ->and($responses)->toContain($queued->raw_command);
});

it('never retransmits a requeued command that retains transmission provenance', function (string $provenanceColumn) {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $provenance = match ($provenanceColumn) {
        'sent_at' => now()->subMinute(),
        'sent_session_id' => $this->state->sessionId,
        'acked_at' => now()->subSeconds(30),
        'ack_response' => '+ACK:GTRTO,legacy$',
    };
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
        $provenanceColumn => $provenance,
    ]);
    $before = ($this->pendingCommandSnapshot)($queued->id);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $after = ($this->pendingCommandSnapshot)($queued->id);
    expect($responses)->toBe(['+SACK:GTHBD,8020090100,09CF$'])
        ->and($after)->toEqual($before)
        ->and($queued->fresh()->raw_command)->toBe('AT+GTRTO=gv500cg,2,,,,,0042$');
})->with([
    'sent timestamp' => 'sent_at',
    'sent session' => 'sent_session_id',
    'ack timestamp' => 'acked_at',
    'ack response' => 'ack_response',
]);

it('leaves an invalid serial binding queued without emitting command bytes', function (string $rawCommand, string $storedSerial) {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => $rawCommand,
        'serial_number' => $storedSerial,
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);
    $before = ($this->pendingCommandSnapshot)($queued->id);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $after = ($this->pendingCommandSnapshot)($queued->id);
    $queued->refresh();
    expect($responses)->toBe(['+SACK:GTHBD,8020090100,09CF$'])
        ->and($after)->toEqual($before)
        ->and($queued->raw_command)->toBe($rawCommand)
        ->and($queued->sent_at)->toBeNull();
})->with([
    'mismatched valid suffix' => ['AT+GTRTO=gv500cg,1,,,,,0043$', '0042'],
    'missing suffix' => ['AT+GTRTO=gv500cg,1$', '0042'],
    'malformed suffix' => ['AT+GTRTO=gv500cg,1,,,,,ZZZZ$', '0042'],
    'lowercase persisted serial' => ['AT+GTRTO=gv500cg,1,,,,,00AF$', '00af'],
    'malformed persisted serial' => ['AT+GTRTO=gv500cg,1,,,,,0042$', 'ZZZZ'],
]);

it('rejects unsafe allocator output without changing the queued command', function (string $allocatorOutput) {
    app()->instance(SerialNumberAllocator::class, new class($allocatorOutput) extends SerialNumberAllocator
    {
        public function __construct(private readonly string $output) {}

        public function nextExcluding(iterable $reserved): string
        {
            return $this->output;
        }
    });
    $this->router = app(FrameRouter::class);

    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);
    $before = ($this->pendingCommandSnapshot)($queued->id);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $after = ($this->pendingCommandSnapshot)($queued->id);
    $queued->refresh();
    expect($responses)->toBe(['+SACK:GTHBD,8020090100,09CF$'])
        ->and($after)->toEqual($before)
        ->and($queued->raw_command)->toBe('AT+GTRTO=gv500cg,2,,,,,0042$')
        ->and($queued->sent_at)->toBeNull();
})->with([
    'malformed serial' => 'ZZZZ',
    'still-reserved serial' => '0042',
    'padded otherwise valid serial' => ' 0043 ',
]);

it('leaves a colliding command queued when no serial can be allocated', function () {
    app()->instance(SerialNumberAllocator::class, new class extends SerialNumberAllocator
    {
        public function nextExcluding(iterable $reserved): string
        {
            throw new RuntimeException('No Queclink command serial number is currently available.');
        }
    });
    $this->router = app(FrameRouter::class);

    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
    ]);
    $queued = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_QUEUED,
    ]);
    $before = ($this->pendingCommandSnapshot)($queued->id);

    $responses = $this->router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
        $this->state,
    );

    $after = ($this->pendingCommandSnapshot)($queued->id);
    $queued->refresh();
    expect($responses)->toBe(['+SACK:GTHBD,8020090100,09CF$'])
        ->and($after)->toEqual($before)
        ->and($queued->raw_command)->toBe('AT+GTRTO=gv500cg,2,,,,,0042$')
        ->and($queued->sent_at)->toBeNull();
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

    $ackFrame = '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$';
    $this->router->handleInbound(
        $ackFrame,
        $this->state,
    );

    $cmd->refresh();
    expect($cmd->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($cmd->acked_at)->not->toBeNull()
        ->and($cmd->ack_response)->toBe($ackFrame);
});

it('keeps acknowledgement correlation scoped to one device', function () {
    $firstDevice = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $secondDevice = QueclinkDevice::create([
        'imei' => '867963069916998',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $firstCommand = QueclinkPendingCommand::create([
        'queclink_device_id' => $firstDevice->id,
        'imei' => $firstDevice->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
    ]);
    $secondCommand = QueclinkPendingCommand::create([
        'queclink_device_id' => $secondDevice->id,
        'imei' => $secondDevice->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$',
        $this->state,
    );

    expect($firstCommand->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($firstCommand->fresh()->acked_at)->not->toBeNull()
        ->and($secondCommand->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($secondCommand->fresh()->acked_at)->toBeNull()
        ->and($secondCommand->fresh()->ack_response)->toBeNull();
});

it('rejects an ambiguous acknowledgement without changing either command', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $commands = collect([1, 2])->map(fn (int $sequence) => QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => "AT+GTRTO=gv500cg,{$sequence},,,,,0042$",
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now()->subSeconds($sequence),
    ]));

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$',
        $this->state,
    );

    $commands->each(function (QueclinkPendingCommand $command): void {
        $command->refresh();
        expect($command->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
            ->and($command->acked_at)->toBeNull()
            ->and($command->ack_response)->toBeNull();
    });
});

it('does not let a delayed acknowledgement cross an acknowledged serial boundary', function () {
    $device = QueclinkDevice::create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
    ]);
    $sent = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,2,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_SENT,
        'sent_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    $acked = QueclinkPendingCommand::create([
        'queclink_device_id' => $device->id,
        'imei' => $device->imei,
        'command_word' => 'GTRTO',
        'raw_command' => 'AT+GTRTO=gv500cg,1,,,,,0042$',
        'serial_number' => '0042',
        'status' => QueclinkPendingCommand::STATUS_ACKED,
        'sent_at' => now()->subMinute(),
        'acked_at' => now()->subSeconds(30),
        'ack_response' => 'original-ack',
        'expires_at' => now()->addHour(),
    ]);
    $originalAckedAt = $acked->acked_at->copy();
    $originalAckResponse = $acked->ack_response;

    $this->router->handleInbound(
        '+ACK:GTRTO,8020090100,864696060004173,GV500CG,GPS,0042,20230811125617,0A6A$',
        $this->state,
    );

    expect($sent->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($sent->fresh()->acked_at)->toBeNull()
        ->and($acked->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($acked->fresh()->acked_at)->toEqual($originalAckedAt)
        ->and($acked->fresh()->ack_response)->toBe($originalAckResponse);
});
