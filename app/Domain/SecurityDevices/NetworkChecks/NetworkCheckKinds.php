<?php

namespace App\Domain\SecurityDevices\NetworkChecks;

use Carbon\CarbonImmutable;

/**
 * The fixed allow-list of checks Obli may ask for (pop-up 97). Every one only looks: nothing on the
 * network is changed. The hub keeps the same list for its kinds (NetworkCheckLane) and refuses
 * anything else.
 *
 * Results are cleaned here to each kind's fields (numbers bounded, names trimmed), and each
 * finding is written here from the cleaned result, never by the AI. A check that couldn't be done
 * says so: "Not available: <why>".
 */
final class NetworkCheckKinds
{
    /** source: who runs it; target: which device it needs. */
    public const array KINDS = [
        'delay' => ['source' => 'hub', 'target' => 'any'],
        'gateway_speed' => ['source' => 'hub', 'target' => 'none'],
        'switch_ports' => ['source' => 'hub', 'target' => 'switch'],
        'wifi' => ['source' => 'hub', 'target' => 'wifi_point'],
        'stored_outages' => ['source' => 'stored', 'target' => 'none'],
        'stored_map' => ['source' => 'stored', 'target' => 'optional'],
        'stored_checks' => ['source' => 'stored', 'target' => 'any'],
    ];

    /** The hub kinds' parameters, exactly (the hub checks the same). */
    public const array HUB_PARAMS = [
        'delay' => ['address'],
        'gateway_speed' => ['console', 'port', 'trusted_pin'],
        'switch_ports' => ['console', 'port', 'trusted_pin', 'address', 'mac'],
        'wifi' => ['console', 'port', 'trusted_pin', 'address', 'mac', 'hours'],
    ];

    /** Why a hub check couldn't be done, from the hub's reason code. */
    public const array HUB_REASONS = [
        'out_of_scope' => 'the hub doesn’t watch that device, so it won’t contact it',
        'no_login' => 'the hub didn’t get a UniFi login it could open',
        'other_console' => 'the saved UniFi login is for a different UniFi console',
        'login_failed' => 'the UniFi console didn’t accept the saved login',
        'unreachable' => 'the hub couldn’t reach it',
        'not_measured' => 'the hub couldn’t run its ping check, so nothing was measured',
        'certificate' => 'the UniFi console’s certificate wasn’t the trusted one, so the hub didn’t send the login',
        'not_found' => 'the UniFi console doesn’t list that device',
        'no_speed_test' => 'the gateway hasn’t run its own speed test',
        'timeout' => 'it took longer than 20 seconds',
        'refused' => 'the hub refused it',
        'expired' => 'it reached the hub too late',
    ];

    public static function isHub(string $kind): bool
    {
        return (self::KINDS[$kind]['source'] ?? null) === 'hub';
    }

    public static function title(string $kind, ?string $device, string $hub): string
    {
        $device = $device ?? 'a device';

        return match ($kind) {
            'delay' => "Delay from {$hub} to {$device}",
            'gateway_speed' => 'Speed test from the gateway',
            'switch_ports' => "Switch ports on {$device}",
            'wifi' => "Wi-Fi on {$device}",
            'stored_outages' => 'Drop-outs on the Internet tab',
            'stored_map' => $device === 'a device' ? 'The Site’s network map' : "Where {$device} is on the network map",
            'stored_checks' => "Oblivion’s checks of {$device}, last 24 hours",
            default => 'A check',
        };
    }

