<?php

use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\SerialNumberAllocator;

test('gl30m family commands use the GL30MEU factory password', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '0001';
        }
    };

    $command = (new CommandBuilder($serials))->requestLocation(CommandBuilder::FAMILY_GL30M);

    expect($command['raw'])->toBe('AT+GTRTO=gl30,1,,,,,,0001$');
});

test('gl30m read configuration command requests all device settings', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AA';
        }
    };

    $command = (new CommandBuilder($serials))->readConfiguration(CommandBuilder::FAMILY_GL30M);

    expect($command['command_word'])->toBe('GTRTO')
        ->and($command['raw'])->toBe('AT+GTRTO=gl30,2,,,,,,00AA$');
});

test('gl30m read configuration command can request a single settings section', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AB';
        }
    };

    $command = (new CommandBuilder($serials))->readConfiguration(CommandBuilder::FAMILY_GL30M, 'sri');

    expect($command['raw'])->toBe('AT+GTRTO=gl30,2,SRI,,,,,00AB$');
});

test('generic builder preserves command words that start with t', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AF';
        }
    };

    $command = (new CommandBuilder($serials))->buildAny(CommandBuilder::FAMILY_GL30M, 'TMA', ['+', 12, 0, 0, '20260519000000', '', '', '', '']);

    expect($command['command_word'])->toBe('GTTMA')
        ->and($command['raw'])->toBe('AT+GTTMA=gl30,+,12,0,0,20260519000000,,,,,00AF$');
});

test('gl30m server registration command uses the live listener settings shape', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AC';
        }
    };

    $command = (new CommandBuilder($serials))->gl30ServerRegistration([
        'report_mode' => 3,
        'manual_netreg' => 0,
        'buffer_mode' => 1,
        'main_host' => 'oblivionfindings.com',
        'main_port' => 8090,
        'backup_host' => 'oblivionfindings.com',
        'backup_port' => 8090,
        'heartbeat_interval_minutes' => 5,
        'sack_enable' => 1,
        'sms_ack_enable' => 0,
        'psm_network_hold_time_seconds' => 30,
        'protocol_format' => 0,
    ]);

    expect($command['command_word'])->toBe('GTSRI')
        ->and($command['raw'])->toBe('AT+GTSRI=gl30,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,0,00AC$');
});

test('gl30m global configuration command can apply continuous GNSS testing settings', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AD';
        }
    };

    $command = (new CommandBuilder($serials))->gl30GlobalConfiguration([
        'device_name' => 'GL30MEU',
        'gnss_timeout_seconds' => 150,
        'event_mask' => '08E3',
        'report_item_mask' => '006F',
        'mode_selection' => 1,
        'continuous_send_interval_seconds' => 30,
        'start_mode' => 0,
        'specified_time_of_day' => '1200',
        'wakeup_interval_hours' => 1,
        'gnss_enable' => 1,
        'agps_mode' => 1,
        'gsm_report' => '0000',
        'battery_low_percentage' => 10,
        'function_button_mode' => 1,
        'sos_report_mode' => 1,
        'wifi_report' => 2,
        'led_on' => 1,
        'charge_standby_mode' => 0,
    ]);

    expect($command['command_word'])->toBe('GTCFG')
        ->and($command['raw'])->toBe('AT+GTCFG=gl30,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,00AD$');
});

test('gl30m resident safety profile enables panic sos and battery threshold', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '00AE';
        }
    };

    $command = (new CommandBuilder($serials))->gl30ResidentSafetyProfile();

    expect($command['command_word'])->toBe('GTCFG')
        ->and($command['raw'])->toBe('AT+GTCFG=gl30,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,20,1,,1,2,1,0,00AE$');
});

