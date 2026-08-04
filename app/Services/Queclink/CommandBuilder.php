<?php

namespace App\Services\Queclink;

use InvalidArgumentException;

/**
 * Builds outbound AT+GTXXX command strings sent to devices.
 *
 * Format:
 *   AT+GT<CMD>=<password>,<arg1>,<arg2>,...,<serial>$
 *
 * Examples:
 *   AT+GTRTO=gv500cg,1,,,,,0001$   - Request current GPS position (RTO sub-command 1)
 *   AT+GTQSS=gv500cg,...,0001$     - Quick start settings
 *   AT+GTSRI=gv500cg,3,,1,<host>,<port>,...,0001$  - Backend server registration
 *
 * Default passwords (factory defaults — operators can change via AT+GTBSI):
 *   - GV500CG family: "gv500cg" (note: full model code, not just "gv500")
 *   - GL30M family:   "gl30"
 */
class CommandBuilder
{
    public const FAMILY_GV500CG = 'gv500cg';

    public const FAMILY_GL30M = 'gl30m';

    public function __construct(
        protected SerialNumberAllocator $serials,
    ) {}

    /**
     * Build a raw command string ready to write to a device socket.
     *
     * @param  string  $commandWord  Without the GT prefix (e.g. "RTO", "FRI", "QSS").
     * @param  array<int|string, mixed>  $args  Positional arguments after the password.
     */
    public function build(string $family, string $commandWord, array $args, ?string $password = null): array
    {
        $commandWord = strtoupper($commandWord);
        if (str_starts_with($commandWord, 'GT')) {
            $commandWord = substr($commandWord, 2);
        }

        $password = $password ?? $this->defaultPassword($family);
        $serial = $this->serials->next();

        $parts = [$password];
        foreach ($args as $arg) {
            $parts[] = (string) $arg;
        }
        $parts[] = $serial;

        $raw = sprintf('AT+GT%s=%s$', $commandWord, implode(',', $parts));

        return [
            'command_word' => 'GT'.$commandWord,
            'raw' => $raw,
            'serial' => $serial,
        ];
    }

    /**
     * Canonical builder for every typed command wrapper. Keeping this thin
     * means command-word normalisation, default passwords, serial allocation,
     * and raw-string formatting stay identical across all families.
     *
     * @param  array<int, mixed>  $args
     */
    public function buildAny(string $family, string $command, array $args, ?string $password = null): array
    {
        return $this->build($family, $command, $args, $password);
    }