    /**
     * Keep only the kind's fields, bounded. Null when the result doesn't look right.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function clean(string $kind, array $raw): ?array
    {
        $int = static fn (mixed $value, int $min, int $max): ?int => is_int($value) && $value >= $min && $value <= $max ? $value : (is_float($value) && $value >= $min && $value <= $max ? (int) round($value) : null);
        $num = static fn (mixed $value, float $min, float $max): ?float => (is_int($value) || is_float($value)) && $value >= $min && $value <= $max ? round((float) $value, 1) : null;
        $text = static fn (mixed $value, int $length = 64): ?string => is_string($value) && trim($value) !== '' && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? mb_substr(trim($value), 0, $length) : null;
        $bool = static fn (mixed $value): ?bool => is_bool($value) ? $value : null;

        return match ($kind) {
            'delay' => ($sent = $int($raw['sent'] ?? null, 1, 20)) === null || ($received = $int($raw['received'] ?? null, 0, 20)) === null || $received > $sent
                ? null
                : [
                    'sent' => $sent,
                    'received' => $received,
                    'min_ms' => $received > 0 ? $num($raw['min_ms'] ?? null, 0, 60_000) : null,
                    'median_ms' => $received > 0 ? $num($raw['median_ms'] ?? null, 0, 60_000) : null,
                    'max_ms' => $received > 0 ? $num($raw['max_ms'] ?? null, 0, 60_000) : null,
                ],
            'gateway_speed' => ($down = $num($raw['down_mbps'] ?? null, 0, 100_000)) === null || ($up = $num($raw['up_mbps'] ?? null, 0, 100_000)) === null
                ? null
                : [
                    'down_mbps' => $down,
                    'up_mbps' => $up,
                    'latency_ms' => $num($raw['latency_ms'] ?? null, 0, 60_000),
                    'tested_at' => $int($raw['tested_at'] ?? null, 1_500_000_000, 4_000_000_000),
                    'isp' => $text($raw['isp'] ?? null, 48),
                ],
            'switch_ports' => ! is_array($raw['ports'] ?? null) || ! array_is_list($raw['ports'])
                ? null
                : ['ports' => array_values(array_filter(array_map(static function (mixed $port) use ($int, $num, $text, $bool): ?array {
                    if (! is_array($port) || ($idx = $int($port['idx'] ?? null, 1, 128)) === null) {
                        return null;
                    }

                    return [
                        'idx' => $idx,
                        'name' => $text($port['name'] ?? null, 48),
                        'up' => $bool($port['up'] ?? null) ?? false,
                        'speed_mbps' => $int($port['speed_mbps'] ?? null, 0, 100_000),
                        'full_duplex' => $bool($port['full_duplex'] ?? null),
                        'rx_errors' => $int($port['rx_errors'] ?? null, 0, PHP_INT_MAX),
                        'tx_errors' => $int($port['tx_errors'] ?? null, 0, PHP_INT_MAX),
                        'poe_w' => $num($port['poe_w'] ?? null, 0, 200),
                    ];
                }, array_slice($raw['ports'], 0, 64))))],
            'wifi' => ($clients = $int($raw['clients'] ?? null, 0, 2000)) === null
                ? null
                : [
                    'clients' => $clients,
                    'weak' => min($clients, $int($raw['weak'] ?? null, 0, 2000) ?? 0),
                    'signal_avg' => $int($raw['signal_avg'] ?? null, -120, 0),
                    'satisfaction' => $int($raw['satisfaction'] ?? null, 0, 100),
                    'radios' => array_values(array_filter(array_map(static function (mixed $radio) use ($int, $text): ?array {
                        if (! is_array($radio) || ! in_array($radio['band'] ?? null, ['2.4', '5', '6'], true)) {
                            return null;
                        }

                        return ['band' => $radio['band'], 'clients' => $int($radio['clients'] ?? null, 0, 2000), 'channel' => $int($radio['channel'] ?? null, 1, 233), 'busy' => $int($radio['busy'] ?? null, 0, 100)];
                    }, is_array($raw['radios'] ?? null) ? array_slice($raw['radios'], 0, 3) : []))),
                    'history' => ($raw['history'] ?? null) === true,
                    'hours' => $int($raw['hours'] ?? null, 1, 72),
                    'drops' => ($raw['history'] ?? null) === true ? $int($raw['drops'] ?? null, 0, 100_000) : null,
                    'drop_hours' => ($raw['history'] ?? null) === true && is_array($raw['drop_hours'] ?? null) && count($raw['drop_hours']) === 24
                        ? array_map(static fn (mixed $count): int => is_int($count) && $count >= 0 ? min($count, 100_000) : 0, array_values($raw['drop_hours']))
                        : null,
                ],
            default => $raw,
        };
    }

    /**
     * The finding shown under a check, in plain words, from the cleaned result.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $portNames  switch port number → the device Oblivion knows is on it
     */
    public static function finding(string $kind, array $result, array $portNames = []): string
    {
        return match ($kind) {
            'delay' => $result['received'] === 0
                ? "No replies: {$result['sent']} of {$result['sent']} lost"
                : self::n($result['median_ms']).' ms typical ('.self::n($result['min_ms']).'–'.self::n($result['max_ms']).' ms) · '
                    .($result['sent'] - $result['received'] === 0 ? 'none lost' : ($result['sent'] - $result['received'])." of {$result['sent']} lost"),
            'gateway_speed' => self::n($result['down_mbps']).' Mbps down, '.self::n($result['up_mbps']).' up'
                .($result['latency_ms'] !== null ? ' · '.self::n($result['latency_ms']).' ms' : '')
                .($result['tested_at'] !== null ? ' · the gateway’s own test, '.self::when($result['tested_at']) : '')
                .($result['isp'] !== null ? ' · '.$result['isp'] : ''),
            'switch_ports' => self::portsWords($result['ports'], $portNames),
            'wifi' => self::wifiWords($result),
            'stored_outages' => ($result['gateways'] ?? 1) === 0
                ? 'Not available: Oblivion doesn’t know this Site’s router, so drop-outs can’t be read'
                : ($result['count'] === 0
                ? 'No drop-outs recorded in the last 14 days'
                : $result['count'].' '.($result['count'] === 1 ? 'drop-out' : 'drop-outs').' recorded in the last 14 days'
                    .(is_string($result['last_at'] ?? null) ? ', the last '.self::when(CarbonImmutable::parse($result['last_at'])->getTimestamp()) : '')),
            'stored_map' => self::mapWords($result),
            'stored_checks' => $result['checks'] === 0
                ? 'Not available: no checks recorded in the last 24 hours'
                : 'Answered '.self::n($result['answered_pct']).'% of '.$result['checks'].' checks'
                    .($result['typical_ms'] !== null ? ' · typical reply '.$result['typical_ms'].' ms' : ''),
            default => 'Done',
        };
    }

