<?php

namespace App\Services\Queclink;

use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;

class ConfigurationSnapshotService
{
    /** @var list<string> */
    private const SECTION_ORDER = [
        'BSI', 'SRI', 'NTS', 'TLS', 'CFG', 'PIN', 'DOG', 'TMA', 'NMD', 'PDS',
        'GEO', 'BTS', 'WFI', 'BID', 'UPC', 'WLT', 'FVR',
    ];

    /** @return array<string, mixed> */
    public function latestForDevice(QueclinkDevice $device): array
    {
        $frames = $device->relationLoaded('rawFrames')
            ? $device->rawFrames->sortByDesc('id')->take(30)->values()
            : QueclinkRawFrame::query()
                ->where('queclink_device_id', $device->id)
                ->where('direction', QueclinkRawFrame::DIRECTION_INBOUND)
                ->where('command_word', 'GTALM')
                ->where('parse_ok', true)
                ->orderByDesc('id')
                ->limit(30)
                ->get();

        if ($frames->isEmpty()) {
            return [
                'available' => false,
                'received_at' => null,
                'raw' => '',
                'sections' => [],
                'summary' => [
                    'server' => null,
                    'global' => null,
                    'pin' => null,
                    'dog' => null,
                    'time' => null,
                    'non_movement' => null,
                    'power' => null,
                    'wifi' => null,
                    'geofences' => [],
                    'bluetooth' => null,
                    'beacons' => null,
                    'allowlist' => null,
                    'firmware_update' => null,
                    'firmware_version' => null,
                ],
            ];
        }

        $groups = $frames->groupBy(fn (QueclinkRawFrame $frame) => (string) data_get(
            $frame->parsed_payload,
            'send_time',
            $frame->created_at?->toIso8601String() ?? $frame->id,
        ));

        foreach ($groups as $group) {
            $first = $group->first();
            $expected = (int) data_get($first->parsed_payload, 'config_total_packets', 1);
            $packets = $group
                ->filter(fn (QueclinkRawFrame $frame) => data_get($frame->parsed_payload, 'config_text') !== null)
                ->sortBy(fn (QueclinkRawFrame $frame) => (int) data_get($frame->parsed_payload, 'config_current_packet', 1))
                ->values();

            if ($packets->count() < $expected) {
                continue;
            }

            $raw = $packets
                ->map(fn (QueclinkRawFrame $frame) => (string) data_get($frame->parsed_payload, 'config_text'))
                ->filter()
                ->implode(',');

            $parsed = $this->parseConfigurationText($raw);

            return [
                'available' => true,
                'received_at' => $first->created_at?->toIso8601String(),
                'raw' => $raw,
                'sections' => $parsed['sections'],
                'summary' => $parsed['summary'],
            ];
        }

        $latest = $frames->first();

        return [
            'available' => false,
            'received_at' => $latest?->created_at?->toIso8601String(),
            'raw' => '',
            'sections' => [],
            'summary' => [
                'server' => null,
                'global' => null,
                'pin' => null,
                'dog' => null,
                'time' => null,
                'non_movement' => null,
                'power' => null,
                'wifi' => null,
                'geofences' => [],
                'bluetooth' => null,
                'beacons' => null,
                'allowlist' => null,
                'firmware_update' => null,
                'firmware_version' => null,
            ],
        ];
    }

    /** @return array{sections: array<string, mixed>, summary: array<string, mixed>} */
    public function parseConfigurationText(string $raw): array
    {
        $rows = $this->splitSectionRows($raw);
        $sections = $this->rowsToSections($rows);

        return [
            'sections' => $sections,
            'summary' => [
                'battery' => $this->mapBattery($this->firstSection($rows, 'BSI')),
                'server' => $this->mapServer($this->firstSection($rows, 'SRI')),
                'global' => $this->mapGlobal($this->firstSection($rows, 'CFG')),
                'pin' => $this->mapPin($this->firstSection($rows, 'PIN')),
                'dog' => $this->mapDog($this->firstSection($rows, 'DOG')),
                'time' => $this->mapTma($this->firstSection($rows, 'TMA')),
                'non_movement' => $this->mapNmd($this->firstSection($rows, 'NMD')),
                'power' => $this->mapPds($this->firstSection($rows, 'PDS')),
                'wifi' => $this->mapWifi($this->firstSection($rows, 'WFI')),
                'geofences' => $this->mapGeo($this->allSections($rows, 'GEO')),
                'bluetooth' => $this->mapBts($this->firstSection($rows, 'BTS')),
                'beacons' => $this->mapBid($this->firstSection($rows, 'BID')),
                'allowlist' => $this->mapWlt($this->firstSection($rows, 'WLT')),
                'firmware_update' => $this->mapUpc($this->firstSection($rows, 'UPC')),
                'firmware_version' => $this->mapFvr($this->firstSection($rows, 'FVR')),
            ],
        ];
    }

