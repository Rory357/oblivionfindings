<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use UnexpectedValueException;

final class MonitorDependency extends Model
{
    public const string POLICY_SUPPRESS = 'suppress_notifications_and_ticketing';

    protected $fillable = [
        'site_id',
        'upstream_monitor_id',
        'downstream_monitor_id',
        'policy',
        'source',
        'confidence',
        'topology_edge_id',
        'is_active',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $dependency): void {
            $dependency->validateScopeAndPolicy();
            $dependency->assertAcyclic();
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function upstreamMonitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'upstream_monitor_id');
    }

    public function downstreamMonitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'downstream_monitor_id');
    }

    public function topologyEdge(): BelongsTo
    {
        return $this->belongsTo(TopologyEdge::class, 'topology_edge_id');
    }

    private function validateScopeAndPolicy(): void
    {
        if ($this->upstream_monitor_id === $this->downstream_monitor_id) {
            throw new LogicException('A monitor dependency would create a cycle.');
        }

        if (! in_array($this->policy, [self::POLICY_SUPPRESS], true)) {
            throw new UnexpectedValueException('Monitor dependency policy is unsupported.');
        }

        if (! in_array($this->source, ['manual', 'topology', 'provider'], true)) {
            throw new UnexpectedValueException('Monitor dependency source is unsupported.');
        }

        $confidence = (float) $this->confidence;
        if ($confidence < 0 || $confidence > 1) {
            throw new UnexpectedValueException('Monitor dependency confidence must be between zero and one.');
        }

        $upstream = Monitor::query()->findOrFail($this->upstream_monitor_id);
        $downstream = Monitor::query()->findOrFail($this->downstream_monitor_id);
        $resolver = app(CanonicalDeviceSiteResolver::class);
        $upstreamSiteId = $resolver->resolve((int) $upstream->device_id);
        $downstreamSiteId = $resolver->resolve((int) $downstream->device_id);

        if ($upstreamSiteId !== $downstreamSiteId || $upstreamSiteId !== (int) $this->site_id) {
            throw new UnexpectedValueException('Monitor dependencies must remain within the same canonical Site.');
        }

        if ($this->topology_edge_id !== null) {
            $edgeSiteId = TopologyEdge::query()
                ->whereKey($this->topology_edge_id)
                ->join('monitoring_topology_snapshots', 'monitoring_topology_snapshots.id', '=', 'monitoring_topology_edges.topology_snapshot_id')
                ->value('monitoring_topology_snapshots.site_id');

            if ((int) $edgeSiteId !== (int) $this->site_id) {
                throw new UnexpectedValueException('Topology evidence must belong to the dependency Site.');
            }
        }
    }

    private function assertAcyclic(): void
    {
        $target = (int) $this->upstream_monitor_id;
        $frontier = [(int) $this->downstream_monitor_id];
        $visited = [];

        while ($frontier !== []) {
            $current = array_shift($frontier);
            if ($current === $target) {
                throw new LogicException('Monitor dependency cycle detected.');
            }

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $next = self::query()
                ->where('site_id', $this->site_id)
                ->where('is_active', true)
                ->where('upstream_monitor_id', $current)
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->pluck('downstream_monitor_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            array_push($frontier, ...$next);
        }
    }
}
