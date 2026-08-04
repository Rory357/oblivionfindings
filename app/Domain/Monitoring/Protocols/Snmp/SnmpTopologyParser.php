<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use Carbon\CarbonImmutable;

final class SnmpTopologyParser
{
    private const string LLDP_LOCAL = '1.0.8802.1.1.2.1.3.7.1';

    private const string LLDP_REMOTE = '1.0.8802.1.1.2.1.4.1.1';

    private const string CDP = '1.3.6.1.4.1.9.9.23.1.2.1.1';

    private const string ARP_MODERN = '1.3.6.1.2.1.4.35.1';

    private const string ARP_LEGACY = '1.3.6.1.2.1.4.22.1';

    private const string BRIDGE_PORT = '1.3.6.1.2.1.17.1.4.1';

    private const string FORWARDING = '1.3.6.1.2.1.17.4.3.1';

    private const string ROUTE_CIDR = '1.3.6.1.2.1.4.24.4.1';

    private const string ROUTE_LEGACY = '1.3.6.1.2.1.4.21.1';

    /**
     * @param  array<string, int|float|string|bool|null>  $values
     * @param  list<string>  $completedOptionalWalkRoots
     */
    public function parse(
        array $values,
        array $completedOptionalWalkRoots,
        CarbonImmutable $observedAt,
    ): SnmpTopologyParseResult {
        $completed = array_fill_keys($completedOptionalWalkRoots, true);
        $observations = [];
        $sources = [];

        if (isset($completed[self::LLDP_REMOTE])) {
            $sources[] = 'lldp';
            array_push($observations, ...$this->lldp($values, $observedAt));
        }
        if (isset($completed[self::CDP])) {
            $sources[] = 'cdp';
            array_push($observations, ...$this->cdp($values, $observedAt));
        }
        if (isset($completed[self::ARP_MODERN]) || isset($completed[self::ARP_LEGACY])) {
            $sources[] = 'arp';
            if (isset($completed[self::ARP_MODERN])) {
                array_push($observations, ...$this->modernArp($values, $observedAt));
            }
            if (isset($completed[self::ARP_LEGACY])) {
                array_push($observations, ...$this->legacyArp($values, $observedAt));
            }
        }
        if (isset($completed[self::FORWARDING])) {
            $sources[] = 'forwarding_table';
            array_push($observations, ...$this->forwarding($values, $observedAt));
        }
        if (isset($completed[self::ROUTE_CIDR]) || isset($completed[self::ROUTE_LEGACY])) {
            $sources[] = 'route';
            if (isset($completed[self::ROUTE_CIDR])) {
                array_push($observations, ...$this->cidrRoutes($values, $observedAt));
            }
            if (isset($completed[self::ROUTE_LEGACY])) {
                array_push($observations, ...$this->legacyRoutes($values, $observedAt));
            }
        }

        $deduplicated = [];
        foreach ($observations as $observation) {
            $key = hash('sha256', json_encode([
                $observation->source,
                $observation->kind,
                $observation->localPort,
                $observation->remotePort,
                $observation->remoteIdentity->evidenceHash(),
            ], JSON_THROW_ON_ERROR));
            if (! isset($deduplicated[$key])
                || $observation->confidence > $deduplicated[$key]->confidence) {
                $deduplicated[$key] = $observation;
            }
        }
        ksort($deduplicated, SORT_STRING);

        return new SnmpTopologyParseResult(
            array_values(array_slice($deduplicated, 0, 2000)),
            array_values(array_unique($sources)),
        );
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function lldp(array $values, CarbonImmutable $observedAt): array
    {
        $rows = $this->rowIndexes($values, [
            self::LLDP_REMOTE.'.4',
            self::LLDP_REMOTE.'.5',
            self::LLDP_REMOTE.'.7',
            self::LLDP_REMOTE.'.9',
        ]);
        $observations = [];
        foreach ($rows as $index) {
            $parts = explode('.', $index);
            if (count($parts) < 3) {
                continue;
            }
            $localPortNumber = $this->positiveInteger($parts[count($parts) - 2]);
            if ($localPortNumber === null) {
                continue;
            }
            $chassisSubtype = $this->positiveInteger($this->at($values, self::LLDP_REMOTE.'.4', $index));
            $chassis = $this->text($this->at($values, self::LLDP_REMOTE.'.5', $index), 255);
            $portSubtype = $this->positiveInteger($this->at($values, self::LLDP_REMOTE.'.6', $index));
            $remotePortId = $this->text($this->at($values, self::LLDP_REMOTE.'.7', $index), 128);
            $remotePortDescription = $this->text($this->at($values, self::LLDP_REMOTE.'.8', $index), 128);
            $hostname = $this->text($this->at($values, self::LLDP_REMOTE.'.9', $index), 255);
            $mac = $chassisSubtype === 4 ? $this->mac($chassis) : null;
            if ($mac === null && $portSubtype === 3) {
                $mac = $this->mac($remotePortId);
            }
            $fingerprint = $chassis !== null && $mac === null
                ? 'lldp:'.($chassisSubtype ?? 0).':'.$chassis
                : null;
            $identity = $this->identity(
                mac: $mac,
                hostname: $hostname,
                address: null,
                fingerprint: $fingerprint,
            );
            if ($identity === null) {
                continue;
            }

            $observations[] = new SnmpTopologyObservation(
                source: 'lldp',
                kind: 'ethernet',
                localPort: $this->localPort($values, $localPortNumber),
                remotePort: $remotePortDescription ?? ($portSubtype === 3 ? null : $remotePortId),
                confidence: 0.98,
                remoteIdentity: $identity,
                evidence: [
                    'protocol' => 'lldp',
                    'chassis_subtype' => $chassisSubtype,
                    'port_subtype' => $portSubtype,
                    'identity_basis' => $this->identityBasis($mac, $hostname, null, $fingerprint),
                ],
                observedAt: $observedAt,
            );
        }

        return $observations;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function cdp(array $values, CarbonImmutable $observedAt): array
    {
        $rows = $this->rowIndexes($values, [self::CDP.'.4', self::CDP.'.6', self::CDP.'.7']);
        $observations = [];
        foreach ($rows as $index) {
            $parts = explode('.', $index);
            $ifIndex = $this->positiveInteger($parts[0] ?? null);
            if ($ifIndex === null) {
                continue;
            }
            $hostname = $this->text($this->at($values, self::CDP.'.6', $index), 255);
            $platform = $this->text($this->at($values, self::CDP.'.8', $index), 128);
            $address = $this->ip($this->at($values, self::CDP.'.4', $index));
            $fingerprint = $hostname !== null || $platform !== null
                ? 'cdp:'.($hostname ?? 'unknown').':'.($platform ?? 'unknown')
                : null;
            $identity = $this->identity(null, $hostname, $address, $fingerprint);
            if ($identity === null) {
                continue;
            }

            $observations[] = new SnmpTopologyObservation(
                source: 'cdp',
                kind: 'ethernet',
                localPort: $this->localPort($values, $ifIndex),
                remotePort: $this->text($this->at($values, self::CDP.'.7', $index), 128),
                confidence: 0.95,
                remoteIdentity: $identity,
                evidence: [
                    'protocol' => 'cdp',
                    'address_type' => $this->positiveInteger($this->at($values, self::CDP.'.3', $index)),
                    'platform_present' => $platform !== null,
                    'identity_basis' => $this->identityBasis(null, $hostname, $address, $fingerprint),
                ],
                observedAt: $observedAt,
            );
        }

        return $observations;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function legacyArp(array $values, CarbonImmutable $observedAt): array
    {
        $observations = [];
        foreach ($this->column($values, self::ARP_LEGACY.'.2') as $index => $rawMac) {
            $parts = explode('.', $index);
            $ifIndex = $this->positiveInteger(array_shift($parts));
            $address = $this->ip($this->at($values, self::ARP_LEGACY.'.3', $index))
                ?? $this->ip(implode('.', $parts));
            $mac = $this->mac($rawMac);
            $identity = $this->identity($mac, null, $address, null);
            if ($ifIndex === null || $identity === null) {
                continue;
            }
            $observations[] = $this->arpObservation(
                $identity,
                $this->localPort($values, $ifIndex),
                $this->positiveInteger($this->at($values, self::ARP_LEGACY.'.4', $index)),
                $mac,
                $address,
                $observedAt,
                'ip_net_to_media',
            );
        }

        return $observations;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function modernArp(array $values, CarbonImmutable $observedAt): array
    {
        $observations = [];
        foreach ($this->column($values, self::ARP_MODERN.'.4') as $index => $rawMac) {
            $parts = explode('.', $index);
            $ifIndex = $this->positiveInteger(array_shift($parts));
            $addressType = $this->positiveInteger(array_shift($parts));
            $length = $this->positiveInteger(array_shift($parts));
            $address = $this->ip($this->at($values, self::ARP_MODERN.'.3', $index));
            if ($address === null && $length !== null && count($parts) >= $length) {
                $address = $this->ipFromBytes(array_slice($parts, 0, $length));
            }
            $mac = $this->mac($rawMac);
            $identity = $this->identity($mac, null, $address, null);
            if ($ifIndex === null || $identity === null) {
                continue;
            }
            $observation = $this->arpObservation(
                $identity,
                $this->localPort($values, $ifIndex),
                $this->positiveInteger($this->at($values, self::ARP_MODERN.'.6', $index)),
                $mac,
                $address,
                $observedAt,
                'ip_net_to_physical',
            );
            $evidence = $observation->evidence;
            $evidence['address_type'] = $addressType;
            $observations[] = new SnmpTopologyObservation(
                $observation->source,
                $observation->kind,
                $observation->localPort,
                $observation->remotePort,
                $observation->confidence,
                $observation->remoteIdentity,
                $evidence,
                $observation->observedAt,
            );
        }

        return $observations;
    }

    private function arpObservation(
        DiscoveredIdentity $identity,
        ?string $localPort,
        ?int $mappingType,
        ?string $mac,
        ?string $address,
        CarbonImmutable $observedAt,
        string $table,
    ): SnmpTopologyObservation {
        return new SnmpTopologyObservation(
            source: 'arp',
            kind: 'observed_path',
            localPort: $localPort,
            remotePort: null,
            confidence: $mac !== null ? 0.58 : 0.45,
            remoteIdentity: $identity,
            evidence: [
                'protocol' => 'arp_nd',
                'table' => $table,
                'mapping_type' => $mappingType,
                'identity_basis' => $this->identityBasis($mac, null, $address, null),
            ],
            observedAt: $observedAt,
        );
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function forwarding(array $values, CarbonImmutable $observedAt): array
    {
        $observations = [];
        foreach ($this->column($values, self::FORWARDING.'.1') as $index => $rawMac) {
            $mac = $this->mac($rawMac) ?? $this->macFromIndex($index);
            $bridgePort = $this->positiveInteger($this->at($values, self::FORWARDING.'.2', $index));
            $ifIndex = $bridgePort === null
                ? null
                : $this->positiveInteger($this->at($values, self::BRIDGE_PORT.'.2', $bridgePort));
            $identity = $this->identity($mac, null, null, null);
            if ($identity === null || $bridgePort === null) {
                continue;
            }
            $observations[] = new SnmpTopologyObservation(
                source: 'forwarding_table',
                kind: 'ethernet',
                localPort: $ifIndex === null ? "bridge-{$bridgePort}" : $this->localPort($values, $ifIndex),
                remotePort: null,
                confidence: 0.75,
                remoteIdentity: $identity,
                evidence: [
                    'protocol' => 'bridge_mib',
                    'entry_status' => $this->positiveInteger($this->at($values, self::FORWARDING.'.3', $index)),
                    'identity_basis' => 'mac',
                ],
                observedAt: $observedAt,
            );
        }

        return $observations;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function legacyRoutes(array $values, CarbonImmutable $observedAt): array
    {
        $observations = [];
        foreach ($this->column($values, self::ROUTE_LEGACY.'.7') as $destination => $rawNextHop) {
            $nextHop = $this->usableNextHop($rawNextHop);
            $ifIndex = $this->positiveInteger($this->at($values, self::ROUTE_LEGACY.'.2', $destination));
            if ($nextHop === null || $ifIndex === null) {
                continue;
            }
            $mask = $this->ip($this->at($values, self::ROUTE_LEGACY.'.11', $destination));
            $observations[] = $this->routeObservation(
                $nextHop,
                $this->localPort($values, $ifIndex),
                $destination,
                $mask,
                $this->positiveInteger($this->at($values, self::ROUTE_LEGACY.'.8', $destination)),
                $this->positiveInteger($this->at($values, self::ROUTE_LEGACY.'.9', $destination)),
                $observedAt,
                'ip_route_mib',
            );
        }

        return $observations;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpTopologyObservation> */
    private function cidrRoutes(array $values, CarbonImmutable $observedAt): array
    {
        $observations = [];
        foreach ($this->column($values, self::ROUTE_CIDR.'.4') as $index => $rawNextHop) {
            $nextHop = $this->usableNextHop($rawNextHop);
            $ifIndex = $this->positiveInteger($this->at($values, self::ROUTE_CIDR.'.5', $index));
            if ($nextHop === null || $ifIndex === null) {
                continue;
            }
            $destination = $this->ip($this->at($values, self::ROUTE_CIDR.'.1', $index));
            $mask = $this->ip($this->at($values, self::ROUTE_CIDR.'.2', $index));
            $observations[] = $this->routeObservation(
                $nextHop,
                $this->localPort($values, $ifIndex),
                $destination,
                $mask,
                $this->positiveInteger($this->at($values, self::ROUTE_CIDR.'.6', $index)),
                $this->positiveInteger($this->at($values, self::ROUTE_CIDR.'.7', $index)),
                $observedAt,
                'ip_cidr_route',
            );
        }

        return $observations;
    }

    private function routeObservation(
        string $nextHop,
        ?string $localPort,
        ?string $destination,
        ?string $mask,
        ?int $type,
        ?int $protocol,
        CarbonImmutable $observedAt,
        string $table,
    ): SnmpTopologyObservation {
        $prefix = ($destination ?? 'unknown').'/'.($mask ?? 'unknown');

        return new SnmpTopologyObservation(
            source: 'route',
            kind: 'route',
            localPort: $localPort,
            remotePort: null,
            confidence: 0.50,
            remoteIdentity: $this->identity(null, null, $nextHop, null),
            evidence: [
                'protocol' => 'routing_table',
                'table' => $table,
                'route_type' => $type,
                'route_protocol' => $protocol,
                'prefix_hash' => hash('sha256', $prefix),
                'prefix_length' => $this->prefixLength($mask),
                'identity_basis' => 'address',
            ],
            observedAt: $observedAt,
        );
    }

    /** @param array<string, int|float|string|bool|null> $values */
    private function localPort(array $values, int $ifIndex): string
    {
        return $this->text($values['1.3.6.1.2.1.31.1.1.1.1.'.$ifIndex] ?? null, 128)
            ?? $this->text($values['1.3.6.1.2.1.2.2.1.2.'.$ifIndex] ?? null, 128)
            ?? $this->text($values[self::LLDP_LOCAL.'.4.'.$ifIndex] ?? null, 128)
            ?? $this->text($values[self::LLDP_LOCAL.'.3.'.$ifIndex] ?? null, 128)
            ?? "if-{$ifIndex}";
    }

    private function identity(
        ?string $mac,
        ?string $hostname,
        ?string $address,
        ?string $fingerprint,
    ): ?DiscoveredIdentity {
        if ($mac === null && $hostname === null && $address === null && $fingerprint === null) {
            return null;
        }

        return new DiscoveredIdentity(
            provider: null,
            providerId: null,
            serialNumber: null,
            hardwareId: null,
            macAddresses: $mac === null ? [] : [$mac],
            certificateFingerprint: null,
            hostname: $hostname,
            addresses: $address === null ? [] : [$address],
            fingerprint: $fingerprint,
        );
    }

    private function identityBasis(?string $mac, ?string $hostname, ?string $address, ?string $fingerprint): string
    {
        return implode('_', array_keys(array_filter([
            'mac' => $mac,
            'hostname' => $hostname,
            'address' => $address,
            'fingerprint' => $fingerprint,
        ], fn (?string $value): bool => $value !== null)));
    }

    /** @param array<string, int|float|string|bool|null> $values @param list<string> $roots @return list<string> */
    private function rowIndexes(array $values, array $roots): array
    {
        $rows = [];
        foreach ($roots as $root) {
            foreach (array_keys($this->column($values, $root)) as $index) {
                $rows[$index] = true;
            }
        }
        ksort($rows, SORT_NATURAL);

        return array_keys($rows);
    }

    /** @param array<string, int|float|string|bool|null> $values @return array<string, int|float|string|bool|null> */
    private function column(array $values, string $root): array
    {
        $column = [];
        $prefix = $root.'.';
        foreach ($values as $oid => $value) {
            if (str_starts_with($oid, $prefix)) {
                $index = substr($oid, strlen($prefix));
                if ($index !== '' && preg_match('/^\d+(?:\.\d+)*$/', $index) === 1) {
                    $column[$index] = $value;
                }
            }
        }

        return $column;
    }

    /** @param array<string, int|float|string|bool|null> $values */
    private function at(array $values, string $root, string|int $index): int|float|string|bool|null
    {
        return $values[$root.'.'.$index] ?? null;
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/^(?:STRING|HEX-STRING|OID):\s*/i', '', trim($value, " \t\n\r\0\x0B\"")) ?? '';
        if ($value === '' || str_starts_with(strtolower($value), 'hex:') || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return strtolower($value);
    }

    private function mac(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $value = preg_replace('/^(?:hex-string:\s*|hex:)/i', '', $value) ?? '';
        $hex = preg_replace('/[^a-f0-9]/', '', $value) ?? '';
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    private function macFromIndex(string $index): ?string
    {
        $parts = explode('.', $index);
        if (count($parts) < 6) {
            return null;
        }
        $bytes = array_slice($parts, -6);
        if (collect($bytes)->contains(fn (string $byte): bool => ! ctype_digit($byte) || (int) $byte > 255)) {
            return null;
        }

        return implode(':', array_map(fn (string $byte): string => sprintf('%02x', (int) $byte), $bytes));
    }

    private function ip(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return strtolower($value);
        }
        $hex = preg_replace('/^(?:hex-string:\s*|hex:)/i', '', strtolower($value)) ?? '';
        $hex = preg_replace('/[^a-f0-9]/', '', $hex) ?? '';
        if (! in_array(strlen($hex), [8, 32], true)) {
            return null;
        }
        $packed = hex2bin($hex);
        if ($packed === false) {
            return null;
        }
        $address = inet_ntop($packed);

        return is_string($address) ? strtolower($address) : null;
    }

    /** @param list<string> $bytes */
    private function ipFromBytes(array $bytes): ?string
    {
        if (! in_array(count($bytes), [4, 16], true)
            || collect($bytes)->contains(fn (string $byte): bool => ! ctype_digit($byte) || (int) $byte > 255)) {
            return null;
        }
        $packed = pack('C*', ...array_map('intval', $bytes));
        $address = inet_ntop($packed);

        return is_string($address) ? strtolower($address) : null;
    }

    private function usableNextHop(mixed $value): ?string
    {
        $address = $this->ip($value);
        if ($address === null || in_array($address, ['0.0.0.0', '::', '127.0.0.1', '::1'], true)) {
            return null;
        }

        return $address;
    }

    private function prefixLength(?string $mask): ?int
    {
        if ($mask === null || filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $packed = inet_pton($mask);
        if ($packed === false) {
            return null;
        }
        $bits = str_pad(decbin(unpack('N', $packed)[1]), 32, '0', STR_PAD_LEFT);
        if (preg_match('/^1*0*$/', $bits) !== 1) {
            return null;
        }

        return substr_count($bits, '1');
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/(?:\(|^|:\s*)(\d+)\)?\s*$/', trim($value), $match) === 1) {
            $integer = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $integer === false ? null : $integer;
        }

        return null;
    }
}
