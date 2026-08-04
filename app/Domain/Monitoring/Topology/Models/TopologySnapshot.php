<?php

namespace App\Domain\Monitoring\Topology\Models;

use App\Domain\Monitoring\Topology\Database\TopologySnapshotQueryBuilder;
use App\Models\Site;
use Closure;
use Database\Factories\TopologySnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class TopologySnapshot extends Model
{
    use HasFactory;

    private static int $buildWriteDepth = 0;

    protected $table = 'monitoring_topology_snapshots';

    protected $fillable = [
        'site_id',
        'snapshot_uuid',
        'source',
        'source_checkpoint_hash',
        'source_envelope_id',
        'captured_at',
        'completed_at',
        'status',
        'node_count',
        'edge_count',
        'change_count',
        'summary',
    ];

    protected $casts = [
        'captured_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'node_count' => 'integer',
        'edge_count' => 'integer',
        'change_count' => 'integer',
        'summary' => 'array',
    ];

    protected static function newFactory(): TopologySnapshotFactory
    {
        return TopologySnapshotFactory::new();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(TopologyNode::class, 'topology_snapshot_id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(TopologyEdge::class, 'topology_snapshot_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TopologyChange::class, 'current_snapshot_id');
    }

    public function newEloquentBuilder($query): TopologySnapshotQueryBuilder
    {
        return new TopologySnapshotQueryBuilder($query);
    }

    public static function allowBuildWrite(Closure $callback): mixed
    {
        self::$buildWriteDepth++;

        try {
            return $callback();
        } finally {
            self::$buildWriteDepth--;
        }
    }

    public static function buildWriteAllowed(): bool
    {
        return self::$buildWriteDepth > 0;
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->getOriginal('status') === 'completed' || ! self::buildWriteAllowed()) {
            throw new LogicException('Completed topology snapshot is immutable.');
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Completed topology snapshot is immutable.');
    }
}
