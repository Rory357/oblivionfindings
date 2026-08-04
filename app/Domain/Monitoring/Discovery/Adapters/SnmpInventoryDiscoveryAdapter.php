<?php

namespace App\Domain\Monitoring\Discovery\Adapters;

use App\Domain\Monitoring\Adapters\SnmpV3ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Services\EgressPolicy;
use Throwable;

final class SnmpInventoryDiscoveryAdapter
{
    public function __construct(
        private readonly EgressPolicy $egress,
        private readonly SnmpV3ProbeAdapter $snmp,
    ) {}

    public function discover(DiscoveryScope $scope, DiscoveryTarget $target): ?DiscoveredIdentity
    {
        if ($scope->collector_id !== null || ! is_string($scope->snmp_credential_reference)
            || $scope->snmp_credential_reference === '') {
            return null;
        }

        try {
            $authorised = $this->egress->authoriseDiscovery(
                (int) $scope->site_id,
                array_values($scope->cidrs ?? []),
                [161],
                ProbeTarget::snmp($target->host),
            );
            $poll = $this->snmp->poll(new AuthorisedProbeContext(
                monitorId: 0,
                siteId: (int) $scope->site_id,
                deviceId: 0,
                kind: MonitorKind::Snmp,
                target: $authorised,
                config: [
                    'version' => 'v3',
                    'credential_reference' => $scope->snmp_credential_reference,
                ],
            ));
        } catch (Throwable) {
            return null;
        }

        return in_array($poll->summary->state, [MonitorState::Healthy, MonitorState::Degraded], true)
            ? $poll->identity
            : null;
    }
}
