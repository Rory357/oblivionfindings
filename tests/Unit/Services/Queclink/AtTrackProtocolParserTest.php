<?php

use App\Services\Queclink\AckBuilder;
use App\Services\Queclink\AtTrackProtocolParser;

beforeEach(function () {
    $this->parser = new AtTrackProtocolParser;
    $this->acks = new AckBuilder;
});

// ─── Frame splitting ─────────────────────────────────────────────────

it('splits multiple complete frames from a single buffer flush', function () {
    $buffer = '';
    $incoming = '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$'.
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075752,09D0$';
    $frames = $this->parser->splitFrames($incoming, $buffer);

    expect($frames)->toHaveCount(2)
        ->and($buffer)->toBe('');
});

it('buffers a partial trailing frame for the next read', function () {
    $buffer = '';
    $first = $this->parser->splitFrames('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$+RESP:GTFRI,802', $buffer);
    expect($first)->toHaveCount(1)
        ->and($buffer)->toBe('+RESP:GTFRI,802');

    $second = $this->parser->splitFrames('0090100,864696060004173,GV500CG,11985,10,1,1,0.0,0,0,1.0,1.0,20230811075800,0460,0001,DF5C,02A90902,01,15,0.0,20230811075801,09D0$', $buffer);
    expect($second)->toHaveCount(1)
        ->and($buffer)->toBe('');
});

it('discards empty or whitespace-only frames', function () {
    $buffer = '';
    $frames = $this->parser->splitFrames("\$\r\n\$", $buffer);
    expect($frames)->toBeEmpty();
});

// ─── Heartbeat parsing + ACK building ────────────────────────────────

it('parses a heartbeat frame and IMEI', function () {
    $frame = $this->parser->parse('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$');

    expect($frame->isValid())->toBeTrue()
        ->and($frame->frameType)->toBe('RESP')
        ->and($frame->commandWord)->toBe('GTHBD')
        ->and($frame->imei)->toBe('864696060004173')
        ->and($frame->protocolVersion)->toBe('8020090100')
        ->and($frame->countNumber)->toBe('09CF')
        ->and($frame->isHeartbeat())->toBeTrue();
});

it('builds a +SACK:GTHBD response that echoes the heartbeat count number', function () {
    $hb = $this->parser->parse('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$');
    $ack = $this->acks->heartbeatAck($hb);

    expect($ack)->toBe('+SACK:GTHBD,8020090100,09CF$');
});

it('builds a generic +SACK response for non-heartbeat device messages', function () {
    $fri = $this->parser->parse('+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,0.0,0,118.5,117.129306,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0119$');

    expect($this->acks->heartbeatAck($fri))->toBeNull()
        ->and($this->acks->serverAck($fri))->toBe('+SACK:0119$');
});

// ─── Generic Location Report (GTFRI) ─────────────────────────────────

it('extracts position fields from a GTFRI frame matching the PDF example', function () {
    $frame = $this->parser->parse('+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,0.0,0,118.5,117.129306,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0119$');

    expect($frame->isValid())->toBeTrue()
        ->and($frame->commandWord)->toBe('GTFRI')
        ->and($frame->payload['imei'])->toBe('864696060004173')
        ->and($frame->payload['lon'])->toBe(117.129306)
        ->and($frame->payload['lat'])->toBe(31.839292)
        ->and($frame->payload['speed'])->toBe(0.0)
        ->and($frame->payload['gps_time'])->toBe('2023-08-08T02:25:09Z')
        ->and($frame->payload['event_type'])->toBe('location_report');
});

it('extracts configuration text from a GTALM readback frame', function () {
    $frame = $this->parser->parse('+RESP:GTALM,970204,861106050000000,GL30MEU,1,1,BSI,,,,,,,00,0,0,0,0,SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,20260518031500,0A10$');

    expect($frame->isValid())->toBeTrue()
        ->and($frame->commandWord)->toBe('GTALM')
        ->and($frame->payload['event_type'])->toBe('configuration_report')
        ->and($frame->payload['config_total_packets'])->toBe(1)
        ->and($frame->payload['config_current_packet'])->toBe(1)
        ->and($frame->payload['config_text'])->toContain('SRI,3,0,1,oblivionfindings.com,8090')
        ->and($frame->payload['send_time'])->toBe('2026-05-18T03:15:00Z');
});

// ─── SOS / panic / alarms ────────────────────────────────────────────

