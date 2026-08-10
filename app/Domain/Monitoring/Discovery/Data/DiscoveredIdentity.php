<?php

namespace App\Domain\Monitoring\Discovery\Data;

use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use InvalidArgumentException;

final readonly class DiscoveredIdentity
{
    /**
     * @param  list<string>  $macAddresses
     * @param  list<string>  $addresses
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