    /** @param list<array<string, mixed>> $ports @param array<int, string> $names */
    private static function portsWords(array $ports, array $names): string
    {
        if ($ports === []) {
            return 'Not available: the UniFi console listed no ports for this switch';
        }
        $up = array_filter($ports, static fn (array $port): bool => $port['up']);
        $errors = array_filter($ports, static fn (array $port): bool => ($port['rx_errors'] ?? 0) + ($port['tx_errors'] ?? 0) > 0);
        $parts = [count($up).' of '.count($ports).' ports up', $errors === [] ? 'no errors' : count($errors).' with errors'];
        $shown = 0;
        foreach ($ports as $port) {
            $name = $names[$port['idx']] ?? null;
            if ($name === null && ! in_array($port, $errors, true)) {
                continue;
            }
            if (++$shown > 4) {
                break;
            }
            $speed = ! $port['up'] ? 'down' : ($port['speed_mbps'] === null ? 'up' : ($port['speed_mbps'] >= 1000 ? self::n($port['speed_mbps'] / 1000).' Gbps' : $port['speed_mbps'].' Mbps'));
            $faults = ($port['rx_errors'] ?? 0) + ($port['tx_errors'] ?? 0);
            $parts[] = 'Port '.$port['idx'].($name !== null ? " ({$name})" : '').' '.$speed.($faults > 0 ? ", {$faults} errors" : '');
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $result */
    private static function wifiWords(array $result): string
    {
        $parts = [$result['clients'].' '.($result['clients'] === 1 ? 'device' : 'devices').' on it now'];
        if ($result['clients'] > 0) {
            $parts[] = $result['weak'] === 0 ? 'none on a weak signal' : $result['weak'].' on a weak signal';
        }
        if ($result['signal_avg'] !== null) {
            $parts[] = 'average signal '.str_replace('-', '−', (string) $result['signal_avg']).' dBm';
        }
        $busy = array_filter(array_column($result['radios'], 'busy'), static fn (mixed $value): bool => is_int($value));
        if ($busy !== []) {
            $parts[] = 'busiest radio '.max($busy).'% busy';
        }
        if (! $result['history']) {
            $parts[] = 'drop-outs: Not available (the UniFi console doesn’t share its history)';
        } elseif ($result['drops'] !== null) {
            $hours = $result['hours'] ?? 72;
            $words = $result['drops'].' '.($result['drops'] === 1 ? 'drop-out' : 'drop-outs')." in the last {$hours} hours";
            if ($result['drops'] > 0 && is_array($result['drop_hours'])) {
                $peak = array_search(max($result['drop_hours']), $result['drop_hours'], true);
                $words .= ', most around '.CarbonImmutable::createFromTime((int) $peak, 0, 0, 'Pacific/Auckland')->format('g a');
            }
            $parts[] = $words;
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $result */
    private static function mapWords(array $result): string
    {
        $links = is_array($result['links'] ?? null) ? $result['links'] : [];
        if ($links === []) {
            return $result['placed'] === 0 ? 'Not available: nothing is placed on the map yet' : $result['placed'].' devices on the map · no links recorded for this one';
        }

        return implode(' · ', array_map(static fn (array $link): string => $link['child'].' is linked to '.$link['parent'].($link['port'] !== null ? ', '.$link['port'] : ''), array_slice($links, 0, 4)));
    }

    /** "Wed 28 Oct, 7:41 pm" in NZ time. */
    public static function when(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone('Pacific/Auckland')->format('D j M, g:i a');
    }

    private static function n(int|float|null $value): string
    {
        if ($value === null) {
            return 'Not available';
        }

        $text = number_format((float) $value, $value >= 100 ? 0 : 1, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
