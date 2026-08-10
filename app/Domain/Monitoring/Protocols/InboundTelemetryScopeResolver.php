<?php

namespace App\Domain\Monitoring\Protocols;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use RuntimeException;
use Throwable;

final class InboundTelemetryScopeResolver
{
    private const array PROTOCOLS = ['flow', 'syslog'];

    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly CanonicalDeviceSiteResolver $deviceSiteResolver,
    ) {}

    public function resolve(string $senderAddress, string $protocol): InboundTelemetryScope
    {
        if (! in_array($protocol, self::PROTOCOLS, true)) {
            throw new RuntimeException('Inbound telemetry protocol is unsupported.');
        }
        try {
            $senderAddress = $this->cidrs->canonicalAddress($senderAddress);
        } catch (Throwable) {
            throw new RuntimeException('Inbound telemetry sender address is invalid.');
        }
        $scopes = DiscoveryScope::query()
            ->where('status', 'active')
            ->whereNull('collector_id')
            ->whereJsonContains('protocols', $protocol)
            ->whereHas('site', fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($site) => $site->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->orderBy('id')
            ->limit(1001)
            ->get();
        if ($scopes->count() > 1000) {
            throw new RuntimeException('Inbound telemetry sender does not resolve to one approved Site scope.');
        }
        $matches = $scopes->filter(function (DiscoveryScope $scope) use ($senderAddress): bool {
            foreach ($scope->cidrs ?? [] as $cidr) {
                try {
                    if (is_string($cidr) && $this->cidrs->contains($cidr, $senderAddress)) {
                        return true;
                    }
                } catch (Throwable) {
                    return false;
                }
            }

            return false;
        })->values();
        if ($matches->count() !== 1) {
            throw new RuntimeException('Inbound telemetry sender does not resolve to one approved Site scope.');
        }
        /** @var DiscoveryScope $scope */
        $scope = $matches->first();

        return new InboundTelemetryScope(
            siteId: (int) $scope->site_id,
            scopeId: (int) $scope->id,
            candidateDeviceId: $this->candidateDevice($senderAddress, (int) $scope->site_id),
        );
    }

    private function candidateDevice(string $senderAddress, int $siteId): ?int
    {
        $matches = Device::query()
            ->where('ip_address', $senderAddress)
            ->whereIn('status', [
                DeviceStatus::Active->value,
                DeviceStatus::Degraded->value,
                DeviceStatus::Offline->value,
            ])
            ->orderBy('id')
            ->limit(3)
            ->get(['id'])
            ->filter(function (Device $device) use ($siteId): bool {
                try {
                    return $this->deviceSiteResolver->resolve((int) $device->id) === $siteId;
                } catch (Throwable) {
                    return false;
                }
            })
            ->values();

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }
}
