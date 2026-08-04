<?php

namespace App\Domain\Monitoring\Topology\Services;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Models\TopologyNode;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Models\Site;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnexpectedValueException;

final class NativeSnmpTopologyProjector
{
    private const array SOURCES = ['lldp', 'cdp', 'arp', 'forwarding_table', 'route'];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly TopologyIdentityResolver $identities,
        private readonly TopologySnapshotBuilder $builder,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<string>  $completedSources
     */
    public function project(
        int $siteId,
        int $deviceId,
        string $checkpoint,
        array $payloads,
        array $completedSources,
    ): TopologySnapshot {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->first();
        if ($site === null || $this->siteResolver->resolve($deviceId) !== $siteId) {
            throw new UnexpectedValueException('Native SNMP topology Site scope is unavailable.');
        }
        if ($checkpoint === '' || strlen($checkpoint) > 2048
            || ! array_is_list($payloads) || count($payloads) > 2000
            || ! array_is_list($completedSources)
            || collect($completedSources)->unique()->count() !== count($completedSources)
            || collect($completedSources)->contains(
                fn (mixed $source): bool => ! is_string($source) || ! in_array($source, self::SOURCES, true),
            )) {
            throw new InvalidArgumentException('Native SNMP topology projection input is invalid.');
        }

        $observations = collect($payloads)
            ->map(fn (mixed $payload): SnmpTopologyObservation => is_array($payload)
                ? SnmpTopologyObservation::fromArray($payload)
                : throw new InvalidArgumentException('Native SNMP topology projection input is invalid.'));
        if ($observations->contains(
            fn (SnmpTopologyObservation $item): bool => ! in_array($item->source, $completedSources, true),
        )) {
            throw new InvalidArgumentException('Native SNMP topology projection input is invalid.');
        }

        foreach ($completedSources as $source) {
            $evidence = $observations
                ->where('source', $source)
                ->map(fn (SnmpTopologyObservation $item): ?TopologyEvidence => $this->evidence(
                    $siteId,
                    $deviceId,
                    $item,
                ))
                ->filter()
                ->values()
                ->all();
            $this->builder->build(
                $site,
                $evidence,
                source: "native:snmp:{$source}:device:{$deviceId}",
                sourceCheckpoint: $checkpoint,
            );
        }

        $sourceSnapshots = $this->latestSourceSnapshots($siteId);
        $aggregateEvidence = $sourceSnapshots
            ->flatMap(fn (TopologySnapshot $snapshot): array => $this->snapshotEvidence($snapshot))
            ->values()
            ->all();
        $aggregateCheckpoint = hash('sha256', json_encode(
            $sourceSnapshots
                ->sortBy('source')
                ->map(fn (TopologySnapshot $snapshot): array => [
                    'source' => $snapshot->source,
                    'snapshot_uuid' => $snapshot->snapshot_uuid,
                ])
                ->values()
                ->all(),
            JSON_THROW_ON_ERROR,
        ));
        $aggregate = $this->builder->build(
            $site,
            $aggregateEvidence,
            source: 'native:snmp',
            sourceCheckpoint: "aggregate:{$aggregateCheckpoint}",
        );

        $this->outbox->stage(
            type: RuntimeMessageType::Projection,
            stream: (string) config('monitoring.queues.topology', 'monitoring-topology'),
            source: "topology:site:{$siteId}:native:snmp",
            idempotencyKey: "topology-snapshot:{$aggregate->snapshot_uuid}",
            payload: [
                'projection_family' => 'topology_snapshot',
                'site_id' => $siteId,
                'snapshot_id' => $aggregate->id,
                'snapshot_uuid' => $aggregate->snapshot_uuid,
                'source' => $aggregate->source,
                'checkpoint_hash' => $aggregate->source_checkpoint_hash,
                'node_count' => $aggregate->node_count,
                'edge_count' => $aggregate->edge_count,
                'change_count' => $aggregate->change_count,
            ],
        );

        return $aggregate;
    }

    private function evidence(
        int $siteId,
        int $deviceId,
        SnmpTopologyObservation $observation,
    ): ?TopologyEvidence {
        $remote = $this->identities->resolve($siteId, $observation->remoteIdentity);
        if ($remote['device_id'] === $deviceId) {
            return null;
        }

        return new TopologyEvidence(
            source: $observation->source,
            fromDeviceId: $deviceId,
            toDeviceId: $remote['device_id'],
            kind: $observation->kind,
            localPort: $observation->localPort,
            remotePort: $observation->remotePort,
            confidence: $observation->confidence,
            evidence: $observation->evidence,
            toCandidateId: $remote['candidate_id'],
            toObservedIdentityHash: $remote['identity_hash'],
            observedAt: $observation->observedAt,
        );
    }

    /** @return Collection<int, TopologySnapshot> */
    private function latestSourceSnapshots(int $siteId): Collection
    {
        $latestIds = TopologySnapshot::query()
            ->selectRaw('MAX(id)')
            ->where('site_id', $siteId)
            ->where('status', 'completed')
            ->where('source', 'like', 'native:snmp:%:device:%')
            ->groupBy('source');

        return TopologySnapshot::query()
            ->with(['nodes', 'edges'])
            ->whereIn('id', $latestIds)
            ->orderBy('source')
            ->get();
    }

    /** @return list<TopologyEvidence> */
    private function snapshotEvidence(TopologySnapshot $snapshot): array
    {
        $nodes = $snapshot->nodes->keyBy('id');

        return $snapshot->edges
            ->map(function ($edge) use ($nodes): TopologyEvidence {
                $from = $nodes->get($edge->from_node_id);
                $to = $nodes->get($edge->to_node_id);
                if (! $from instanceof TopologyNode || ! $to instanceof TopologyNode) {
                    throw new UnexpectedValueException('Native SNMP topology snapshot is internally inconsistent.');
                }

                return new TopologyEvidence(
                    source: (string) $edge->source,
                    fromDeviceId: $from->canonical_device_id === null ? null : (int) $from->canonical_device_id,
                    toDeviceId: $to->canonical_device_id === null ? null : (int) $to->canonical_device_id,
                    kind: (string) $edge->kind,
                    localPort: $edge->local_port,
                    remotePort: $edge->remote_port,
                    confidence: (float) $edge->confidence,
                    evidence: is_array($edge->evidence) ? $edge->evidence : [],
                    fromCandidateId: $from->discovery_candidate_id === null ? null : (int) $from->discovery_candidate_id,
                    toCandidateId: $to->discovery_candidate_id === null ? null : (int) $to->discovery_candidate_id,
                    fromObservedIdentityHash: $from->observed_identity_hash,
                    toObservedIdentityHash: $to->observed_identity_hash,
                    observedAt: $edge->last_seen_at,
                );
            })
            ->all();
    }
}
