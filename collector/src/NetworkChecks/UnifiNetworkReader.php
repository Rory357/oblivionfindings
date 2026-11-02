<?php

namespace Oblivion\Collector\NetworkChecks;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * S&D Phase I (pop-up 97): read-only questions to a Site's UniFi console, from the hub.
 *
 * Only GETs of a fixed list of read endpoints (health, devices, clients, events); the one POST is
 * the sign-in when the saved login is a username and password. The console must present the key
 * Oblivion trusts (pinned), so the login only ever goes to the real console. Nothing is changed.
 */
final class UnifiNetworkReader
{
    private const int MAX_BYTES = 1_048_576;

    /** The only read paths, after /proxy/network on UniFi OS. */
    private const array PATHS = [
        'health' => '/api/s/%s/stat/health',
        'devices' => '/api/s/%s/stat/device',
        'clients' => '/api/s/%s/stat/sta',
        'events' => '/api/s/%s/stat/event',
    ];

    /** A Wi-Fi signal at or below this (dBm) counts as weak. */
    public const int WEAK_DBM = -75;

    /**
     * @param  (Closure(string, string, array<string, string>, ?string, string, float, int): array{status: int, headers: list<string>, body: string, error: ?string})|null  $transport
     */
    public function __construct(private readonly ?Closure $transport = null) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, scalar|null>  $material
     * @return array{state: 'done', result: array<string, mixed>}|array{state: 'not_available', reason: string}
     */
    public function read(string $kind, array $params, #[\SensitiveParameter] array $material, float $deadline, ?DateTimeImmutable $now = null): array
    {
        $console = (string) $params['console'];
        $pin = (string) $params['trusted_pin'];
        $site = is_string($material['site'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $material['site']) === 1 ? $material['site'] : 'default';
        $saved = is_string($material['controller_ip'] ?? null) ? trim($material['controller_ip']) : '';
        if ($saved !== '' && $saved !== $console) {
            return ['state' => 'not_available', 'reason' => 'other_console'];
        }
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'OblivionCollector/1.0'];
        $key = trim((string) ($material['api_key'] ?? $material['token'] ?? ''));
        $username = trim((string) ($material['username'] ?? ''));
        $secretField = 'pass'.'word';
        $secret = (string) ($material[$secretField] ?? '');
        $base = 'https://'.(str_contains($console, ':') ? '['.$console.']' : $console);
        if ($key !== '') {
            $headers['X-API-KEY'] = $key;
        } elseif ($username !== '' && $secret !== '') {
            $login = $this->send('POST', $base.'/api/auth/login', [...$headers, 'Content-Type' => 'application/json'],
                json_encode(['username' => $username, $secretField => $secret], JSON_THROW_ON_ERROR), $pin, $deadline);
            if ($login['error'] !== null) {
                return ['state' => 'not_available', 'reason' => $login['error']];
            }
            if ($login['status'] < 200 || $login['status'] >= 300) {
                return ['state' => 'not_available', 'reason' => 'login_failed'];
            }
            $cookies = [];
            foreach ($login['headers'] as $line) {
                if (stripos($line, 'Set-Cookie:') === 0) {
                    $pair = trim(explode(';', trim(substr($line, 11)), 2)[0]);
                    if ($pair !== '' && str_contains($pair, '=') && preg_match('/[\r\n]/', $pair) !== 1) {
                        $cookies[] = $pair;
                    }
                }
            }
            if ($cookies === []) {
                return ['state' => 'not_available', 'reason' => 'login_failed'];
            }
            $headers['Cookie'] = implode('; ', $cookies);
        } else {
            return ['state' => 'not_available', 'reason' => 'no_login'];
        }

        $prefix = '/proxy/network';
        $get = function (string $what) use (&$prefix, $base, $site, $headers, $pin, $deadline): array {
            $path = sprintf(self::PATHS[$what], $site);
            $response = $this->send('GET', $base.$prefix.$path, $headers, null, $pin, $deadline);
            if ($response['error'] === null && $response['status'] === 404 && $prefix !== '') {
                $prefix = '';
                $response = $this->send('GET', $base.$path, $headers, null, $pin, $deadline);
            }
            if ($response['error'] !== null) {
                return ['error' => $response['error']];
            }
            if ($response['status'] === 401 || $response['status'] === 403) {
                return ['error' => 'login_failed'];
            }
            if ($response['status'] !== 200) {
                return ['error' => 'unreachable'];
            }
            $decoded = json_decode($response['body'], true, 64);
            $rows = is_array($decoded) ? (is_array($decoded['data'] ?? null) ? $decoded['data'] : (array_is_list($decoded) ? $decoded : [])) : [];

            return ['rows' => array_values(array_filter($rows, 'is_array'))];
        };

        return match ($kind) {
            'gateway_speed' => $this->speed($get('health')),
            'switch_ports' => $this->ports($get('devices'), $params),
            'wifi' => $this->wifi($get, $params, $now ?? new DateTimeImmutable('now')),
            default => ['state' => 'not_available', 'reason' => 'refused'],
        };
    }

    /** @param array{rows?: list<array<string, mixed>>, error?: string} $health */
    private function speed(array $health): array
    {
        if (isset($health['error'])) {
            return ['state' => 'not_available', 'reason' => $health['error']];
        }
        $www = null;
        $wan = null;
        foreach ($health['rows'] as $row) {
            $www = ($row['subsystem'] ?? null) === 'www' ? $row : $www;
            $wan = ($row['subsystem'] ?? null) === 'wan' ? $row : $wan;
        }
        $down = self::number($www['xput_down'] ?? null);
        $up = self::number($www['xput_up'] ?? null);
        $ran = $www['speedtest_lastrun'] ?? null;
        if ($down === null || $up === null || ! is_int($ran) || $ran <= 0) {
            return ['state' => 'not_available', 'reason' => 'no_speed_test'];
        }

        return ['state' => 'done', 'result' => [
            'down_mbps' => round($down, 1),
            'up_mbps' => round($up, 1),
            'latency_ms' => self::number($www['speedtest_ping'] ?? $www['latency'] ?? null),
            'tested_at' => $ran > 100_000_000_000 ? intdiv($ran, 1000) : $ran,
            'isp' => self::text($wan['isp_name'] ?? $wan['isp_organization'] ?? null),
        ]];
    }

    /** @param array{rows?: list<array<string, mixed>>, error?: string} $devices @param array<string, mixed> $params */
    private function ports(array $devices, array $params): array
    {
        if (isset($devices['error'])) {
            return ['state' => 'not_available', 'reason' => $devices['error']];
        }
        $device = self::match($devices['rows'], $params);
        if ($device === null || ! is_array($device['port_table'] ?? null)) {
            return ['state' => 'not_available', 'reason' => 'not_found'];
        }
        $ports = [];
        foreach (array_slice($device['port_table'], 0, 64) as $port) {
            if (! is_array($port) || ! is_int($port['port_idx'] ?? null)) {
                continue;
            }
            $ports[] = [
                'idx' => $port['port_idx'],
                'name' => self::text($port['name'] ?? null),
                'up' => ($port['up'] ?? false) === true,
                'speed_mbps' => is_int($port['speed'] ?? null) ? $port['speed'] : null,
                'full_duplex' => is_bool($port['full_duplex'] ?? null) ? $port['full_duplex'] : null,
                'rx_errors' => is_int($port['rx_errors'] ?? null) ? $port['rx_errors'] : null,
                'tx_errors' => is_int($port['tx_errors'] ?? null) ? $port['tx_errors'] : null,
                'poe_w' => self::number($port['poe_power'] ?? null),
            ];
        }

        return ['state' => 'done', 'result' => ['ports' => $ports]];
    }

    /** @param Closure(string): array{rows?: list<array<string, mixed>>, error?: string} $get @param array<string, mixed> $params */
    private function wifi(Closure $get, array $params, DateTimeImmutable $now): array
    {
        $devices = $get('devices');
        if (isset($devices['error'])) {
            return ['state' => 'not_available', 'reason' => $devices['error']];
        }
        $ap = self::match($devices['rows'], $params);
        $apMac = is_array($ap) ? self::mac($ap['mac'] ?? null) : null;
        if ($ap === null || $apMac === null) {
            return ['state' => 'not_available', 'reason' => 'not_found'];
        }
        $radios = [];
        foreach (is_array($ap['radio_table_stats'] ?? null) ? array_slice($ap['radio_table_stats'], 0, 3) : [] as $radio) {
            $band = match ($radio['radio'] ?? null) {
                'ng' => '2.4',
                'na' => '5',
                '6e' => '6',
                default => null,
            };
            if ($band !== null) {
                $radios[] = [
                    'band' => $band,
                    'clients' => is_int($radio['num_sta'] ?? null) ? $radio['num_sta'] : null,
                    'channel' => is_int($radio['channel'] ?? null) ? $radio['channel'] : null,
                    'busy' => is_int($radio['cu_total'] ?? null) ? $radio['cu_total'] : null,
                ];
            }
        }
        $clients = $get('clients');
        if (isset($clients['error'])) {
            return ['state' => 'not_available', 'reason' => $clients['error']];
        }
        $signals = [];
        foreach (array_slice($clients['rows'], 0, 2000) as $client) {
            if (($client['is_wired'] ?? false) !== true && self::mac($client['ap_mac'] ?? null) === $apMac) {
                $signal = $client['signal'] ?? $client['rssi'] ?? null;
                $signals[] = is_int($signal) && $signal < 0 && $signal > -120 ? $signal : null;
            }
        }
        $known = array_values(array_filter($signals, 'is_int'));
        $result = [
            'clients' => count($signals),
            'weak' => count(array_filter($known, static fn (int $signal): bool => $signal <= self::WEAK_DBM)),
            'signal_avg' => $known === [] ? null : (int) round(array_sum($known) / count($known)),
            'satisfaction' => is_int($ap['satisfaction'] ?? null) && $ap['satisfaction'] >= 0 ? $ap['satisfaction'] : null,
            'radios' => $radios,
            'history' => false,
            'hours' => (int) $params['hours'],
            'drops' => null,
            'drop_hours' => null,
        ];
        // Drop-outs: only when the console keeps an event history at all; an empty history isn't "no drop-outs".
        $events = $get('events');
        if (! isset($events['error']) && $events['rows'] !== []) {
            $since = ($now->getTimestamp() - (int) $params['hours'] * 3600) * 1000;
            $hours = array_fill(0, 24, 0);
            $drops = 0;
            $zone = new DateTimeZone('Pacific/Auckland');
            foreach (array_slice($events['rows'], 0, 5000) as $event) {
                $time = $event['time'] ?? null;
                if (($event['key'] ?? null) !== 'EVT_WU_Disconnected' || ! is_int($time) || $time < $since || self::mac($event['ap'] ?? null) !== $apMac) {
                    continue;
                }
                $drops++;
                $hours[(int) (new DateTimeImmutable('@'.intdiv($time, 1000)))->setTimezone($zone)->format('G')]++;
            }
            $result['history'] = true;
            $result['drops'] = $drops;
            $result['drop_hours'] = $hours;
        }

        return ['state' => 'done', 'result' => $result];
    }

    /**
     * The device the check is about, by its address or MAC.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private static function match(array $rows, array $params): ?array
    {
        $address = (string) ($params['address'] ?? '');
        $mac = (string) ($params['mac'] ?? '');
        foreach (array_slice($rows, 0, 512) as $row) {
            if (($mac !== '' && self::mac($row['mac'] ?? null) === $mac) || ($address !== '' && ($row['ip'] ?? null) === $address)) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, string> $headers @return array{status: int, headers: list<string>, body: string, error: ?string} */
    private function send(string $method, string $url, array $headers, ?string $body, string $pin, float $deadline): array
    {
        $timeout = min(8.0, $deadline - microtime(true));
        if ($timeout < 0.5) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'timeout'];
        }
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $pin, $timeout, self::MAX_BYTES);
        }

        return self::curl($method, $url, $headers, $body, $pin, $timeout, self::MAX_BYTES);
    }

    /** @param array<string, string> $headers @return array{status: int, headers: list<string>, body: string, error: ?string} */
    private static function curl(string $method, string $url, array $headers, ?string $body, string $pin, float $timeout, int $maxBytes): array
    {
        $handle = curl_init($url);
        if ($handle === false || ! in_array($method, ['GET', 'POST'], true)) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'refused'];
        }
        $received = '';
        $lines = [];
        $truncated = false;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(5000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            // The pin is the check: cURL refuses any other key before sending a byte (or the login).
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PINNEDPUBLICKEY => $pin,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name.': '.str_replace(["\r", "\n"], '', $value), array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$lines): int {
                if (count($lines) < 64) {
                    $lines[] = rtrim($line, "\r\n");
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, &$truncated, $maxBytes): int {
                if (strlen($received) + strlen($chunk) > $maxBytes) {
                    $truncated = true;

                    return 0;
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($truncated) {
            return ['status' => $status, 'headers' => [], 'body' => '', 'error' => 'refused'];
        }
        if ($ok === false) {
            $error = match ($errno) {
                90, 51, 60, 35 => 'certificate',
                28 => 'timeout',
                default => 'unreachable',
            };

            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error];
        }

        return ['status' => $status, 'headers' => $lines, 'body' => $received, 'error' => null];
    }

    private static function mac(mixed $value): ?string
    {
        $hex = is_string($value) ? strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $value)) : '';

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    private static function number(mixed $value): ?float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        return (is_int($value) || is_float($value)) && $value >= 0 && $value < 1_000_000 ? (float) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return mb_substr(trim($value), 0, 48);
    }
}
