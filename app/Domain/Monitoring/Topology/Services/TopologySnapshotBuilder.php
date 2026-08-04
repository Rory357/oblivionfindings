<?php

namespace App\Domain\Monitoring\Topology\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologyNode;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

final class TopologySnapshotBuilder
{
    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly TopologyDiffService $diffs,
    ) {}

    /**
     * @param  list<TopologyEvidence>  $evidence
     */
    public function build(
        Site $site,
        array $evidence,
        string $source = 'native:manual',
        ?string $sourceCheckpoint = null,
        ?string $sourceEnvelopeId = null,
        ?DateTimeInterface $capturedAt = null,
    ): TopologySnapshot {
        $this->assertRequest($site, $evidence, $source, $sourceCheckpoint, $sourceEnvelopeId);
        $checkpoint = $sourceCheckpoint ?? (string) Str::orderedUuid();
        $checkpointHash = hash('sha256', $checkpoint);

        $existing = TopologySnapshot::query()
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->where('source_checkpoint_hash', $checkpointHash)
            ->where('status', 'completed')
            ->first();
        if ($existing !== null) {
            return $this->loaded($existing);
        }

        return DB::transaction(function () use (
            $site,
            $evidence,
            $source,
            $checkpointHash,
            $sourceEnvelopeId,
            $capturedAt,
        ): TopologySnapshot {
            $existing = TopologySnapshot::query()
                ->where('site_id', $site->id)
                ->where('source', $source)
                ->where('source_checkpoint_hash', $checkpointHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->status !== 'completed') {
                    throw new UnexpectedValueException('Topology snapshot checkpoint is already being built.');
                }

                return $this->loaded($existing);
            }

            $captured = CarbonImmutable::instance($capturedAt ?? now())->utc();
            $previous = TopologySnapshot::query()
                ->where('site_id', $site->id)
                ->where('source', $source)
                ->where('status', 'completed')
                ->latest('captured_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $snapshot = TopologySnapshot::query()->create([
                'site_id' => $site->id,
                'snapshot_uuid' => (string) Str::orderedUuid(),
                'source' => $source,
                'source_checkpoint_hash' => $checkpointHash,
                'source_envelope_id' => $sourceEnvelopeId,
                'captured_at' => $captured,
                'completed_at' => null,
                'status' => 'building',
                'node_count' => 0,
                'edge_count' => 0,
                'change_count' => 0,
                'summary' => [
                    'sources' => [],
                    'unresolved_nodes' => 0,
                    'conflicts' => 0,
                    'changes' => ['added' => 0, 'removed' => 0, 'changed' => 0],
                ],
            ]);

            $this->persistEdges($snapshot, $site, $evidence, $previous, $captured);
            $changes = $this->diffs->diff($previous, $snapshot);
            $nodes = $snapshot->nodes()->get();
            $edges = $snapshot->edges()->get();
            $summary = [
                'sources' => $edges->pluck('source')->unique()->sort()->values()->all(),
                'unresolved_nodes' => $nodes->whereNull('canonical_device_id')->count(),
                'conflicts' => $this->conflictCount($edges),
                'changes' => $changes,
            ];

            TopologySnapshot::allowBuildWrite(function () use ($snapshot, $nodes, $edges, $changes, $summary): void {
                $snapshot->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'node_count' => $nodes->count(),
                    'edge_count' => $edges->count(),
                    'change_count' => array_sum($changes),
                    'summary' => $summary,
                ])->save();
            });

            return $this->loaded($snapshot);
        }, 3);
    }

    /**
     * @param  list<TopologyEvidence>  $evidence
     */
    private function assertRequest(
        Site $site,
        array $evidence,
        string $source,
        ?string $checkpoint,
        ?string $sourceEnvelopeId,
    ): void {
        $siteIsActive = Site::query()
            ->whereKey($site->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->exists();
        if (! $siteIsActive) {
            throw new UnexpectedValueException('Topology Site is unavailable.');
        }
        if (count($evidence) > 5000
            || collect($evidence)->contains(fn (mixed $item): bool => ! $item instanceof TopologyEvidence)) {
            throw new InvalidArgumentException('Topology evidence collection is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9:_-]{0,127}$/', $source) !== 1
            || ($checkpoint !== null && ($checkpoint === '' || strlen($checkpoint) > 2048))
            || ($sourceEnvelopeId !== null && ! Str::isUuid($sourceEnvelopeId))) {
            throw new InvalidArgumentException('Topology snapshot source is invalid.');
        }
    }

    /** @param list<TopologyEvidence> $evidence */
    private function persistEdges(
        TopologySnapshot $snapshot,
        Site $site,
        array $evidence,
        ?TopologySnapshot $previous,
        CarbonImmutable $capturedAt,
    ): void {
        /** @var array<string, TopologyNode> $nodes */
        $nodes = [];
        /** @var array<string, array{item: TopologyEvidence, from: TopologyNode, to: TopologyNode, evidence: array<string, mixed>, evidence_hash: string, content_hash: string}> $edges */
        $edges = [];

        foreach ($evidence as $item) {
            $from = $this->node($snapshot, $site, $item->fromEndpoint(), $nodes);
            $to = $this->node($snapshot, $site, $item->toEndpoint(), $nodes);
            if (hash_equals($from->node_key_hash, $to->node_key_hash)) {
                throw new InvalidArgumentException('Topology self edges are invalid.');
            }
            $edgeHash = $this->hash([
                'source' => $item->source,
                'kind' => $item->kind,
                'from' => $from->node_key_hash,
                'to' => $to->node_key_hash,
            ]);
            $normalisedEvidence = $this->sorted($item->evidence);
            $evidenceHash = $this->hash($normalisedEvidence);
            $contentHash = $this->hash([
                'local_port' => $item->localPort,
                'remote_port' => $item->remotePort,
                'confidence' => number_format($item->confidence, 4, '.', ''),
                'evidence_hash' => $evidenceHash,
            ]);
            $candidate = [
                'item' => $item,
                'from' => $from,
                'to' => $to,
                'evidence' => $normalisedEvidence,
                'evidence_hash' => $evidenceHash,
                'content_hash' => $contentHash,
            ];

            if (! isset($edges[$edgeHash])
                || $item->confidence > $edges[$edgeHash]['item']->confidence
                || ($item->confidence === $edges[$edgeHash]['item']->confidence
                    && strcmp($contentHash, $edges[$edgeHash]['content_hash']) < 0)) {
                $edges[$edgeHash] = $candidate;
            }
        }

        ksort($edges, SORT_STRING);
        foreach ($edges as $edgeHash => $candidate) {
            $item = $candidate['item'];
            $old = $previous?->edges()->where('edge_hash', $edgeHash)->first();
            $lastSeen = $item->observedAt ?? $capturedAt;
            TopologyEdge::query()->create([
                'topology_snapshot_id' => $snapshot->id,
                'from_node_id' => $candidate['from']->id,
                'to_node_id' => $candidate['to']->id,
                'source' => $item->source,
                'kind' => $item->kind,
                'local_port' => $item->localPort,
                'remote_port' => $item->remotePort,
                'confidence' => $item->confidence,
                'evidence' => $candidate['evidence'],
                'evidence_hash' => $candidate['evidence_hash'],
                'edge_hash' => $edgeHash,
                'content_hash' => $candidate['content_hash'],
                'first_seen_at' => $old?->first_seen_at ?? $lastSeen,
                'last_seen_at' => $lastSeen,
            ]);
        }
    }

    /**
     * @param  array{device_id: ?int, candidate_id: ?int, identity_hash: ?string}  $endpoint
     * @param  array<string, TopologyNode>  $nodes
     */
    private function node(
        TopologySnapshot $snapshot,
        Site $site,
        array $endpoint,
        array &$nodes,
    ): TopologyNode {
        $deviceId = $endpoint['device_id'];
        $candidateId = $endpoint['candidate_id'];
        $identityHash = $endpoint['identity_hash'];

        if ($deviceId !== null) {
            if ($this->siteResolver->resolve($deviceId) !== (int) $site->id) {
                throw new UnexpectedValueException('Topology endpoint does not belong to the canonical Site.');
            }
            $key = "device:{$deviceId}";
        } elseif ($candidateId !== null) {
            $candidate = DiscoveryCandidate::query()
                ->with('run.scope')
                ->find($candidateId);
            if ($candidate === null || (int) $candidate->run?->scope?->site_id !== (int) $site->id) {
                throw new UnexpectedValueException('Topology endpoint discovery candidate Site is invalid.');
            }
            if ($candidate->canonical_device_id !== null
                && $this->siteResolver->resolve((int) $candidate->canonical_device_id) !== (int) $site->id) {
                throw new UnexpectedValueException('Topology endpoint discovery candidate Site is invalid.');
            }
            $key = "candidate:{$candidateId}";
        } else {
            $key = "observed:{$identityHash}";
        }

        $nodeKeyHash = hash('sha256', $key);
        if (isset($nodes[$nodeKeyHash])) {
            return $nodes[$nodeKeyHash];
        }

        return $nodes[$nodeKeyHash] = TopologyNode::query()->create([
            'topology_snapshot_id' => $snapshot->id,
            'canonical_device_id' => $deviceId,
            'discovery_candidate_id' => $candidateId,
            'observed_identity_hash' => $identityHash,
            'node_key_hash' => $nodeKeyHash,
        ]);
    }

    /** @param Collection<int, TopologyEdge> $edges */
    private function conflictCount(Collection $edges): int
    {
        return $edges
            ->filter(fn (TopologyEdge $edge): bool => in_array($edge->source, ['lldp', 'cdp', 'forwarding_table', 'provider'], true)
                && $edge->local_port !== null)
            ->groupBy(fn (TopologyEdge $edge): string => implode(':', [
                $edge->source,
                $edge->kind,
                $edge->from_node_id,
                $edge->local_port,
            ]))
            ->filter(fn ($group): bool => $group->pluck('to_node_id')->unique()->count() > 1)
            ->count();
    }

    /** @param array<string, mixed> $value */
    private function sorted(array $value): array
    {
        ksort($value, SORT_STRING);

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->sorted($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function loaded(TopologySnapshot $snapshot): TopologySnapshot
    {
        return $snapshot->fresh(['nodes', 'edges', 'changes']) ?? $snapshot->load(['nodes', 'edges', 'changes']);
    }
}
