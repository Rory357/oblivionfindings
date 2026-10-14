<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\SecurityDevices\Data\NetworkMapExplorationPage;
use App\Domain\SecurityDevices\Data\NetworkMapExplorationUnavailable;
use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/** Immediate connections only. Canonical Site and permission checks precede eligibility counts. */
final class NetworkMapExplorationQuery
{
    public const NEIGHBOR_LIMIT = 40;

    public const CANDIDATE_LIMIT = 1000;

    public const EDGE_LIMIT = 500;

    public function __construct(private readonly CanonicalDeviceSiteResolver $siteResolver, private readonly TopologyWorkspacePresenter $topologyPresenter) {}

    public function page(Builder $scope, NetworkMapFilters $filters, Collection $approvedSites): NetworkMapExplorationPage
    {
        $anchor = $this->canonicalLoads(clone $scope)->whereKey($filters->anchorId)->firstOrFail();
        try {
            $siteId = $this->siteResolver->resolveLoadedForContext($anchor);
        } catch (UnexpectedValueException) {
            throw (new ModelNotFoundException)->setModel(Device::class);
        }
        if (($filters->siteId !== null && $filters->siteId !== $siteId) || ! $approvedSites->contains('id', $siteId)) {
            throw (new ModelNotFoundException)->setModel(Device::class);
        }
        $filters = new NetworkMapFilters($siteId, anchorId: (int) $anchor->id, neighborCursor: $filters->neighborCursor);
        $snapshots = $this->topologyPresenter->latestSnapshots(collect([$siteId]));
        $sourcesTruncated = $snapshots->count() > TopologyWorkspacePresenter::CURRENT_SNAPSHOT_LIMIT;
        $snapshots = $snapshots->take(TopologyWorkspacePresenter::CURRENT_SNAPSHOT_LIMIT)->values();
        $runtime = $snapshots->isNotEmpty();
        if ($runtime) {
            $evidence = TopologyEdge::query()
                ->join('monitoring_topology_nodes as parent_node', 'parent_node.id', '=', 'monitoring_topology_edges.from_node_id')
                ->join('monitoring_topology_nodes as child_node', 'child_node.id', '=', 'monitoring_topology_edges.to_node_id')
                ->whereIn('monitoring_topology_edges.topology_snapshot_id', $snapshots->pluck('id'));
            $parent = 'parent_node.canonical_device_id';
            $child = 'child_node.canonical_device_id';
            $edgeId = 'monitoring_topology_edges.id';
        } else {
            $evidence = DeviceRelationship::query()->active();
            $parent = 'device_relationships.parent_device_id';
            $child = 'device_relationships.child_device_id';
            $edgeId = 'device_relationships.id';
        }
        $visibleIds = (clone $scope)->reorder()->select('devices.id');
        $evidence->where(fn ($query) => $query
            ->where(fn ($forward) => $forward->where($parent, $anchor->id)->where($child, '!=', $anchor->id)->whereIn($child, clone $visibleIds))
            ->orWhere(fn ($reverse) => $reverse->where($child, $anchor->id)->where($parent, '!=', $anchor->id)->whereIn($parent, clone $visibleIds)));
        $candidateIds = (clone $evidence)->selectRaw("CASE WHEN {$parent} = ? THEN {$child} ELSE {$parent} END AS neighbor_id", [$anchor->id])
            ->distinct()->orderBy('neighbor_id')->limit(self::CANDIDATE_LIMIT + 1)->pluck('neighbor_id');
        if ($candidateIds->count() > self::CANDIDATE_LIMIT) {
            throw new NetworkMapExplorationUnavailable($siteId);
        }
        $candidates = $this->canonicalLoads((clone $scope)->whereKey($candidateIds))->reorder('devices.id')->get();
        $eligible = $candidates->filter(function (Device $device) use ($siteId): bool {
            try {
                return $this->siteResolver->resolveLoadedForContext($device) === $siteId;
            } catch (UnexpectedValueException) {
                return false;
            }
        })->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $position = $filters->neighborCursor;
        $neighbors = $this->seek($eligible, $position);
        $positionReset = false;
        if ($position !== null && $neighbors->isEmpty()) {
            $neighbors = $this->seek($eligible, null);
            $positionReset = true;
            $position = null;
            $filters = $filters->withNeighborCursor(null);
        }
        $hasPrevious = $neighbors->isNotEmpty() && $eligible->contains(fn (int $id): bool => $id < $neighbors->first());
        $hasNext = $neighbors->isNotEmpty() && $eligible->contains(fn (int $id): bool => $id > $neighbors->last());
        if (! $hasPrevious && $neighbors->count() === min($eligible->count(), self::NEIGHBOR_LIMIT)) {
            $filters = $filters->withNeighborCursor(null);
        }
        // Preserve one exact incident edge per displayed neighbor before filling parallel evidence.
        $priority = $neighbors->map(fn (int $id) => (clone $evidence)->where(fn ($pair) => $pair
            ->where($parent, $id)->orWhere($child, $id))->orderBy($edgeId)->value($edgeId))->filter()->values();
        $parallel = (clone $evidence)->where(fn ($query) => $query->whereIn($parent, $neighbors)->orWhereIn($child, $neighbors))
            ->orderBy($edgeId)->limit(self::EDGE_LIMIT + 1)->pluck($edgeId);
        $edgeIds = $priority->merge($parallel)->unique()->values();
        $edgesTruncated = $edgeIds->count() > self::EDGE_LIMIT;
        $metadata = [
            'anchorId' => (int) $anchor->id, 'siteId' => $siteId, 'pageKey' => hash('sha256', $filters->href()),
            'neighborLimit' => self::NEIGHBOR_LIMIT, 'neighborsShown' => $neighbors->count(), 'neighborsTotal' => $eligible->count(),
            'previousHref' => $hasPrevious ? $filters->withNeighborCursor(new Cursor(['id' => $neighbors->first()], false))->href() : null,
            'nextHref' => $hasNext ? $filters->withNeighborCursor(new Cursor(['id' => $neighbors->last()]))->href() : null,
            'refreshHref' => $filters->withNeighborCursor(null)->href(), 'inventoryHref' => (new NetworkMapFilters($siteId))->href(),
            'positionReset' => $positionReset, 'isPartial' => true, 'sourcesTruncated' => $sourcesTruncated, 'edgesTruncated' => $edgesTruncated,
        ];

        return new NetworkMapExplorationPage(collect([(int) $anchor->id])->merge($neighbors), $snapshots, $edgeIds->take(self::EDGE_LIMIT), $runtime, $metadata, $filters);
    }

    private function canonicalLoads(Builder $query): Builder
    {
        return $query->with(['assignments' => fn ($assignment) => $assignment->active()->where('assigned_at', '<=', now())->orderBy('id'), 'activeAssetLinks' => fn ($link) => $link->orderBy('id')]);
    }

    private function seek(Collection $eligible, ?Cursor $cursor): Collection
    {
        if ($cursor === null) {
            return $eligible->take(self::NEIGHBOR_LIMIT)->values();
        }
        $ids = $eligible->filter(fn (int $id): bool => $cursor->pointsToNextItems() ? $id > $cursor->parameter('id') : $id < $cursor->parameter('id'));

        return ($cursor->pointsToNextItems() ? $ids->take(self::NEIGHBOR_LIMIT) : $ids->take(-self::NEIGHBOR_LIMIT))->values();
    }
}
