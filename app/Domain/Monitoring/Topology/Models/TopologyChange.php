<?php

namespace App\Domain\Monitoring\Topology\Models;

use App\Domain\Monitoring\Topology\Database\ImmutableTopologyBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class TopologyChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'monitoring_topology_changes';

    protected $fillable = [
        'previous_snapshot_id',
        'current_snapshot_id',
        'change_type',
        'edge_hash',
        'before_edge_id',
        'after_edge_id',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(TopologySnapshot::class, 'previous_snapshot_id');
    }

    public function currentSnapshot(): BelongsTo
    {
        return $this->belongsTo(TopologySnapshot::class, 'current_snapshot_id');
    }

    public function beforeEdge(): BelongsTo
    {
        return $this->belongsTo(TopologyEdge::class, 'before_edge_id');
    }

    public function afterEdge(): BelongsTo
    {
        return $this->belongsTo(TopologyEdge::class, 'after_edge_id');
    }

    public function newEloquentBuilder($query): ImmutableTopologyBuilder
    {
        return new ImmutableTopologyBuilder($query, 'Topology changes are immutable.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Topology changes are immutable.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Topology changes are immutable.');
    }
}
