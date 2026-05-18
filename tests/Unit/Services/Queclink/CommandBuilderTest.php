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
