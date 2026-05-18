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
        $frames = QueclinkRawFrame::query()
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
            ],
        ];
    }

    /** @return array{sections: array<string, mixed>, summary: array<string, mixed>} */
    public function parseConfigurationText(string $raw): array
    {
        $sections = $this->splitSections($raw);

        return [
            'sections' => $sections,
            'summary' => [
                'server' => $this->mapServer($sections['SRI']['values'] ?? null),
                'global' => $this->mapGlobal($sections['CFG']['values'] ?? null),
            ],
        ];
    }

    /** @return array<string, array{name: string, values: list<string>}> */
    private function splitSections(string $raw): array
    {
        $markers = array_flip(self::SECTION_ORDER);
        $sections = [];
        $current = null;

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if (isset($markers[$token])) {
                $current = $token;
                $sections[$current] = [
                    'name' => $token,
                    'values' => [],
                ];

                continue;
            }

            if ($current !== null) {
                $sections[$current]['values'][] = $token;
            }
        }

        return $sections;
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
}
