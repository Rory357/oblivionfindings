<?php

namespace App\Domain\Monitoring\Topology\Models;

use App\Domain\Monitoring\Topology\Database\ImmutableTopologyBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class TopologyEdge extends Model
{
    protected $table = 'monitoring_topology_edges';

    protected $fillable = [
        'topology_snapshot_id',
        'from_node_id',
        'to_node_id',
        'source',
        'kind',
        'local_port',
        'remote_port',
        'confidence',
        'evidence',
        'evidence_hash',
        'edge_hash',
        'content_hash',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'evidence' => 'array',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TopologySnapshot::class, 'topology_snapshot_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(TopologyNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(TopologyNode::class, 'to_node_id');
    }

    public function newEloquentBuilder($query): ImmutableTopologyBuilder
    {
        return new ImmutableTopologyBuilder($query, 'Topology edges are immutable.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Topology edges are immutable.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Topology edges are immutable.');
    }
}