it('flags GTSOS as a critical SOS event', function () {
    $frame = $this->parser->parse('+RESP:GTSOS,8020090100,864696060004173,GV500CG,,00,1,1,0.0,0,124.6,117.129278,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0460$');

    expect($frame->payload['alarm'])->toBe('sos')
        ->and($frame->payload['event_type'])->toBe('vehicle_sos');
});

it('flags GTTOW as a tamper event', function () {
    $frame = $this->parser->parse('+RESP:GTTOW,8020090100,864696060004173,GV500CG,,00,1,1,0.0,0,124.6,117.129278,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0461$');

    expect($frame->payload['alarm'])->toBe('tamper')
        ->and($frame->payload['event_type'])->toBe('tamper');
});

it('flags GTMAN (personal-tracker man-down) as a critical SOS event', function () {
    $frame = $this->parser->parse('+RESP:GTMAN,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,0.0,0.0,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0462$');

    expect($frame->payload['alarm'])->toBe('man_down')
        ->and($frame->payload['sos_flag'])->toBeTrue();
});

it('extracts battery voltage and percentage from a GL30 GTFRI health tail', function () {
    // Real frame captured from a live GL30MEU pendant (IMEI redacted).
    // Trailing fields after cell_id 0017E102 include current mode, CSQ,
    // voltage=4191mV, battery=100%, and movement/quality fields. GTFRI does
    // not carry charge state; that arrives via GTBTC/GTSTC.
    $frame = $this->parser->parse(
        '+RESP:GTFRI,970204,867963069916998,,0,0,1,0,4.3,145,-105.0,'.
        '175.241197,-37.723363,20260518224343,0530,0001,A310,0017E102,'.
        '24,0,4191,100,1,,,20260518233812,085F$'
    );

    expect($frame->isValid())->toBeTrue()
        ->and($frame->commandWord)->toBe('GTFRI')
        ->and($frame->payload['event_type'])->toBe('location_report')
        ->and($frame->payload['battery'])->toBe(100.0)
        ->and($frame->payload['battery_voltage_mv'])->toBe(4191)
        ->and($frame->payload['charging_status'])->toBeNull();
});

it('normalizes GL30 battery-starts-charging events', function () {
    $frame = $this->parser->parse(
        '+RESP:GTBTC,970204,867963069916998,GL30MEU,4066,95,0530,0001,A310,0017E102,20,0,20260519022646,098F$'
    );

    expect($frame->isValid())->toBeTrue()
        ->and($frame->commandWord)->toBe('GTBTC')
        ->and($frame->payload['event_type'])->toBe('charging_started')
        ->and($frame->payload['battery'])->toBe(95.0)
        ->and($frame->payload['battery_voltage_mv'])->toBe(4066)
        ->and($frame->payload['charging_status'])->toBe('charging')
        ->and($frame->payload['external_power'])->toBeTrue()
        ->and($frame->payload['send_time'])->toBe('2026-05-19T02:26:46Z');
});

it('normalizes GL30 battery-stops-charging events', function () {
    $frame = $this->parser->parse(
        '+RESP:GTSTC,970204,867963069916998,GL30MEU,0,4066,95,0530,0001,A310,0017E102,20,0,20260519022632,098E$'
    );

    expect($frame->isValid())->toBeTrue()
        ->and($frame->commandWord)->toBe('GTSTC')
        ->and($frame->payload['event_type'])->toBe('charging_stopped')
        ->and($frame->payload['charge_event_state'])->toBe(0)
        ->and($frame->payload['battery'])->toBe(95.0)
        ->and($frame->payload['battery_voltage_mv'])->toBe(4066)
        ->and($frame->payload['charging_status'])->toBe('stopped_charging')
        ->and($frame->payload['external_power'])->toBeFalse()
        ->and($frame->payload['send_time'])->toBe('2026-05-19T02:26:32Z');
});

it('does not invent battery values from a GTFRI frame with no voltage in the trail', function () {
    // Same skeleton but mask=01 (only satellites appended); no voltage.
    $frame = $this->parser->parse(
        '+RESP:GTFRI,970204,867963069916998,,0,0,1,0,4.3,145,-105.0,'.
        '175.241197,-37.723363,20260518224343,0530,0001,A310,0017E102,'.
        '01,15,0.0,20260518233812,085F$'
    );

    expect($frame->isValid())->toBeTrue()
        ->and($frame->payload['battery'])->toBeNull()
        ->and($frame->payload['battery_voltage_mv'])->toBeNull()
        ->and($frame->payload['charging_status'])->toBeNull();
});

