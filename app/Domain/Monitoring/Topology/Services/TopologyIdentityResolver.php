<?php

namespace App\Domain\Monitoring\Topology\Services;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DeviceIdentityMatcher;
use UnexpectedValueException;

final class TopologyIdentityResolver
{
    public function __construct(private readonly DeviceIdentityMatcher $identityMatcher) {}

    /** @return array{device_id: ?int, candidate_id: null, identity_hash: ?string} */
    public function resolve(int $siteId, DiscoveredIdentity $identity): array
    {
        $scope = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
        if ($scope === null) {
            return ['device_id' => null, 'candidate_id' => null, 'identity_hash' => $identity->evidenceHash()];
        }

        $match = $this->identityMatcher->match($scope, $identity);
        if (in_array($match->decision, ['rejected', 'excluded'], true)) {
            throw new UnexpectedValueException('Topology identity is outside the approved Site scope.');
        }

        $canonicalDeviceId = ($match->decision === 'matched'
            || ($match->decision === 'review' && $match->deviceId !== null && $match->confidence >= 80))
                ? $match->deviceId
                : null;

        return [
            'device_id' => $canonicalDeviceId,
            'candidate_id' => null,
            'identity_hash' => $canonicalDeviceId === null ? $identity->evidenceHash() : null,
        ];
    }
}