    /** @return list<array{name: string, values: list<string>}> */
    private function splitSectionRows(string $raw): array
    {
        $markers = array_flip(self::SECTION_ORDER);
        $rows = [];
        $current = null;

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if (isset($markers[$token])) {
                $current = $token;
                $rows[] = [
                    'name' => $token,
                    'values' => [],
                ];

                continue;
            }

            if ($current !== null) {
                $rows[array_key_last($rows)]['values'][] = $token;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{name: string, values: list<string>}>  $rows
     * @return array<string, array{name: string, values: list<string>, repeats?: list<list<string>>}>
     */
    private function rowsToSections(array $rows): array
    {
        $sections = [];

        foreach ($rows as $row) {
            $name = $row['name'];
            if (! isset($sections[$name])) {
                $sections[$name] = $row;

                continue;
            }

            $sections[$name]['repeats'] ??= [$sections[$name]['values']];
            $sections[$name]['repeats'][] = $row['values'];
        }

        return $sections;
    }

    /**
     * @param  list<array{name: string, values: list<string>}>  $rows
     * @return list<string>|null
     */
    private function firstSection(array $rows, string $name): ?array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row['values'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, values: list<string>}>  $rows
     * @return list<list<string>>
     */
    private function allSections(array $rows, string $name): array
    {
        return array_values(array_map(
            fn (array $row) => $row['values'],
            array_filter($rows, fn (array $row) => $row['name'] === $name),
        ));
    }

    /** @param  list<string>|null  $values */
    private function mapBattery(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'battery_percentage' => $values[0] ?? '',
            'voltage_mv' => $values[1] ?? '',
            'charging_state' => $values[2] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapServer(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'report_mode' => $values[0] ?? '3',
            'manual_netreg' => $values[1] ?? '0',
            'buffer_mode' => $values[2] ?? '1',
            'main_host' => $values[3] ?? '',
            'main_port' => $values[4] ?? '',
            'backup_host' => $values[5] ?? '',
            'backup_port' => $values[6] ?? '',
            'sms_gateway' => $values[7] ?? '',
            'heartbeat_interval_minutes' => $values[8] ?? '5',
            'sack_enable' => $values[9] ?? '1',
            'sms_ack_enable' => $values[10] ?? '0',
            'psm_network_hold_time_seconds' => $values[11] ?? '30',
            'protocol_format' => $values[12] ?? '0',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapGlobal(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'new_password' => $values[0] ?? '',
            'device_name' => $values[1] ?? 'GL30MEU',
            'gnss_timeout_seconds' => $values[2] ?? '150',
            'event_mask' => strtoupper($values[3] ?? '08E3'),
            'report_item_mask' => strtoupper($values[4] ?? '006F'),
            'mode_selection' => $values[5] ?? '1',
            'continuous_send_interval_seconds' => $values[6] ?? '30',
            'start_mode' => $values[8] ?? '0',
            'specified_time_of_day' => $values[9] ?? '1200',
            'wakeup_interval_hours' => $values[11] ?? '1',
            'gnss_enable' => $values[15] ?? '1',
            'agps_mode' => $values[16] ?? '1',
            'gsm_report' => strtoupper($values[17] ?? '0000'),
            'battery_low_percentage' => $values[20] ?? '10',
            'function_button_mode' => $values[21] ?? '1',
            'sos_report_mode' => $values[23] ?? '1',
            'wifi_report' => $values[24] ?? '2',
            'led_on' => $values[25] ?? '1',
            'charge_standby_mode' => $values[26] ?? '0',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapPin(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'auto_unlock_pin' => $values[0] ?? '0',
            'pin' => $values[1] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapDog(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'mode' => $values[0] ?? '1',
            'reboot_interval' => $values[2] ?? '7',
            'reboot_time' => $values[3] ?? '0200',
            'report_before_reboot' => $values[5] ?? '1',
            'unit' => $values[7] ?? '0',
            'send_failure_timeout' => $values[10] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapTma(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'sign' => $values[0] ?? '+',
            'hour_offset' => $values[1] ?? '0',
            'minute_offset' => $values[2] ?? '0',
            'daylight_saving' => $values[3] ?? '0',
            'utc_time' => $values[4] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapNmd(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'sensor_enable' => $values[0] ?? '0',
            'mode' => $values[1] ?? '0',
            'non_movement_duration' => $values[2] ?? '3',
            'movement_duration' => $values[3] ?? '3',
            'movement_threshold' => $values[4] ?? '2',
            'rest_send_interval' => $values[5] ?? '1440',
            'report_mode' => $values[6] ?? '2',
            'safe_check' => $values[9] ?? '0',
            'location_ignore' => $values[10] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapPds(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'mode' => $values[0] ?? '1',
            'mask' => strtoupper($values[1] ?? '00000011'),
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<int, array<string, string>>
     */
    private function mapGeo(array $rows): array
    {
        $geofences = [];

        foreach ($rows as $values) {
            $slot = (int) ($values[0] ?? 0);
            $geofences[$slot] = [
                'slot' => (string) $slot,
                'mode' => $values[1] ?? '0',
                'longitude' => $values[2] ?? '',
                'latitude' => $values[3] ?? '',
                'radius' => $values[4] ?? '50',
            ];
        }

        ksort($geofences);

        return $geofences;
    }

    /** @param  list<string>|null  $values */
    private function mapBts(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'mode' => $values[0] ?? '0',
            'bluetooth_name' => $values[2] ?? '',
            'discoverable_mode' => $values[4] ?? '0',
            'discoverable_time' => $values[5] ?? '0',
            'advertising_interval' => $values[13] ?? '1000',
            'advertising_data_type' => $values[14] ?? '0',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapBid(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'enable' => $values[1] ?? '0',
            'beacon_id_model' => $values[2] ?? '4',
            'append_mask' => strtoupper($values[3] ?? '000A'),
            'scan_interval' => $values[11] ?? '30',
            'beacon_accessory_model' => $values[14] ?? '',
            'mac_list' => array_values(array_filter(array_slice($values, 15, 4), fn (string $value) => $value !== '')),
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapWifi(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'mode' => $values[0] ?? '0',
            'scan_interval' => $values[1] ?? '10',
            'send_interval' => $values[2] ?? '0',
            'lost_times' => $values[3] ?? '2',
            'alarm_scan_interval' => $values[4] ?? '10',
            'start_index' => $values[5] ?? '1',
            'end_index' => $values[6] ?? '1',
            'entries' => array_values(array_filter(array_slice($values, 7, 20), fn (string $value) => $value !== '')),
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapWlt(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'number_filter' => $values[0] ?? '0',
            'phone_number_start' => $values[1] ?? '',
            'phone_number_end' => $values[2] ?? '',
            'phone_numbers' => array_values(array_filter(array_slice($values, 3), fn (string $value) => $value !== '')),
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapUpc(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'max_download_retry' => $values[0] ?? '0',
            'download_timeout_minutes' => $values[1] ?? '10',
            'download_protocol' => $values[2] ?? '0',
            'report_enable' => $values[3] ?? '0',
            'update_interval_hours' => $values[4] ?? '0',
            'download_url' => $values[5] ?? '',
            'mode' => $values[6] ?? '0',
            'extended_status_report' => $values[8] ?? '0',
            'identifier_number' => $values[9] ?? '',
        ];
    }

    /** @param  list<string>|null  $values */
    private function mapFvr(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return [
            'configuration_name' => $values[0] ?? '',
            'configuration_version' => $values[1] ?? '',
            'digital_signature' => $values[7] ?? '',
            'generation_time' => $values[11] ?? '',
        ];
    }
}