    /**
     * Pre-canned commands for the debug console one-click buttons.
     */
    public function requestLocation(string $family, #[\SensitiveParameter] ?string $password = null): array
    {
        // AT+GTRTO=password,1,,,,,serial$ — sub-command 1 = "request location".
        return $this->build($family, 'RTO', [1, '', '', '', '', ''], $password);
    }

    public function reboot(string $family, #[\SensitiveParameter] ?string $password = null): array
    {
        // AT+GTRTO=password,3,,,,,serial$ — sub-command 3 = "reboot device".
        return $this->build($family, 'RTO', [3, '', '', '', '', ''], $password);
    }

    public function readConfiguration(
        string $family,
        ?string $section = null,
        #[\SensitiveParameter] ?string $password = null,
    ): array {
        $section = strtoupper(trim((string) $section));
        if ($section === '' || $section === 'ALL') {
            $section = '';
        }

        if ($section !== '' && ! preg_match('/^[A-Z0-9]{3}$/', $section)) {
            throw new InvalidArgumentException('Configuration section must be a 3-character Queclink command section.');
        }

        // AT+GTRTO=password,2,<section>,,,,,serial$ — sub-command 2 = READ.
        return $this->build($family, 'RTO', [2, $section, '', '', '', ''], $password);
    }

    /**
     * Build GL30MEU backend server registration settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function gl30ServerRegistration(array $settings, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'SRI', [
            $settings['report_mode'] ?? 3,
            $settings['manual_netreg'] ?? 0,
            $settings['buffer_mode'] ?? 1,
            $settings['main_host'] ?? '',
            $settings['main_port'] ?? 0,
            $settings['backup_host'] ?? '',
            $settings['backup_port'] ?? 0,
            $settings['sms_gateway'] ?? '',
            $settings['heartbeat_interval_minutes'] ?? 5,
            $settings['sack_enable'] ?? 1,
            $settings['sms_ack_enable'] ?? 0,
            $settings['psm_network_hold_time_seconds'] ?? 30,
            $settings['protocol_format'] ?? 0,
            0, // Reserved.
        ], $password);
    }

    /**
     * Build GL30MEU global configuration. Defaults mirror the Manage Tool's
     * safe continuous-testing profile and can be overwritten by UI fields.
     *
     * @param  array<string, mixed>  $settings
     */
    public function gl30GlobalConfiguration(array $settings, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'CFG', [
            $settings['new_password'] ?? '',
            $settings['device_name'] ?? 'GL30MEU',
            $settings['gnss_timeout_seconds'] ?? 150,
            strtoupper((string) ($settings['event_mask'] ?? '08E3')),
            strtoupper((string) ($settings['report_item_mask'] ?? '006F')),
            $settings['mode_selection'] ?? 1,
            $settings['continuous_send_interval_seconds'] ?? 30,
            '', // Reserved.
            $settings['start_mode'] ?? 0,
            $settings['specified_time_of_day'] ?? '1200',
            '', // Reserved.
            $settings['wakeup_interval_hours'] ?? 1,
            '', // Reserved.
            '', // Reserved.
            '', // Reserved.
            $settings['gnss_enable'] ?? 1,
            $settings['agps_mode'] ?? 1,
            strtoupper((string) ($settings['gsm_report'] ?? '0000')),
            '', // Reserved.
            '', // Reserved.
            $settings['battery_low_percentage'] ?? 10,
            $settings['function_button_mode'] ?? 1,
            '', // Reserved.
            $settings['sos_report_mode'] ?? 1,
            $settings['wifi_report'] ?? 2,
            $settings['led_on'] ?? 1,
            $settings['charge_standby_mode'] ?? 0,
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.2.2 AT+GTPIN:
     * Auto-Unlock PIN, PIN, Reserved x5.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Pin(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'PIN', [
            $args['auto_unlock_pin'] ?? 0,
            $args['pin'] ?? '',
            '', '', '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.2.3 AT+GTDOG:
     * Mode, Reserved, Reboot Interval, Reboot Time, Reserved,
     * Report Before Reboot, Reserved, Unit, Reserved x2, Send Failure Timeout.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Dog(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'DOG', [
            $args['mode'] ?? 1,
            '',
            $args['reboot_interval'] ?? 7,
            $args['reboot_time'] ?? '0200',
            '',
            $args['report_before_reboot'] ?? 1,
            '',
            $args['unit'] ?? 0,
            '',
            '',
            $args['send_failure_timeout'] ?? '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.2.4 AT+GTTMA:
     * Sign, Hour Offset, Minute Offset, Daylight Saving, UTC Time, Reserved x4.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Tma(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'TMA', [
            $args['sign'] ?? '+',
            $args['hour_offset'] ?? 0,
            $args['minute_offset'] ?? 0,
            $args['daylight_saving'] ?? 0,
            $args['utc_time'] ?? '',
            '', '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.2.5 AT+GTNMD:
     * Sensor Enable, Mode, Non-movement Duration, Movement Duration,
     * Movement Threshold, Rest Send Interval, Report Mode, Reserved x2,
     * Safe Check, Location Ignore, Reserved x3.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Nmd(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'NMD', [
            $args['sensor_enable'] ?? 0,
            $args['mode'] ?? 0,
            $args['non_movement_duration'] ?? 3,
            $args['movement_duration'] ?? 3,
            $args['movement_threshold'] ?? 2,
            $args['rest_send_interval'] ?? 1440,
            $args['report_mode'] ?? 2,
            '',
            '',
            $args['safe_check'] ?? 0,
            $args['location_ignore'] ?? '',
            '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.2.6 AT+GTPDS:
     * Mode, Mask, Reserved x6.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Pds(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'PDS', [
            $args['mode'] ?? 1,
            strtoupper((string) ($args['mask'] ?? '00000011')),
            '', '', '', '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.3.1 AT+GTGEO:
     * GEO ID, Mode, Longitude, Latitude, Radius.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Geo(int $slot, array $args, ?string $password = null): array
    {
        if ($slot < 0 || $slot > 19) {
            throw new InvalidArgumentException('GL30 geo-fence slot must be 0..19.');
        }

        return $this->buildAny(self::FAMILY_GL30M, 'GEO', [
            $slot,
            $args['mode'] ?? 0,
            $args['longitude'] ?? '',
            $args['latitude'] ?? '',
            $args['radius'] ?? 50,
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.4.1 AT+GTBTS:
     * Mode, Reserved, Bluetooth Name, Reserved, Discoverable Mode,
     * Discoverable Time, Reserved x7, Advertising Interval,
     * Advertising Data Type, Reserved x6. The protocol has no AT+GTBT
     * command; this is the Bluetooth settings write.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Bts(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'BTS', [
            $args['mode'] ?? 0,
            '',
            $args['bluetooth_name'] ?? 'GL30MEU_BT',
            '',
            $args['discoverable_mode'] ?? 0,
            $args['discoverable_time'] ?? 0,
            '', '', '', '', '', '', '',
            $args['advertising_interval'] ?? 1000,
            $args['advertising_data_type'] ?? 0,
            '', '', '', '', '', '',
        ], $password);
    }

    public function gl30Bt(array $args, ?string $password = null): array
    {
        return $this->gl30Bts($args, $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.4.2 AT+GTBID:
     * Reserved, Enable, Beacon ID Model, Append Mask, Reserved x7,
     * Scan Interval, Reserved x2, Beacon Accessory Model, MAC List,
     * Reserved x6.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Bid(array $args, ?string $password = null): array
    {
        $macList = array_slice(array_values((array) ($args['mac_list'] ?? [])), 0, 4);
        $macList = array_pad($macList, 4, '');

        return $this->buildAny(self::FAMILY_GL30M, 'BID', [
            '',
            $args['enable'] ?? 0,
            $args['beacon_id_model'] ?? 4,
            strtoupper((string) ($args['append_mask'] ?? '000A')),
            '', '', '', '', '', '', '',
            $args['scan_interval'] ?? 30,
            '',
            '',
            $args['beacon_accessory_model'] ?? '',
            ...$macList,
            '', '', '', '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.5.1 AT+GTWFI:
     * Mode, Scan Interval, Send Interval, Lost Times, Alarm Scan Interval,
     * Start Index, End Index, then the configured MAC/SSID list padded to
     * the protocol's GL30 command shape. The plan called this GTWIF in one
     * place; the GL30 v2.04 command word is GTWFI.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Wifi(array $args, ?string $password = null): array
    {
        $entries = array_slice(array_values((array) ($args['entries'] ?? $args['ssid_list'] ?? [])), 0, 20);
        $entries = array_pad($entries, 20, '');

        return $this->buildAny(self::FAMILY_GL30M, 'WFI', [
            $args['mode'] ?? 0,
            $args['scan_interval'] ?? 10,
            $args['send_interval'] ?? 0,
            $args['lost_times'] ?? 2,
            $args['alarm_scan_interval'] ?? 10,
            $args['start_index'] ?? 1,
            $args['end_index'] ?? 1,
            ...$entries,
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.6.2 AT+GTWLT:
     * Number Filter, Phone Number Start, Phone Number End, Phone Number List,
     * Reserved x4.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Wlt(array $args, ?string $password = null): array
    {
        $numbers = array_values((array) ($args['phone_numbers'] ?? []));

        return $this->buildAny(self::FAMILY_GL30M, 'WLT', [
            $args['number_filter'] ?? 0,
            $args['phone_number_start'] ?? ($numbers === [] ? '' : 1),
            $args['phone_number_end'] ?? ($numbers === [] ? '' : count($numbers)),
            ...$numbers,
            '', '', '', '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.7 AT+GTUPC:
     * Max Download Retry, Download Timeout, Download Protocol, Report Enable,
     * Update Interval, Download URL, Mode, Reserved, Extended Status Report,
     * Identifier Number, Reserved x2.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Upc(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'UPC', [
            $args['max_download_retry'] ?? 0,
            $args['download_timeout_minutes'] ?? 10,
            $args['download_protocol'] ?? 0,
            $args['report_enable'] ?? 0,
            $args['update_interval_hours'] ?? 0,
            $args['download_url'] ?? '',
            $args['mode'] ?? 0,
            '',
            $args['extended_status_report'] ?? 0,
            $args['identifier_number'] ?? '',
            '',
            '',
        ], $password);
    }

    /**
     * GL30MEUR01 v2.04 §3.2.7 AT+GTFVR:
     * Configuration Name, Configuration Version, Reserved x5, Digital Signature,
     * Reserved x4. This is written only inside OTA configuration files; the UI
     * mostly reads the section back from GTALM.
     *
     * @param  array<string, mixed>  $args
     */
    public function gl30Fvr(array $args, ?string $password = null): array
    {
        return $this->buildAny(self::FAMILY_GL30M, 'FVR', [
            $args['configuration_name'] ?? '',
            $args['configuration_version'] ?? '',
            '', '', '', '', '',
            $args['digital_signature'] ?? '',
            '', '', '', '',
        ], $password);
    }

    public function gl30ResidentSafetyProfile(): array
    {
        return $this->gl30GlobalConfiguration([
            'continuous_send_interval_seconds' => 30,
            'battery_low_percentage' => 20,
            'function_button_mode' => 1,
            'sos_report_mode' => 1,
            'gnss_enable' => 1,
            'agps_mode' => 1,
            'wifi_report' => 2,
            'led_on' => 1,
            'charge_standby_mode' => 0,
        ]);
    }

    public function setReportingInterval(string $family, int $seconds): array
    {
        if ($seconds < 5 || $seconds > 86400) {
            throw new InvalidArgumentException('Reporting interval must be 5..86400 seconds.');
        }
        if (strtolower($family) === self::FAMILY_GL30M) {
            return $this->gl30GlobalConfiguration([
                'continuous_send_interval_seconds' => $seconds,
            ]);
        }

        // AT+GTFRI fields (after password), per @Track v5.01 §3.4.1:
        //   1=Mode, 2=DiscardNoFix, 3=CompressedReport, 4=PeriodMode,
        //   5=StartTime, 6=EndTime, 7=CheckInterval, 8=SendInterval,
        //   9=Distance, 10=Mileage, 11=Reserved, 12=CornerValue,
        //   13=IGFReportInterval, 14=ERIMask, 15=ContinueTime,
        //   16=Reserved, 17=WrapCornerPoint
        // Example from the spec:
        //   AT+GTFRI=gv500cg,1,1,,1,0000,0000,0,30,1000,1000,,0,600,00000000,0,,0,FFFF$
        return $this->build($family, 'FRI', [
            1,           // Mode = Fixed Time Report
            1,           // Discard No Fix = disable (avoid bogus 0,0 coords)
            '',          // Compressed Report
            1,           // Period Mode
            '0000',      // Start Time
            '0000',      // End Time
            0,           // Check Interval
            $seconds,    // Send Interval ← the field we actually want to set
            1000,        // Distance
            1000,        // Mileage
            '',          // Reserved
            0,           // Corner Value
            600,         // IGF Report Interval
            '00000000',  // ERI Mask
            0,           // Continue Time
            '',          // Reserved
            0,           // Wrap Corner Point
        ]);
    }

    /**
     * Build a raw AT+ command from a freeform string (debug-console REPL).
     * Validates basic shape and appends terminator if missing.
     */
    public function fromRaw(string $rawInput, string $family): array
    {
        $raw = trim($rawInput);
        if ($raw === '') {
            throw new InvalidArgumentException('Empty command');
        }
        if (! str_starts_with(strtoupper($raw), 'AT+GT')) {
            throw new InvalidArgumentException('Command must start with AT+GT');
        }
        if (! str_contains($raw, '=')) {
            throw new InvalidArgumentException('Command must contain "="');
        }
        if (! str_ends_with($raw, '$')) {
            $raw .= '$';
        }

        $afterEq = substr($raw, strpos($raw, '=') + 1, -1);
        $fields = explode(',', $afterEq);
        $serial = end($fields);
        if (! preg_match('/^[0-9A-F]{4}$/i', $serial)) {
            // Auto-append a serial if missing.
            $serial = $this->serials->next();
            $raw = substr($raw, 0, -1).','.$serial.'$';
        }

        $commandStart = strpos($raw, '+GT') + 3;
        $commandEnd = strpos($raw, '=');
        $commandWord = 'GT'.substr($raw, $commandStart, $commandEnd - $commandStart);

        return [
            'command_word' => $commandWord,
            'raw' => $raw,
            'serial' => strtoupper($serial),
        ];
    }

    protected function defaultPassword(string $family): string
    {
        return match (strtolower($family)) {
            self::FAMILY_GV500CG => 'gv500cg',
            self::FAMILY_GL30M => 'gl30',
            default => 'gv500cg',
        };
    }
}
