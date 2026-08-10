<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\IdentityMatchResult;
use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Support\Collection;
use Throwable;

final class DeviceIdentityMatcher
{
    public function __construct(
        private readonly DiscoveryScopeValidator $scopeValidator,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
    ) {}

    public function match(DiscoveryScope $scope, DiscoveredIdentity $identity): IdentityMatchResult
    {
        if (($preflight = $this->scopeValidator->preflight($scope, $identity)) !== null) {
            return new IdentityMatchResult(
                decision: $preflight['decision'],
                deviceId: null,
                confidence: 0,
                reasons: [$preflight['reason']],
            );
        }

        /** @var array<int, array{score: int, reasons: list<string>, hashes: list<string>, immutable: bool}> $matches */
        $matches = [];
        $scopeMismatch = false;
        foreach ($identity->evidence() as $item) {
            $ids = $this->candidateDeviceIds($item['type'], $item['value']);
            foreach ($ids as $deviceId) {
                try {
                    $siteId = $this->siteResolver->resolve($deviceId);
                } catch (Throwable) {
                    $scopeMismatch = true;

                    continue;
                }
                if ($siteId !== (int) $scope->site_id) {
                    $scopeMismatch = true;

                    continue;
                }

                $matches[$deviceId] ??= [
                    'score' => 0,
                    'reasons' => [],
                    'hashes' => [],
                    'immutable' => false,
                ];
                $matches[$deviceId]['score'] = max($matches[$deviceId]['score'], $item['weight']);
                $matches[$deviceId]['reasons'][] = $item['reason'];
                $matches[$deviceId]['hashes'][] = DeviceIdentityEvidence::hashValue($item['type'], $item['value']);
                $matches[$deviceId]['immutable'] = $matches[$deviceId]['immutable'] || $item['immutable'];
            }
        }

        $immutable = array_filter($matches, fn (array $match): bool => $match['immutable'] && $match['score'] >= 90);
        if (count($immutable) > 1) {
            return new IdentityMatchResult(
                decision: 'review',
                deviceId: null,
                confidence: max(array_column($immutable, 'score')),
                reasons: ['conflicting_immutable_evidence'],
                matchedEvidenceHashes: $this->hashes($immutable),
            );
        }
        if (count($immutable) === 1) {
            $deviceId = (int) array_key_first($immutable);
            $match = $immutable[$deviceId];

            return new IdentityMatchResult(
                decision: 'matched',
                deviceId: $deviceId,
                confidence: $match['score'],
                reasons: array_values(array_unique($match['reasons'])),
                matchedEvidenceHashes: array_values(array_unique($match['hashes'])),
            );
        }
        if ($matches !== []) {
            uasort($matches, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $deviceId = (int) array_key_first($matches);
            $top = $matches[$deviceId];
            $tied = collect($matches)->where('score', $top['score'])->count() > 1;

            return new IdentityMatchResult(
                decision: 'review',
                deviceId: $tied ? null : $deviceId,
                confidence: $top['score'],
                reasons: $tied
                    ? ['mutable_evidence_ambiguous']
                    : array_values(array_unique($top['reasons'])),
                matchedEvidenceHashes: $this->hashes($matches),
            );
        }
        if ($scopeMismatch) {
            return new IdentityMatchResult(
                decision: 'rejected',
                deviceId: null,
                confidence: 0,
                reasons: ['canonical_site_mismatch'],
            );
        }

        return new IdentityMatchResult(
            decision: 'proposed',
            deviceId: null,
            confidence: 0,
            reasons: ['no_existing_identity_match'],
        );
    }

    /** @return list<int> */
    private function candidateDeviceIds(string $type, string $value): array
    {
        $ids = DeviceIdentityEvidence::query()
            ->where('evidence_type', $type)
            ->where('value_hash', DeviceIdentityEvidence::hashValue($type, $value))
            ->where('is_active', true)
            ->pluck('canonical_device_id');

        $direct = match ($type) {
            'provider_id' => $this->providerIds($value),
            'serial_number' => Device::query()->where('serial_number', $value)->pluck('id'),
            'mac_address' => Device::query()->whereNotNull('mac_address')->get(['id', 'mac_address'])
                ->filter(fn (Device $device): bool => $this->normalisesTo('mac_address', $device->mac_address, $value))
                ->pluck('id'),
            'device_fingerprint' => Device::query()->whereNotNull('manufacturer')->whereNotNull('model')
                ->get(['id', 'manufacturer', 'model'])
                ->filter(fn (Device $device): bool => $this->normalisesTo(
                    'device_fingerprint',
                    "{$device->manufacturer}:{$device->model}",
                    $value,
                ))
                ->pluck('id'),
            'hostname' => Device::query()->where('name', $value)->pluck('id'),
            'address_history' => Device::query()->where('ip_address', $value)->pluck('id'),
            default => collect(),
        };

        return $ids->merge($direct)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function providerIds(string $value): Collection
    {
        [$provider, $providerId] = array_pad(explode(':', $value, 2), 2, null);
        if ($provider === null || $providerId === null) {
            return collect();
        }

        return Device::query()
            ->where('provider', $provider)
            ->where('external_ref->provider_entity_id', $providerId)
            ->pluck('id');
    }

    private function normalisesTo(string $type, mixed $candidate, string $expected): bool
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return false;
        }
        try {
            return hash_equals($expected, DeviceIdentityEvidence::normaliseValue($type, $candidate));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int, array{hashes: list<string>}> $matches @return list<string> */
    private function hashes(array $matches): array
    {
        return collect($matches)
            ->flatMap(fn (array $match): array => $match['hashes'])
            ->unique()
            ->values()
            ->all();
    }
}
