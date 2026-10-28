<?php

namespace App\Domain\Monitoring\Discovery\Adapters;

use App\Domain\Monitoring\Adapters\SnmpV3ProbeAdapter;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Support\SnmpSystemProbe;
use App\Domain\Monitoring\Discovery\Support\SnmpTopologyProbe;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Services\EgressPolicy;
use Throwable;

final class SnmpInventoryDiscoveryAdapter
{
    public function __construct(
        private readonly EgressPolicy $egress,
        private readonly SnmpV3ProbeAdapter $snmp,
        private readonly ?CredentialLeaseProvider $credentials = null,
        private readonly SnmpSystemProbe $snmpProbe = new SnmpSystemProbe,
        private readonly SnmpTopologyProbe $topologyProbe = new SnmpTopologyProbe,
    ) {}

    public function discover(DiscoveryScope $scope, DiscoveryTarget $target): ?DiscoveredIdentity
    {
        if ($scope->collector_id !== null) {
            return null;
        }

        try {
            $authorised = $this->egress->authoriseDiscovery(
                (int) $scope->site_id,
                array_values($scope->cidrs ?? []),
                [161],
                ProbeTarget::snmp($target->host),
            );
        } catch (Throwable) {
            return null;
        }

        $reference = is_string($scope->snmp_credential_reference)
            ? trim($scope->snmp_credential_reference)
            : '';

        if ($reference !== '') {
            $poll = null;
            try {
                $poll = $this->snmp->poll(new AuthorisedProbeContext(
                    monitorId: 0,
                    siteId: (int) $scope->site_id,
                    deviceId: 0,
                    kind: MonitorKind::Snmp,
                    target: $authorised,
                    config: [
                        'version' => 'v3',
                        'credential_reference' => $reference,
                    ],
                ));
            } catch (Throwable) {
                $poll = null;
            }

            if ($poll !== null
                && in_array($poll->summary->state, [MonitorState::Healthy, MonitorState::Degraded], true)
                && $poll->identity !== null) {
                $evidence = $poll->summary->evidence;
                $signals = array_filter([
                    'snmp_sys_object_id' => is_string($evidence['system_object_id'] ?? null) ? $evidence['system_object_id'] : null,
                    'snmp_sys_name' => is_string($evidence['system_name'] ?? null) ? $evidence['system_name'] : null,
                    'snmp_manufacturer' => is_string($evidence['manufacturer'] ?? null) ? $evidence['manufacturer'] : null,
                    'snmp_model' => is_string($evidence['model'] ?? null) ? $evidence['model'] : null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');

                return $signals === [] ? $poll->identity : new DiscoveredIdentity(
                    provider: $poll->identity->provider,
                    providerId: $poll->identity->providerId,
                    serialNumber: $poll->identity->serialNumber,
                    hardwareId: $poll->identity->hardwareId,
                    macAddresses: $poll->identity->macAddresses,
                    certificateFingerprint: $poll->identity->certificateFingerprint,
                    hostname: $poll->identity->hostname,
                    addresses: $poll->identity->addresses,
                    fingerprint: $poll->identity->fingerprint,
                    signals: $signals,
                );
            }

            if ($this->credentials !== null
                && ($poll === null || $poll->summary->reasonCode === 'snmp_transport_unavailable')) {
                return $this->probeWithPurePhpLease((int) $scope->site_id, $reference, array_values($authorised->addresses));
            }

            return null;
        }

        foreach ($authorised->addresses as $address) {
            $system = $this->snmpProbe->probeV2c($address, 'public', 161, 0.35);
            if ($system !== null) {
                $topo = $this->topologyProbe->probeTopology($address, null, 161, 0.35);

                return $this->identityFromSystemMib($system, array_values($authorised->addresses), $topo);
            }
        }

        return null;
    }

    /** @param list<string> $addresses */
    private function probeWithPurePhpLease(int $siteId, string $reference, array $addresses): ?DiscoveredIdentity
    {
        $lease = $this->acquireSnmpLease($siteId, $reference);
        if ($lease === null) {
            return null;
        }

        $material = null;
        try {
            $material = $lease->material();
            foreach ($addresses as $address) {
                $system = (isset($material['security_name']) || isset($material['username']))
                    ? $this->snmpProbe->probeV3($address, $material, 161, 0.75)
                    : (is_string($material['community'] ?? null) && $material['community'] !== ''
                        ? $this->snmpProbe->probeV2c($address, $material['community'], 161, 0.5)
                        : null);
                if ($system !== null) {
                    $topo = $this->topologyProbe->probeTopology($address, $material, 161, 0.35);

                    return $this->identityFromSystemMib($system, $addresses, $topo);
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_array($material)) {
                foreach ($material as &$value) {
                    if (is_string($value) && $value !== '' && function_exists('sodium_memzero')) {
                        sodium_memzero($value);
                    }
                    $value = null;
                }
                unset($value);
            }
        }
    }

    private function acquireSnmpLease(int $siteId, string $reference): ?CredentialLease
    {
        if ($this->credentials === null) {
            return null;
        }

        foreach ([['snmp:v3:auth_priv'], ['snmp:v2c:compatibility'], ['monitoring.collector.snmp.read']] as $capabilities) {
            try {
                return $this->credentials->acquire($siteId, $reference, $capabilities);
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @param  array{sys_descr: ?string, sys_object_id: ?string, sys_name: ?string}  $system
     * @param  list<string>  $addresses
     * @param  array<string, mixed>  $topology
     */
    private function identityFromSystemMib(array $system, array $addresses, array $topology = []): DiscoveredIdentity
    {
        $objectId = is_string($system['sys_object_id'] ?? null) && trim($system['sys_object_id']) !== ''
            ? ltrim(trim($system['sys_object_id']), '.')
            : null;
        $sysName = is_string($system['sys_name'] ?? null) && trim($system['sys_name']) !== ''
            ? trim($system['sys_name'])
            : null;
        $sysDescr = is_string($system['sys_descr'] ?? null) && trim($system['sys_descr']) !== ''
            ? trim($system['sys_descr'])
            : null;

        $signals = array_filter([
            'snmp_sys_descr' => $sysDescr,
            'snmp_sys_object_id' => $objectId,
            'snmp_sys_name' => $sysName,
            ...$topology,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return new DiscoveredIdentity(
            provider: 'snmp',
            providerId: null,
            serialNumber: null,
            hardwareId: null,
            macAddresses: [],
            certificateFingerprint: null,
            hostname: $sysName,
            addresses: $addresses,
            fingerprint: $objectId !== null ? "snmp:{$objectId}" : 'snmp:system',
            signals: $signals,
        );
    }
}