test('gl30m builder covers writable remote configuration commands from the v204 protocol', function (string $method, array $arguments, string $commandWord, string $raw) {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return 'FFFF';
        }
    };

    $builder = new CommandBuilder($serials);
    $command = $method === 'gl30Geo'
        ? $builder->gl30Geo($arguments['slot'], $arguments['settings'])
        : $builder->{$method}($arguments);

    expect($command['command_word'])->toBe($commandWord)
        ->and($command['raw'])->toBe($raw);
})->with([
    'pin' => [
        'gl30Pin',
        ['auto_unlock_pin' => 0],
        'GTPIN',
        'AT+GTPIN=gl30,0,,,,,,,FFFF$',
    ],
    'dog' => [
        'gl30Dog',
        ['mode' => 1, 'reboot_interval' => 1, 'reboot_time' => '0130', 'report_before_reboot' => 1, 'unit' => 0],
        'GTDOG',
        'AT+GTDOG=gl30,1,,1,0130,,1,,0,,,,FFFF$',
    ],
    'tma' => [
        'gl30Tma',
        ['sign' => '+', 'hour_offset' => 7, 'minute_offset' => 0, 'daylight_saving' => 1, 'utc_time' => '20230312032345'],
        'GTTMA',
        'AT+GTTMA=gl30,+,7,0,1,20230312032345,,,,,FFFF$',
    ],
    'nmd' => [
        'gl30Nmd',
        ['sensor_enable' => 1, 'mode' => 1, 'non_movement_duration' => 3, 'movement_duration' => 3, 'movement_threshold' => 2, 'rest_send_interval' => 1440, 'report_mode' => 2, 'safe_check' => 0],
        'GTNMD',
        'AT+GTNMD=gl30,1,1,3,3,2,1440,2,,,0,,,,,FFFF$',
    ],
    'pds' => [
        'gl30Pds',
        ['mode' => 1, 'mask' => '00000011'],
        'GTPDS',
        'AT+GTPDS=gl30,1,00000011,,,,,,,FFFF$',
    ],
    'geo' => [
        'gl30Geo',
        ['slot' => 0, 'settings' => ['mode' => 1, 'longitude' => '121.412248', 'latitude' => '31.187891', 'radius' => 1000]],
        'GTGEO',
        'AT+GTGEO=gl30,0,1,121.412248,31.187891,1000,FFFF$',
    ],
    'bts' => [
        'gl30Bts',
        ['mode' => 1, 'bluetooth_name' => 'GL30MEUR01_BT', 'discoverable_mode' => 0, 'discoverable_time' => 0, 'advertising_interval' => 1000, 'advertising_data_type' => 0],
        'GTBTS',
        'AT+GTBTS=gl30,1,,GL30MEUR01_BT,,0,0,,,,,,,,1000,0,,,,,,,FFFF$',
    ],
    'bid' => [
        'gl30Bid',
        ['enable' => 1, 'beacon_id_model' => 4, 'append_mask' => '000A', 'scan_interval' => 30, 'beacon_accessory_model' => '123456', 'mac_list' => ['11BBCCDDEEFF', '22BBCCDDEEFF', '33BBCCDDEEFF', '44BBCCDDEEFF']],
        'GTBID',
        'AT+GTBID=gl30,,1,4,000A,,,,,,,,30,,,123456,11BBCCDDEEFF,22BBCCDDEEFF,33BBCCDDEEFF,44BBCCDDEEFF,,,,,,,FFFF$',
    ],
    'wifi' => [
        'gl30Wifi',
        ['mode' => 0, 'scan_interval' => 10, 'send_interval' => 0, 'lost_times' => 2, 'alarm_scan_interval' => 10, 'start_index' => 1, 'end_index' => 1],
        'GTWFI',
        'AT+GTWFI=gl30,0,10,0,2,10,1,1,,,,,,,,,,,,,,,,,,,,,FFFF$',
    ],
    'wlt' => [
        'gl30Wlt',
        ['number_filter' => 1, 'phone_number_start' => 1, 'phone_number_end' => 2, 'phone_numbers' => ['13813888888', '13913999999']],
        'GTWLT',
        'AT+GTWLT=gl30,1,1,2,13813888888,13913999999,,,,,FFFF$',
    ],
    'upc' => [
        'gl30Upc',
        ['max_download_retry' => 0, 'download_timeout_minutes' => 30, 'download_protocol' => 0, 'report_enable' => 1, 'update_interval_hours' => 0, 'download_url' => 'http://www.xxx.com/configure.ini', 'mode' => 1, 'extended_status_report' => 1, 'identifier_number' => '1234578'],
        'GTUPC',
        'AT+GTUPC=gl30,0,30,0,1,0,http://www.xxx.com/configure.ini,1,,1,1234578,,,FFFF$',
    ],
    'fvr' => [
        'gl30Fvr',
        ['configuration_name' => 'TEST', 'configuration_version' => '0001'],
        'GTFVR',
        'AT+GTFVR=gl30,TEST,0001,,,,,,,,,,,FFFF$',
    ],
]);
