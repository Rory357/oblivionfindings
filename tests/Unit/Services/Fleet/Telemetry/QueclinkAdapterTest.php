<?php

use App\Services\Fleet\Telemetry\QueclinkAdapter;

it('preserves parser safety and power metadata in normalized telemetry', function () {
    $normalized = (new QueclinkAdapter)->normalize([
        'imei' => '861106050000000',
        'alarm' => 'man_down',
        'event_type' => 'man_down',
        'sos_flag' => true,
        'battery' => 15,
        'external_power' => true,
        'power_event' => 'power_on',
    ]);

    expect($normalized['device_uid'])->toBe('861106050000000')
        ->and($normalized['battery_pct'])->toBe(15)
        ->and($normalized['external_power'])->toBeTrue()
        ->and($normalized['sos_flag'])->toBeTrue()
        ->and($normalized['raw_payload']['power_event'])->toBe('power_on');
});
