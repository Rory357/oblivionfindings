<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryScopeValidator;
use App\Domain\Monitoring\Exceptions\EgressDenied;

final class DiscoveryApprovedProbeScopeProvider implements ApprovedProbeScopeProvider
{
    public function __construct(private readonly DiscoveryScopeValidator $validator) {}

    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        if ($siteId < 1 || $deviceId < 1) {
            throw new EgressDenied('approved probe scope is invalid');
        }

        $scopes = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->whereNull('collector_id')
            ->orderBy('id')
            ->limit(65)
            ->get();
        if ($scopes->count() > 64) {
            throw new EgressDenied('approved probe scope is ambiguous');
        }

        $cidrs = [];
        $ports = [];
        foreach ($scopes as $scope) {
            // Discovery exclusions can contain hosts, networks, MAC addresses,
            // or names and cannot be represented safely by ProbeScope. Such a
            // scope remains usable for discovery, but not as an egress grant.
            if (($scope->exclusions ?? []) !== [] || $this->validator->validateScope($scope) !== null) {
                continue;
            }

            $protocols = collect($scope->protocols ?? [])
                ->filter(fn (mixed $protocol): bool => is_string($protocol))
                ->map(fn (string $protocol): string => strtolower($protocol))
                ->all();
            foreach ($scope->cidrs ?? [] as $cidr) {
                $cidrs[] = $cidr;
            }
            foreach ($scope->port_bounds ?? [] as $protocol => $protocolPorts) {
                if (! is_string($protocol)
                    || ! in_array(strtolower($protocol), $protocols, true)
                    || ! is_array($protocolPorts)) {
                    continue;
                }
                foreach ($protocolPorts as $port) {
                    $ports[] = $port;
                }
            }
        }

        $cidrs = array_values(array_unique($cidrs));
        $ports = array_values(array_unique($ports));
        sort($cidrs, SORT_STRING);
        sort($ports, SORT_NUMERIC);
        if ($cidrs === [] || $ports === [] || count($cidrs) > 64 || count($ports) > 128) {
            throw new EgressDenied('approved probe scope is unavailable');
        }

        return new ProbeScope(
            siteId: $siteId,
            deviceId: $deviceId,
            approvedCidrs: $cidrs,
            allowedPorts: $ports,
        );
    }
}
