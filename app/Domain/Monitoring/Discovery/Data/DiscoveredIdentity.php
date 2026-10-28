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

        foreach (['locally_administered_mac', 'icmp_reachable', 'arp_seen'] as $boolKey) {
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

        return $clean;
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
