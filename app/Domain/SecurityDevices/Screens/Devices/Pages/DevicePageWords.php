<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use Illuminate\Support\Str;

/** Plain words for the device pages: events as log lines, stored keys as sentence case. */
final class DevicePageWords
{
    /** Events whose stored name isn't plain English on its own. */
    private const array EVENT_WORDS = [
        'offline' => 'Went offline',
        'online' => 'Back online',
        'recovered' => 'Back online',
        'tamper' => 'Tampered with',
        'management_command_reconciled' => 'A change was checked',
        // S&D rebuild C6: the Devices bulk bar's result for this device.
        'bulk_action_requested' => 'Asked to run an action with other devices',
        'bulk_action_left_out' => 'Left out of an action run on several devices',
        'firmware_updated' => 'Software updated',
        'firmware.updated' => 'Software updated',
        'reboot' => 'Restarted',
        'restarted' => 'Restarted',
    ];

    /** @return array{key: string, at: ?string, tone: string, title: string, sub: ?string} */
    public static function event(DeviceEvent $event): array
    {
        $type = strtolower(trim((string) $event->event_type));

        return [
            'key' => 'event-'.$event->id,
            'at' => $event->occurred_at?->toIso8601String(),
            'tone' => self::tone((string) $event->severity),
            'title' => self::EVENT_WORDS[$type] ?? self::sentence($type),
            'sub' => self::source((string) $event->source),
        ];
    }

    public static function tone(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high', 'error', 'danger', 'major', 'block' => 'critical',
            'warning', 'caution', 'medium' => 'warning',
            'ok', 'resolved', 'success' => 'success',
            default => 'info',
        };
    }

    /** "firmware_update_available" → "Firmware update available"; words like UPS and SSH keep capitals. */
    public static function sentence(string $key): string
    {
        $acronyms = ['ups' => 'UPS', 'ssh' => 'SSH', 'snmp' => 'SNMP', 'poe' => 'PoE', 'nvr' => 'NVR', 'ip' => 'IP', 'wifi' => 'Wi-Fi', 'dhcp' => 'DHCP'];
        $words = preg_split('/[_.\s-]+/', mb_strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 'Something happened';
        }

        return collect($words)
            ->map(fn (string $word, int $index): string => $acronyms[$word] ?? ($index === 0 ? Str::ucfirst($word) : $word))
            ->join(' ');
    }

    /** Where an event came from, without code words. */
    private static function source(string $source): ?string
    {
        $source = strtolower(trim($source));

        return match (true) {
            $source === '' => null,
            in_array($source, ['native', 'oblivion', 'monitoring', 'native_monitoring'], true) => 'From Oblivion’s checks',
            str_contains($source, 'collector') || str_contains($source, 'hub') => 'From the hub',
            str_contains($source, 'unifi') => 'From UniFi',
            str_contains($source, 'milesight') => 'From Milesight',
            str_contains($source, 'hikvision') => 'From the recorder',
            str_contains($source, 'manual') || str_contains($source, 'user') => 'Recorded by staff',
            default => null,
        };
    }
}
