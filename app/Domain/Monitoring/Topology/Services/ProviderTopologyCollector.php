<?php

namespace App\Domain\Monitoring\Topology\Services;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Exceptions\ProviderTopologyDeferred;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

final class ProviderTopologyCollector
{
    public function __construct(
        private readonly IntegrationAdapterRegistry $registry,
        private readonly TopologyIdentityResolver $identities,
    ) {}

    /** @return list<TopologyEvidence> */
    public function collect(int $siteId, string $provider): array
    {
        $capability = $this->registry->capability($provider, TopologyCollectionCapability::class);
        if (! $capability instanceof TopologyCollectionCapability) {
            throw new UnexpectedValueException('Provider topology capability is unavailable.');
        }

        $siteConfig = IntegrationSiteConfig::query()
            ->forProvider($provider)
            ->active()
            ->where('site_id', $siteId)
            ->whereHas('site', fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($siteQuery) => $siteQuery->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->first();
        $connection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->connected()
            ->first();
        if ($siteConfig === null || $connection === null) {
            throw new UnexpectedValueException('Provider topology Site scope is unavailable.');
        }

        $manifest = $this->registry->manifest($provider);
        $maximum = min(5000, $manifest->backfillLimit);
        $cursor = null;
        $seenCursors = [];
        $nodes = [];
        $evidence = [];

        do {
            $cursorKey = $cursor ?? '__first__';
            if (isset($seenCursors[$cursorKey]) || count($seenCursors) >= 1000) {
                throw new UnexpectedValueException('Provider topology cursor did not advance.');
            }
            $seenCursors[$cursorKey] = true;
            $page = $capability->collectTopology(
                $siteConfig,
                $connection,
                $cursor,
                min($manifest->pageLimit, max(1, $maximum - count($evidence))),
            );
            if ($page->partial || $page->retryAfterSeconds !== null) {
                throw new ProviderTopologyDeferred($page->retryAfterSeconds ?? $manifest->minimumIntervalSeconds);
            }

            foreach ($page->nodes as $node) {
                $this->collectNode($siteId, $provider, $node, $nodes);
            }
            foreach ($page->edges as $edge) {
                if (count($evidence) >= $maximum) {
                    throw new UnexpectedValueException('Provider topology backfill exceeds the declared bound.');
                }
                $evidence[] = $this->collectEdge($provider, $edge, $nodes);
            }

            $next = $page->nextCursor;
            if ($next !== null && isset($seenCursors[$next])) {
                throw new UnexpectedValueException('Provider topology cursor did not advance.');
            }
            $cursor = $next;
        } while ($cursor !== null);

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array{device_id: ?int, candidate_id: ?int, identity_hash: ?string}>  $nodes
     */
    private function collectNode(int $siteId, string $provider, array $node, array &$nodes): void
    {
        $allowed = ['key', 'device_id', 'candidate_id', 'identity_hash', 'identity'];
        $key = $node['key'] ?? null;
        if (array_diff(array_keys($node), $allowed) !== []
            || ! is_string($key)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new InvalidArgumentException('Provider topology node is invalid.');
        }
        $locatorCount = collect(['device_id', 'candidate_id', 'identity_hash', 'identity'])
            ->filter(fn (string $field): bool => array_key_exists($field, $node) && $node[$field] !== null)
            ->count();
        if ($locatorCount !== 1) {
            throw new InvalidArgumentException('Provider topology node is invalid.');
        }

        $locator = match (true) {
            array_key_exists('device_id', $node) => $this->directLocator($node['device_id'], 'device_id'),
            array_key_exists('candidate_id', $node) => $this->directLocator($node['candidate_id'], 'candidate_id'),
            array_key_exists('identity_hash', $node) => $this->hashLocator($node['identity_hash']),
            default => $this->identityLocator($siteId, $provider, $node['identity']),
        };
        if (isset($nodes[$key]) && $nodes[$key] !== $locator) {
            throw new UnexpectedValueException('Provider topology node identity changed within one checkpoint.');
        }
        if (count($nodes) >= 5000 && ! isset($nodes[$key])) {
            throw new UnexpectedValueException('Provider topology nodes exceed the bounded limit.');
        }
        $nodes[$key] = $locator;
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, array{device_id: ?int, candidate_id: ?int, identity_hash: ?string}>  $nodes
     */
    private function collectEdge(string $provider, array $edge, array $nodes): TopologyEvidence
    {
        $allowed = [
            'from', 'to', 'source', 'kind', 'local_port', 'remote_port', 'confidence', 'evidence', 'observed_at',
        ];
        if (array_diff(array_keys($edge), $allowed) !== []
            || ! is_string($edge['from'] ?? null)
            || ! is_string($edge['to'] ?? null)
            || ! isset($nodes[$edge['from']], $nodes[$edge['to']])
            || ! is_string($edge['source'] ?? null)
            || ! is_string($edge['kind'] ?? null)
            || ! is_array($edge['evidence'] ?? null)
            || ! is_int($edge['confidence'] ?? null) && ! is_float($edge['confidence'] ?? null)) {
            throw new InvalidArgumentException('Provider topology edge is invalid.');
        }
        $from = $nodes[$edge['from']];
        $to = $nodes[$edge['to']];
        $evidence = $edge['evidence'];
        if (array_key_exists('provider', $evidence) && $evidence['provider'] !== $provider) {
            throw new InvalidArgumentException('Provider topology edge is invalid.');
        }
        $evidence['provider'] = $provider;

        $observedAt = null;
        if (($edge['observed_at'] ?? null) !== null) {
            if (! is_string($edge['observed_at']) || strlen($edge['observed_at']) > 64) {
                throw new InvalidArgumentException('Provider topology edge is invalid.');
            }
            try {
                $observedAt = CarbonImmutable::parse($edge['observed_at'])->utc();
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException('Provider topology edge is invalid.', previous: $exception);
            }
        }

        return new TopologyEvidence(
            source: $edge['source'],
            fromDeviceId: $from['device_id'],
            toDeviceId: $to['device_id'],
            kind: $edge['kind'],
            localPort: $this->optionalString($edge['local_port'] ?? null),
            remotePort: $this->optionalString($edge['remote_port'] ?? null),
            confidence: (float) $edge['confidence'],
            evidence: $evidence,
            fromCandidateId: $from['candidate_id'],
            toCandidateId: $to['candidate_id'],
            fromObservedIdentityHash: $from['identity_hash'],
            toObservedIdentityHash: $to['identity_hash'],
            observedAt: $observedAt,
        );
    }

    /** @return array{device_id: ?int, candidate_id: ?int, identity_hash: ?string} */
    private function directLocator(mixed $value, string $field): array
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Provider topology node is invalid.');
        }

        return [
            'device_id' => $field === 'device_id' ? $value : null,
            'candidate_id' => $field === 'candidate_id' ? $value : null,
            'identity_hash' => null,
        ];
    }

    /** @return array{device_id: null, candidate_id: null, identity_hash: string} */
    private function hashLocator(mixed $value): array
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Provider topology node is invalid.');
        }

