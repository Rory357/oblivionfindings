<?php

use App\Services\Queclink\ConfigurationSnapshotService;

test('configuration snapshot maps every supported gl30 remote section by writable field names', function () {
    $raw = implode(',', [
        'BSI', '98', '4100', '1',
        'SRI', '3', '0', '1', 'oblivionfindings.com', '8090', 'backup.example.co.nz', '8091', '', '5', '1', '0', '30', '0', '',
        'CFG', '', 'GL30MEU', '150', '08E3', '006F', '1', '30', '', '0', '1200', '', '1', '', '', '', '1', '1', '0000', '', '', '20', '1', '', '1', '2', '1', '0',
        'PIN', '1', '1234', '', '', '', '', '',
        'DOG', '1', '', '1', '0130', '', '1', '', '0', '', '', '60',
        'TMA', '+', '12', '0', '1', '20260519000000', '', '', '', '',
        'NMD', '1', '1', '3', '3', '2', '1440', '2', '', '', '0', '', '', '', '',
        'PDS', '1', '00000011', '', '', '', '', '', '',
        'GEO', '0', '3', '175.241598', '-37.723573', '150',
        'BTS', '1', '', 'GL30MEU_BT', '', '0', '0', '', '', '', '', '', '', '', '1000', '0', '', '', '', '', '', '',
        'WFI', '0', '10', '0', '2', '10', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
        'BID', '', '1', '4', '000A', '', '', '', '', '', '', '', '30', '', '', '123456', '11BBCCDDEEFF', '', '', '', '', '', '', '', '', '',
        'UPC', '0', '30', '0', '1', '0', 'http://www.xxx.com/configure.ini', '1', '', '1', '1234578', '', '',
        'WLT', '1', '1', '2', '13813888888', '13913999999', '', '', '', '',
        'FVR', 'TEST', '0001', '', '', '', '', '', '', '', '', '', '',
    ]);

    $parsed = (new ConfigurationSnapshotService)->parseConfigurationText($raw);

    expect($parsed['summary']['server']['main_host'])->toBe('oblivionfindings.com')
        ->and($parsed['summary']['global'])->not->toHaveKey('new_password')
        ->and($parsed['summary']['global']['battery_low_percentage'])->toBe('20')
        ->and($parsed['summary']['pin']['auto_unlock_pin'])->toBe('1')
        ->and($parsed['summary']['dog']['send_failure_timeout'])->toBe('60')
        ->and($parsed['summary']['time']['hour_offset'])->toBe('12')
        ->and($parsed['summary']['non_movement']['rest_send_interval'])->toBe('1440')
        ->and($parsed['summary']['power']['mask'])->toBe('00000011')
        ->and($parsed['summary']['geofences'][0]['radius'])->toBe('150')
        ->and($parsed['summary']['bluetooth']['bluetooth_name'])->toBe('GL30MEU_BT')
        ->and($parsed['summary']['wifi']['scan_interval'])->toBe('10')
        ->and($parsed['summary']['beacons']['mac_list'][0])->toBe('11BBCCDDEEFF')
        ->and($parsed['summary']['firmware_update']['download_url'])->toBe('http://www.xxx.com/configure.ini')
        ->and($parsed['summary']['allowlist']['phone_numbers'])->toBe(['13813888888', '13913999999'])
        ->and($parsed['summary']['firmware_version']['configuration_name'])->toBe('TEST');
});
