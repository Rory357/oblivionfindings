<?php

namespace App\Domain\Monitoring\Topology\Models;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Topology\Database\ImmutableTopologyBuilder;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class TopologyNode extends Model
{
    protected $table = 'monitoring_topology_nodes';

    protected $fillable = [
        'topology_snapshot_id',
        'canonical_device_id',
        'discovery_candidate_id',
        'observed_identity_hash',
        'node_key_hash',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TopologySnapshot::class, 'topology_snapshot_id');
    }

    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
    }

    public function discoveryCandidate(): BelongsTo
    {
        return $this->belongsTo(DiscoveryCandidate::class, 'discovery_candidate_id');
    }

    public function newEloquentBuilder($query): ImmutableTopologyBuilder
    {
        return new ImmutableTopologyBuilder($query, 'Topology nodes are immutable.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Topology nodes are immutable.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Topology nodes are immutable.');
    }
}