it('flags GTBPL as a battery_low event with the battery percentage in field 4', function () {
    $frame = $this->parser->parse('+RESP:GTBPL,970204,861106050000000,GL30MEU,15,00,1,1,0.0,0,0.0,0.0,0.0,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0463$');

    expect($frame->payload['alarm'])->toBe('battery_low')
        ->and($frame->payload['event_type'])->toBe('battery_low')
        ->and($frame->payload['battery'])->toBe(15.0);
});

it('normalizes GL30 low battery with percentage and location', function () {
    $frame = $this->parser->parse('+RESP:GTBPL,970204,861106050000000,GL30MEU,15,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0463$');

    expect($frame->payload['event_type'])->toBe('battery_low')
        ->and($frame->payload['battery'])->toBe(15.0)
        ->and($frame->payload['alarm'])->toBe('battery_low')
        ->and($frame->payload['lon'])->toBe(175.241655)
        ->and($frame->payload['lat'])->toBe(-37.723657);
});

it('normalizes GL30 SOS and man down as critical safety events', function () {
    $sos = $this->parser->parse('+RESP:GTSOS,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0464$');
    $manDown = $this->parser->parse('+RESP:GTMAN,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0465$');

    expect($sos->payload['sos_flag'])->toBeTrue()
        ->and($sos->payload['event_type'])->toBe('vehicle_sos')
        ->and($manDown->payload['sos_flag'])->toBeTrue()
        ->and($manDown->payload['event_type'])->toBe('man_down');
});

it('normalizes GL30 power events for device health metadata', function () {
    $powerOn = $this->parser->parse('+RESP:GTPNA,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0466$');
    $powerOff = $this->parser->parse('+RESP:GTPFA,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0467$');

    expect($powerOn->payload['power_event'])->toBe('power_on')
        ->and($powerOn->payload['charging_status'])->toBeNull()
        ->and($powerOff->payload['power_event'])->toBe('power_off')
        ->and($powerOff->payload['charging_status'])->toBeNull();
});

// ─── Ignition events ─────────────────────────────────────────────────

it('flags GTVGN as ignition_on with ignition=true', function () {
    $frame = $this->parser->parse('+RESP:GTVGN,8020090100,864696060004173,GV500CG,,00,1,1,0.0,0,124.6,117.129278,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0464$');

    expect($frame->payload['ignition'])->toBeTrue()
        ->and($frame->payload['event_type'])->toBe('ignition_on');
});

it('flags GTVGF as ignition_off with ignition=false', function () {
    $frame = $this->parser->parse('+RESP:GTVGF,8020090100,864696060004173,GV500CG,,01,1,1,0.0,0,124.6,117.129278,31.839292,20230808022509,0460,0001,DF5C,02A90902,01,15,0.0,20230808022510,0465$');

    expect($frame->payload['ignition'])->toBeFalse()
        ->and($frame->payload['event_type'])->toBe('ignition_off');
});

// ─── Edge cases ──────────────────────────────────────────────────────

it('rejects frames missing the $ terminator', function () {
    $frame = $this->parser->parse('+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF');
    expect($frame->isValid())->toBeFalse()
        ->and($frame->parseError)->toContain('did not end with');
});

it('rejects frames with non-numeric IMEI in field 2', function () {
    $frame = $this->parser->parse('+RESP:GTHBD,8020090100,NOTANIMEI,GV500CG,20230811075652,09CF$');
    expect($frame->isValid())->toBeFalse();
});

it('rejects HEX-formatted frames with a clear error', function () {
    $frame = $this->parser->parse(hex2bin('2b41434b016f268020090302030c562e600600614c0300ffff07e80b0f03241200de7ee00d0a24'));
    expect($frame->parseError)->toContain('HEX frame');
});

it('parses a server-issued ACK frame with the option field', function () {
    $frame = $this->parser->parse('+ACK:GTGEO,8020090100,864696060004173,GV500CG,0,FFFF,20230811125617,0A6A$');
    expect($frame->isValid())->toBeTrue()
        ->and($frame->frameType)->toBe('ACK')
        ->and($frame->commandWord)->toBe('GTGEO')
        ->and($frame->imei)->toBe('864696060004173');
});

it('treats GL-series 6-char protocol version as valid', function () {
    $frame = $this->parser->parse('+ACK:GTBSI,970204,861106050000000,GL30MEU,FFFF,20230526103430,09D8$');
    expect($frame->isValid())->toBeTrue()
        ->and($frame->protocolVersion)->toBe('970204');
});
