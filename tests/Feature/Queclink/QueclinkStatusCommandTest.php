<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Site;
use App\Services\Queclink\Listener\QueclinkListenerRuntimeProbe;
use Illuminate\Support\Facades\Artisan;

it('prints the last frame age without treating the timestamp as a string', function () {
    QueclinkRawFrame::create([
        'imei' => '867963069916998',
        'direction' => 'inbound',
        'frame_type' => 'RESP',
        'command_word' => 'GTFRI',
        'raw_frame' => '+RESP:GTFRI,867963069916998,,,,20260518031500,0000$',
        'parse_ok' => true,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('queclink:status')
        ->expectsOutputToContain('Last frame:')
        ->assertExitCode(0);
});

it('fails closed when live listener evidence is requested but unavailable', function () {
    $this->mock(QueclinkListenerRuntimeProbe::class)
        ->shouldReceive('serviceState')
        ->once()
        ->andReturn('inactive');

    $this->artisan('queclink:status --require-live')
        ->expectsOutputToContain('Live evidence:  unverified')
        ->expectsOutputToContain('listener_not_active')
        ->assertExitCode(1);
});

it('emits value-free verified evidence for a supervised listener and current canonical tracker frame', function () {
    $this->mock(QueclinkListenerRuntimeProbe::class)
        ->shouldReceive('serviceState')
        ->twice()
        ->andReturn('active');

    $site = Site::factory()->create();
    $device = Device::factory()->tracking()->create(['provider' => 'queclink']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);
    $tracker = QueclinkDevice::query()->create([
        'device_id' => $device->id,
        'imei' => '860000000000901',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'connection_state' => QueclinkDevice::CONN_CONNECTED,
    ]);
    QueclinkRawFrame::query()->create([
        'queclink_device_id' => $tracker->id,
        'imei' => $tracker->imei,
        'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
        'frame_type' => QueclinkRawFrame::FRAME_RESP,
        'command_word' => 'GTHBD',
        'raw_frame' => '+RESP:GTHBD,860000000000901,0100,0001$',
        'parse_ok' => true,
        'created_at' => now()->subSeconds(30),
    ]);

    $exitCode = Artisan::call('queclink:status', [
        '--evidence-json' => true,
        '--max-frame-age' => 300,
    ]);
    $output = Artisan::output();
    $evidence = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($evidence['acceptance']['state'])->toBe('verified')
        ->and($evidence['acceptance']['canonical_paired_trackers'])->toBeGreaterThanOrEqual(1)
        ->and($evidence['acceptance']['fresh_trackers_observed'])
        ->toBe($evidence['acceptance']['canonical_paired_trackers'])
        ->and($evidence['acceptance']['canonical_roster_fingerprint'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($evidence['acceptance']['frame_execution_fingerprint'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($evidence['acceptance']['frame_window']['oldest_observed_at'])->not->toBeNull()
        ->and($evidence['acceptance']['frame_window']['newest_observed_at'])->not->toBeNull()
        ->and($output)->not->toContain('860000000000901')
        ->and($evidence['acceptance'])->not->toHaveKeys(['site_id', 'site_ids', 'device_id', 'device_ids']);

    $this->travel(1)->seconds();

    QueclinkRawFrame::query()->create([
        'queclink_device_id' => $tracker->id,
        'imei' => $tracker->imei,
        'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
        'frame_type' => QueclinkRawFrame::FRAME_RESP,
        'command_word' => 'GTHBD',
        'raw_frame' => '+RESP:GTHBD,860000000000901,0100,0002$',
        'parse_ok' => true,
        'created_at' => now(),
    ]);
    Artisan::call('queclink:status', [
        '--evidence-json' => true,
        '--max-frame-age' => 300,
    ]);
    $advanced = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

    expect($advanced['acceptance']['canonical_roster_fingerprint'])
        ->toBe($evidence['acceptance']['canonical_roster_fingerprint'])
        ->and($advanced['acceptance']['frame_execution_fingerprint'])
        ->not->toBe($evidence['acceptance']['frame_execution_fingerprint'])
        ->and(strtotime($advanced['acceptance']['frame_window']['newest_observed_at']))
        ->toBeGreaterThan(strtotime($evidence['acceptance']['frame_window']['newest_observed_at']));
});

it('fails closed when any canonical paired tracker lacks current listener evidence', function () {
    $this->mock(QueclinkListenerRuntimeProbe::class)
        ->shouldReceive('serviceState')
        ->once()
        ->andReturn('active');

    $site = Site::factory()->create();
    foreach (['860000000000902', '860000000000903'] as $index => $imei) {
        $device = Device::factory()->tracking()->create(['provider' => 'queclink']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $tracker = QueclinkDevice::query()->create([
            'device_id' => $device->id,
            'imei' => $imei,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);
        if ($index === 0) {
            QueclinkRawFrame::query()->create([
                'queclink_device_id' => $tracker->id,
                'imei' => $tracker->imei,
                'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
                'frame_type' => QueclinkRawFrame::FRAME_RESP,
                'command_word' => 'GTHBD',
                'raw_frame' => '+RESP:GTHBD,860000000000902,0100,0001$',
                'parse_ok' => true,
                'created_at' => now(),
            ]);
        }
    }

    $exitCode = Artisan::call('queclink:status', [
        '--evidence-json' => true,
        '--max-frame-age' => 300,
    ]);
    $output = Artisan::output();
    $evidence = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($evidence['acceptance']['state'])->toBe('unverified')
        ->and($evidence['acceptance']['canonical_paired_trackers'])->toBe(2)
        ->and($evidence['acceptance']['fresh_trackers_observed'])->toBe(1)
        ->and($evidence['acceptance']['reason_codes'])->toContain('canonical_tracker_evidence_incomplete')
        ->and($output)->not->toContain('860000000000902', '860000000000903');
});

it('rejects an unsafe live-evidence freshness window', function () {
    $this->artisan('queclink:status --require-live --max-frame-age=59')
        ->expectsOutputToContain('Max frame age must be an integer between 60 and 86400 seconds.')
        ->assertExitCode(2);
});
