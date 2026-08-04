<?php

namespace App\Domain\Monitoring\Topology\Services;

use App\Domain\Monitoring\Topology\Models\TopologyChange;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use Illuminate\Support\Collection;

final class TopologyDiffService
{
    /** @return array{added: int, removed: int, changed: int} */
    public function diff(?TopologySnapshot $previous, TopologySnapshot $current): array
    {
        $before = $previous?->edges()->get()->keyBy('edge_hash') ?? collect();
        $after = $current->edges()->get()->keyBy('edge_hash');
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];

        $this->recordAdded($previous, $current, $before, $after, $counts);
        $this->recordRemoved($previous, $current, $before, $after, $counts);
        $this->recordChanged($previous, $current, $before, $after, $counts);

        return $counts;
    }

    /**
     * @param  Collection<string, TopologyEdge>  $before
     * @param  Collection<string, TopologyEdge>  $after
     * @param  array{added: int, removed: int, changed: int}  $counts
     */
    private function recordAdded(
        ?TopologySnapshot $previous,
        TopologySnapshot $current,
        Collection $before,
        Collection $after,
        array &$counts,
    ): void {
        foreach ($after as $edgeHash => $edge) {
            if ($before->has($edgeHash)) {
                continue;
            }
            TopologyChange::query()->create([
                'previous_snapshot_id' => $previous?->id,
                'current_snapshot_id' => $current->id,
                'change_type' => 'added',
                'edge_hash' => $edgeHash,
                'before_edge_id' => null,
                'after_edge_id' => $edge->id,
                'evidence' => $this->changeEvidence(null, $edge),
            ]);
            $counts['added']++;
        }
    }

    /**
     * @param  Collection<string, TopologyEdge>  $before
     * @param  Collection<string, TopologyEdge>  $after
     * @param  array{added: int, removed: int, changed: int}  $counts
     */
    private function recordRemoved(
        ?TopologySnapshot $previous,
        TopologySnapshot $current,
        Collection $before,
        Collection $after,
        array &$counts,
    ): void {
        foreach ($before as $edgeHash => $edge) {
            if ($after->has($edgeHash)) {
                continue;
            }
            TopologyChange::query()->create([
                'previous_snapshot_id' => $previous?->id,
                'current_snapshot_id' => $current->id,
                'change_type' => 'removed',
                'edge_hash' => $edgeHash,
                'before_edge_id' => $edge->id,
                'after_edge_id' => null,
                'evidence' => $this->changeEvidence($edge, null),
            ]);
            $counts['removed']++;
        }
    }

    /**
     * @param  Collection<string, TopologyEdge>  $before
     * @param  Collection<string, TopologyEdge>  $after
     * @param  array{added: int, removed: int, changed: int}  $counts
     */
    private function recordChanged(
        ?TopologySnapshot $previous,
        TopologySnapshot $current,
        Collection $before,
        Collection $after,
        array &$counts,
    ): void {
        foreach ($after as $edgeHash => $edge) {
            /** @var ?TopologyEdge $old */
            $old = $before->get($edgeHash);
            if ($old === null || hash_equals($old->content_hash, $edge->content_hash)) {
                continue;
            }
            TopologyChange::query()->create([
                'previous_snapshot_id' => $previous?->id,
                'current_snapshot_id' => $current->id,
                'change_type' => 'changed',
                'edge_hash' => $edgeHash,
                'before_edge_id' => $old->id,
                'after_edge_id' => $edge->id,
                'evidence' => $this->changeEvidence($old, $edge),
            ]);
            $counts['changed']++;
        }
    }

    /** @return array<string, bool|float|int|string|null> */
    private function changeEvidence(?TopologyEdge $before, ?TopologyEdge $after): array
    {
        $reference = $after ?? $before;

        return [
            'source' => $reference?->source,
            'kind' => $reference?->kind,
            'before_content_hash' => $before?->content_hash,
            'after_content_hash' => $after?->content_hash,
            'local_port_changed' => $before !== null && $after !== null && $before->local_port !== $after->local_port,
            'remote_port_changed' => $before !== null && $after !== null && $before->remote_port !== $after->remote_port,
            'confidence_changed' => $before !== null && $after !== null && $before->confidence !== $after->confidence,
        ];
    }
}