        return ['device_id' => null, 'candidate_id' => null, 'identity_hash' => $value];
    }

    /** @return array{device_id: ?int, candidate_id: null, identity_hash: ?string} */
    private function identityLocator(int $siteId, string $provider, mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Provider topology identity is invalid.');
        }
        $allowed = [
            'provider', 'provider_id', 'serial_number', 'hardware_id', 'mac_addresses',
            'certificate_fingerprint', 'hostname', 'addresses', 'fingerprint',
        ];
        if (array_diff(array_keys($payload), $allowed) !== []
            || isset($payload['provider']) && $payload['provider'] !== $provider) {
            throw new InvalidArgumentException('Provider topology identity is invalid.');
        }

        $identity = new DiscoveredIdentity(
            provider: $provider,
            providerId: $this->optionalString($payload['provider_id'] ?? null),
            serialNumber: $this->optionalString($payload['serial_number'] ?? null),
            hardwareId: $this->optionalString($payload['hardware_id'] ?? null),
            macAddresses: $this->stringList($payload['mac_addresses'] ?? []),
            certificateFingerprint: $this->optionalString($payload['certificate_fingerprint'] ?? null),
            hostname: $this->optionalString($payload['hostname'] ?? null),
            addresses: $this->stringList($payload['addresses'] ?? []),
            fingerprint: $this->optionalString($payload['fingerprint'] ?? null),
        );

        return $this->identities->resolve($siteId, $identity);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)
            || collect($value)->contains(fn (mixed $item): bool => ! is_string($item))) {
            throw new InvalidArgumentException('Provider topology identity is invalid.');
        }

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Provider topology value is invalid.');
        }

        return $value;
    }
}
