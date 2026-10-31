<?php

namespace App\Domain\Monitoring\Discovery\Data;

use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use InvalidArgumentException;

final readonly class DiscoveredIdentity
{
    private const array ALLOWED_SIGNAL_STRINGS = [
        'mac_vendor' => 120,
        'ptr_hostname' => 253,
        'mdns_hostname' => 253,
        'mdns_model' => 120,
        'ssdp_server' => 200,
        'ssdp_st' => 200,
        'upnp_device_type' => 200,
        'upnp_friendly_name' => 160,
        'upnp_manufacturer' => 120,
        'upnp_model_name' => 120,
        'onvif_types' => 240,
        'onvif_hardware' => 120,
        'onvif_name' => 160,
        'http_server' => 160,
        'http_title' => 160,
        'http_auth_realm' => 120,
        'tls_common_name' => 160,
        'tls_organization' => 160,
        'snmp_sys_descr' => 255,
        'snmp_sys_object_id' => 128,
        'snmp_sys_name' => 253,
        'snmp_manufacturer' => 120,
        'snmp_model' => 120,
        'arp_gateway_ip' => 64,
        'arp_gateway_mac' => 32,
    ];

    /**
     * @param  list<string>  $macAddresses
     * @param  list<string>  $addresses
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public ?string $provider,
        public ?string $providerId,
        public ?string $serialNumber,
        public ?string $hardwareId,
        public array $macAddresses,
        public ?string $certificateFingerprint,
        public ?string $hostname,
        public array $addresses,
        public ?string $fingerprint,
        public array $signals = [],
    ) {
        if (count($macAddresses) > 64 || count($addresses) > 64) {
            throw new InvalidArgumentException('Discovered identity exceeds bounded evidence limits.');
        }
    }

    /**
     * @return list<array{type: string, value: string, weight: int, reason: string, immutable: bool}>
     */
    public function evidence(): array
    {
        $evidence = [];
        $provider = $this->optional('provider', $this->provider);
        $providerId = $this->optional('provider_id', $this->providerId);
        if ($provider !== null && $providerId !== null) {
            $evidence[] = $this->item('provider_id', "{$provider}:{$providerId}", 100, 'provider_id_exact', true);
        }
        if (($serial = $this->optional('serial_number', $this->serialNumber)) !== null) {
            $evidence[] = $this->item('serial_number', $serial, 95, 'serial_number_exact', true);
        }
        if (($certificate = $this->optional('certificate_fingerprint', $this->certificateFingerprint)) !== null) {
            $evidence[] = $this->item('certificate_fingerprint', $certificate, 95, 'certificate_fingerprint_exact', true);
        }
        if (($hardware = $this->optional('hardware_id', $this->hardwareId)) !== null) {
            $evidence[] = $this->item('hardware_id', $hardware, 90, 'hardware_id_exact', true);
        }
        foreach ($this->macAddresses as $mac) {
            $evidence[] = $this->item('mac_address', $mac, 80, 'mac_address_requires_review', false);
        }
        if (($fingerprint = $this->optional('device_fingerprint', $this->fingerprint)) !== null) {
            $evidence[] = $this->item('device_fingerprint', $fingerprint, 55, 'device_fingerprint_is_mutable', false);
        }
        if (($hostname = $this->optional('hostname', $this->hostname)) !== null) {
            $evidence[] = $this->item('hostname', $hostname, 25, 'hostname_is_mutable', false);
        }
        foreach ($this->addresses as $address) {
            $evidence[] = $this->item('address_history', $address, 15, 'address_history_only', false);
        }

        $unique = [];
        foreach ($evidence as $item) {
            $unique[$item['type'].':'.$item['value']] = $item;
        }

        return array_values($unique);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $grouped = collect($this->evidence())->groupBy('type');

        return [
            'addresses' => $grouped->get('address_history', collect())->pluck('value')->values()->all(),
            'certificate_fingerprint' => $grouped->get('certificate_fingerprint', collect())->first()['value'] ?? null,
            'fingerprint' => $grouped->get('device_fingerprint', collect())->first()['value'] ?? null,
            'hardware_id' => $grouped->get('hardware_id', collect())->first()['value'] ?? null,
            'hostname' => $grouped->get('hostname', collect())->first()['value'] ?? null,
            'mac_addresses' => $grouped->get('mac_address', collect())->pluck('value')->values()->all(),
            'provider' => $this->optional('provider', $this->provider),
            'provider_id' => $this->optional('provider_id', $this->providerId),
            'serial_number' => $grouped->get('serial_number', collect())->first()['value'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function sanitizedSignals(): array
    {
        if ($this->signals === []) {
            return [];
        }

        $clean = [];

        if (is_array($this->signals['open_ports'] ?? null)) {
            $ports = [];
            foreach (array_slice($this->signals['open_ports'], 0, 64) as $port) {
                if (is_int($port) && $port >= 1 && $port <= 65535) {
                    $ports[] = $port;
                }
            }
            $ports = array_values(array_unique($ports));
            sort($ports);
            if ($ports !== []) {
                $clean['open_ports'] = $ports;
            }
        }

        if (is_int($this->signals['http_status'] ?? null)
            && $this->signals['http_status'] >= 100
            && $this->signals['http_status'] <= 599) {
            $clean['http_status'] = $this->signals['http_status'];
        }

        foreach (['locally_administered_mac', 'icmp_reachable', 'arp_seen', 'is_subnet_gateway'] as $boolKey) {
            if (is_bool($this->signals[$boolKey] ?? null)) {
                $clean[$boolKey] = $this->signals[$boolKey];
            }
        }

        foreach (['mdns_services' => 24, 'onvif_scopes' => 16] as $listKey => $maxItems) {
            if (is_array($this->signals[$listKey] ?? null)) {
                $items = [];
                foreach (array_slice($this->signals[$listKey], 0, $maxItems) as $rawItem) {
                    $sanitized = self::scrubBannerString($rawItem, 200);
                    if ($sanitized !== null) {
                        $items[] = $sanitized;
                    }
                }
                $items = array_values(array_unique($items));
                if ($items !== []) {
                    $clean[$listKey] = $items;
                }
            }
        }

        foreach (self::ALLOWED_SIGNAL_STRINGS as $key => $maxLength) {
            $sanitized = self::scrubBannerString($this->signals[$key] ?? null, $maxLength);
            if ($sanitized !== null) {
                $clean[$key] = $sanitized;
            }
        }

        if (is_string($clean['arp_gateway_ip'] ?? null)
            && filter_var($clean['arp_gateway_ip'], FILTER_VALIDATE_IP) === false) {
            unset($clean['arp_gateway_ip']);
        }
        if (is_string($clean['arp_gateway_mac'] ?? null)) {
            $normGwMac = self::normaliseUnicastMac($clean['arp_gateway_mac']);
            if ($normGwMac === null) {
                unset($clean['arp_gateway_mac']);
            } else {
                $clean['arp_gateway_mac'] = $normGwMac;
            }
        }

        if (is_array($this->signals['lldp_neighbors'] ?? null)) {
            $rows = [];
            foreach (array_slice($this->signals['lldp_neighbors'], 0, 32) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $row = [];
                foreach (['local_port' => 64, 'remote_port' => 64, 'remote_name' => 128] as $field => $len) {
                    $val = self::scrubBannerString($entry[$field] ?? null, $len);
                    if ($val !== null) {
                        $row[$field] = $val;
                    }
                }
                $mac = self::normaliseUnicastMac($entry['remote_mac'] ?? null);
                if ($mac !== null) {
                    $row['remote_mac'] = $mac;
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
            if ($rows !== []) {
                $clean['lldp_neighbors'] = $rows;
            }
        }

        if (is_array($this->signals['cdp_neighbors'] ?? null)) {
            $rows = [];
            foreach (array_slice($this->signals['cdp_neighbors'], 0, 32) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $row = [];
                foreach (['local_port' => 64, 'remote_port' => 64, 'remote_name' => 128, 'platform' => 96] as $field => $len) {
                    $val = self::scrubBannerString($entry[$field] ?? null, $len);
                    if ($val !== null) {
                        $row[$field] = $val;
                    }
                }
                if (is_string($entry['remote_ip'] ?? null) && filter_var($entry['remote_ip'], FILTER_VALIDATE_IP) !== false) {
                    $row['remote_ip'] = $entry['remote_ip'];
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
            if ($rows !== []) {
                $clean['cdp_neighbors'] = $rows;
            }
        }

        if (is_array($this->signals['bridge_fdb'] ?? null)) {
            $rows = [];
            foreach (array_slice($this->signals['bridge_fdb'], 0, 48) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $port = self::scrubBannerString($entry['port'] ?? null, 64);
                $mac = self::normaliseUnicastMac($entry['mac'] ?? null);
                if ($port !== null && $mac !== null) {
                    $rows[] = ['port' => $port, 'mac' => $mac];
                }
            }
            if ($rows !== []) {
                $clean['bridge_fdb'] = $rows;
            }
        }

        if (is_array($this->signals['snmp_arp'] ?? null)) {
            $rows = [];
            foreach (array_slice($this->signals['snmp_arp'], 0, 48) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $ip = is_string($entry['ip'] ?? null) && filter_var($entry['ip'], FILTER_VALIDATE_IP) !== false ? $entry['ip'] : null;
                $mac = self::normaliseUnicastMac($entry['mac'] ?? null);
                if ($ip !== null && $mac !== null) {
                    $rows[] = ['ip' => $ip, 'mac' => $mac];
                }
            }
            if ($rows !== []) {
                $clean['snmp_arp'] = $rows;
            }
        }

        if (is_array($this->signals['unifi_uplink'] ?? null)) {
            $uplink = $this->signals['unifi_uplink'];
            $mac = self::normaliseUnicastMac($uplink['uplink_mac'] ?? null);
            $kind = in_array($uplink['kind'] ?? null, ['ethernet', 'wireless'], true) ? $uplink['kind'] : 'ethernet';
            if ($mac !== null) {
                $u = ['uplink_mac' => $mac, 'kind' => $kind];
                if (is_string($uplink['uplink_ip'] ?? null) && filter_var($uplink['uplink_ip'], FILTER_VALIDATE_IP) !== false) {
                    $u['uplink_ip'] = $uplink['uplink_ip'];
                }
                $port = self::scrubBannerString($uplink['uplink_port'] ?? null, 64);
                if ($port !== null) {
                    $u['uplink_port'] = $port;
                }
                $ssid = self::scrubBannerString($uplink['ssid'] ?? null, 64);
                if ($ssid !== null) {
                    $u['ssid'] = $ssid;
                }
                $clean['unifi_uplink'] = $u;
            }
        }

        return $clean;
    }

    private static function normaliseUnicastMac(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '');
        if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'FFFFFFFFFFFF') {
            return null;
        }
        if ((hexdec(substr($hex, 0, 2)) & 0x01) !== 0) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    public static function scrubBannerString(mixed $value, int $maxLength = 200): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($text === '') {
            return null;
        }

        $text = preg_replace(
            '/\b(?:credential|credentials|password|passwords|secret|secrets|command|commands|shell|shells)\b/i',
            '[redacted]',
            $text,
        ) ?? $text;
        $text = str_ireplace(['credential', 'password', 'secret', 'command', 'shell'], 'auth', $text);
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return strlen($text) > $maxLength ? rtrim(substr($text, 0, $maxLength)) : $text;
    }

    public function evidenceHash(): string
    {
        return hash('sha256', json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
    }

    /** @return array{type: string, value: string, weight: int, reason: string, immutable: bool} */
    private function item(string $type, string $value, int $weight, string $reason, bool $immutable): array
    {
        return [
            'type' => $type,
            'value' => DeviceIdentityEvidence::normaliseValue($type, $value),
            'weight' => $weight,
            'reason' => $reason,
            'immutable' => $immutable,
        ];
    }

    private function optional(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 2048) {
            throw new InvalidArgumentException("Discovered {$field} exceeds the bounded length.");
        }

        return strtolower($value);
    }
}
