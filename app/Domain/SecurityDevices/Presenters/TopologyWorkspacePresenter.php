<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Data\NetworkMapExplorationPage;
use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only projection; historical ownership never grants present-day visibility. */
final class TopologyWorkspacePresenter
{
    public const HISTORY_LIMIT = 10;

    public const CURRENT_SNAPSHOT_LIMIT = 100;

    /** @param Collection<int, array<string, mixed>> $devices @param Collection<int, int|null> $canonicalSiteIds */
    public function present(Collection $devices, Collection $canonicalSiteIds, bool $includeHistory, ?Cursor $historyCursor = null, ?NetworkMapFilters $mapFilters = null, ?NetworkMapExplorationPage $exploration = null): ?array
    {
        $siteIds = $canonicalSiteIds->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return null;
        }

        $latest = $exploration?->snapshots ?? $this->latestSnapshots($siteIds);
        if ($latest->isEmpty()) {
            return null;
        }

        $currentTruncated = $exploration?->metadata['sourcesTruncated'] ?? ($latest->count() > self::CURRENT_SNAPSHOT_LIMIT);
        $latest = $latest->take(self::CURRENT_SNAPSHOT_LIMIT)->values();
        $this->load($latest, $devices, $exploration?->edgeIds);
        $evaluatedAt = CarbonImmutable::now();
        $staleAfter = max(300, (int) config('monitoring.runtime.site_topology_stale_seconds', 3600));
        $projections = $latest->map(fn (TopologySnapshot $snapshot): array => $this->project(
            $snapshot, $devices, $canonicalSiteIds, $evaluatedAt, $staleAfter,
        ));
        $recordedIds = $projections->flatMap(fn (array $row) => $row['nodes'])->pluck('id')->unique();
        $sites = $latest->pluck('site')->filter()->keyBy('id');
        $nodes = $devices->map(function (array $device) use ($canonicalSiteIds, $recordedIds, $sites): array {
            $siteId = $canonicalSiteIds->get($device['id']);
            $siteName = $siteId === null ? null : ($sites->get($siteId)?->name
                ?? (($device['site']['id'] ?? null) === $siteId ? ($device['site']['name'] ?? null) : null));

            return [
                'id' => $device['id'], 'name' => $device['name'],
                'category' => $device['category'], 'subcategory' => $device['subcategory'],
                'health' => $device['health'], 'healthBasis' => 'current_device_state', 'currentState' => true,
                'siteId' => $siteId, 'site' => $siteName, 'href' => $device['href'],
                'exploreHref' => $siteId === null ? null : NetworkMapFilters::exploreHref($device['id'], $siteId),
                'topologyEvidence' => $recordedIds->contains($device['id']) ? 'retained_snapshot' : 'not_observed',
            ];
        })->values();
        $edges = $projections->flatMap(fn (array $row) => $row['edges'])->values();
        $linkedIds = $edges->flatMap(fn (array $edge): array => [$edge['parentId'], $edge['childId']])->unique();
        // Coverage is measured against the bounded visible inventory, not only imported nodes.
        $unlinked = $devices->pluck('id')->diff($linkedIds)->count();
        $snapshots = $projections->map(function (array $row): array {
            unset($row['nodes'], $row['edges']);

            return $row;
        })->values();
        $history = self::emptyHistory(false, $mapFilters);
        if ($includeHistory) {
            [$history, $candidates] = $this->historyPage($siteIds, $historyCursor, $mapFilters);
            $this->load($candidates, $devices);
            $history['entries'] = $candidates->map(function (TopologySnapshot $snapshot) use (
                $devices, $canonicalSiteIds, $evaluatedAt, $staleAfter,
            ): array {
                $previous = $this->scope(collect([(int) $snapshot->site_id]))
                    ->where('source', $snapshot->source)
                    ->where(fn ($query) => $query->where('captured_at', '<', $snapshot->getRawOriginal('captured_at'))
                        ->orWhere(fn ($tie) => $tie->where('captured_at', $snapshot->getRawOriginal('captured_at'))->where('id', '<', $snapshot->id)))
                    ->first();
                if ($previous !== null) {
                    $this->load(collect([$previous]), $devices);
                }
                $row = $this->project($snapshot, $devices, $canonicalSiteIds, $evaluatedAt, $staleAfter);
                $row['comparison'] = $this->compare($previous, $snapshot, $devices, $canonicalSiteIds, $evaluatedAt, $staleAfter);
                $row['changes'] = ['added' => count($row['comparison']['addedEdges']), 'removed' => count($row['comparison']['removedEdges']), 'changed' => count($row['comparison']['changedEdges'])];
                $row['changeCount'] = array_sum($row['changes']);

                return $row;
            })->values();
        }

        return [
            'source' => 'runtime_topology',
            'state' => $edges->isEmpty() || $unlinked > 0 || $currentTruncated ? 'partial' : 'known',
            'label' => $edges->isEmpty() ? 'Latest topology snapshots have no visible relationships'
                : ($unlinked === 0 && ! $currentTruncated ? 'Topology evidence connects every device in the visible inventory' : 'Topology evidence is incomplete'),
            'nodeCount' => $nodes->count(),
            'edgeCount' => $edges->count(),
            'unlinkedCount' => $unlinked,
            'visibleDeviceCount' => $devices->count(),
            'currentSnapshotLimit' => self::CURRENT_SNAPSHOT_LIMIT,
            'currentSnapshotsTruncated' => $currentTruncated,
            'nodes' => $nodes,
            'edges' => $edges,
            'snapshots' => $snapshots,
            'changes' => collect(['added', 'removed', 'changed'])->mapWithKeys(fn (string $key): array => [
                $key => $projections->sum(fn (array $row): int => $row['changes'][$key]),
            ])->all(),
            'history' => $history,
        ];
    }

    public function latestSnapshots(Collection $siteIds): Collection
    {
        return $this->scope($siteIds)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('monitoring_topology_snapshots as newer')
                    ->whereColumn('newer.site_id', 'monitoring_topology_snapshots.site_id')
                    ->whereColumn('newer.source', 'monitoring_topology_snapshots.source')
                    ->where('newer.status', 'completed')
                    ->where(fn ($newer) => $newer
                        ->whereColumn('newer.captured_at', '>', 'monitoring_topology_snapshots.captured_at')
                        ->orWhere(fn ($tie) => $tie
                            ->whereColumn('newer.captured_at', 'monitoring_topology_snapshots.captured_at')
                            ->whereColumn('newer.id', '>', 'monitoring_topology_snapshots.id')));
            })
            ->limit(self::CURRENT_SNAPSHOT_LIMIT + 1)->get();
    }

    public static function emptyHistory(bool $positionReset = false, ?NetworkMapFilters $mapFilters = null): array
    {
        return [
            'limit' => self::HISTORY_LIMIT, 'truncated' => false, 'entries' => [],
            'pageKey' => 'latest', 'isOlderPage' => false, 'positionReset' => $positionReset,
            'previousHref' => null, 'nextHref' => null, 'latestHref' => ($mapFilters ?? new NetworkMapFilters)->href(),
        ];
    }

    /** Cursor position is deliberately unsigned: it grants no record or Site access. */
    public static function decodeCursor(mixed $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > 512 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
            throw new InvalidArgumentException('Topology history cursor is invalid.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Topology history cursor is invalid.');
        }
        try {
            $payload = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Topology history cursor is invalid.');
        }
        if (! is_array($payload) || array_keys($payload) !== ['captured_at', 'id', '_pointsToNextItems']
            || ! is_string($payload['captured_at']) || ! is_int($payload['id']) || $payload['id'] < 1
            || ! is_bool($payload['_pointsToNextItems'])
            || preg_match('/\A([1-9]\d{3})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?\z/D', $payload['captured_at'], $date) !== 1
            || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])
            || (int) $date[4] > 23 || (int) $date[5] > 59 || (int) $date[6] > 59) {
            throw new InvalidArgumentException('Topology history cursor is invalid.');
        }
        $cursor = new Cursor(['captured_at' => $payload['captured_at'], 'id' => $payload['id']], $payload['_pointsToNextItems']);
        if ($cursor->encode() !== $encoded) {
            throw new InvalidArgumentException('Topology history cursor is invalid.');
        }

        return $cursor;
    }

    /** @return array{array<string, mixed>, Collection<int, TopologySnapshot>} */
    private function historyPage(Collection $siteIds, ?Cursor $cursor, ?NetworkMapFilters $mapFilters): array
    {
        // Passing an explicit empty string prevents Laravel from resolving unvalidated request input.
        $page = $this->scope($siteIds)->cursorPaginate(self::HISTORY_LIMIT, ['*'], 'topology_cursor', $cursor ?? '');
        $candidates = $page->getCollection()->values();
        $positionReset = false;
        if ($cursor !== null && $candidates->isEmpty()) {
            $page = $this->scope($siteIds)->cursorPaginate(self::HISTORY_LIMIT, ['*'], 'topology_cursor', '');
            $candidates = $page->getCollection()->values();
            $cursor = null;
            $positionReset = true;
        }
        $history = self::emptyHistory($positionReset, $mapFilters);
        if ($candidates->isEmpty()) {
            return [$history, $candidates];
        }
        $first = $candidates->first();
        $last = $candidates->last();
        $hasNewer = $this->seek($this->scope($siteIds), $first, true)->exists();
        $hasOlder = $this->seek($this->scope($siteIds), $last, false)->exists();
        $history['isOlderPage'] = $cursor !== null && $hasNewer;
        $history['pageKey'] = $history['isOlderPage'] ? hash('sha256', $cursor->encode()) : 'latest';
        $history['truncated'] = $hasNewer || $hasOlder;
        $history['previousHref'] = $history['isOlderPage'] ? $this->historyHref($first, false, $mapFilters) : null;
        $history['nextHref'] = $hasOlder ? $this->historyHref($last, true, $mapFilters) : null;

        return [$history, $candidates];
    }

    private function seek(Builder $query, TopologySnapshot $anchor, bool $newer): Builder
    {
        $date = $anchor->getRawOriginal('captured_at');
        $operator = $newer ? '>' : '<';

        return $query->where(fn ($position) => $position->where('captured_at', $operator, $date)
            ->orWhere(fn ($tie) => $tie->where('captured_at', $date)->where('id', $operator, $anchor->id)));
    }

    private function historyHref(TopologySnapshot $snapshot, bool $older, ?NetworkMapFilters $mapFilters): string
    {
        // Use the retained database timestamp, preserving precision independently of Carbon's __toString().
        $cursor = new Cursor(['captured_at' => $snapshot->getRawOriginal('captured_at'), 'id' => (int) $snapshot->id], $older);

        return ($mapFilters ?? new NetworkMapFilters)->href($cursor);
    }

    private function scope(Collection $siteIds): Builder
    {
        $query = TopologySnapshot::query()->whereIn('site_id', $siteIds)->where('status', 'completed');
        foreach (['lldp', 'cdp', 'arp', 'forwarding_table', 'route'] as $source) {
            $query->where('source', 'not like', "native:snmp:{$source}:device:%");
        }

        return $query->orderByDesc('captured_at')->orderByDesc('id');
    }

    private function load(Collection $snapshots, Collection $devices, ?Collection $edgeIds = null): void
    {
        $deviceIds = $devices->pluck('id');
        // Load raw edge evidence only for visible endpoints; changes need endpoint references, never historical names or raw evidence.
        (new \Illuminate\Database\Eloquent\Collection($snapshots->all()))->load([
            'site:id,name',
            'nodes' => fn ($query) => $query->select(['id', 'topology_snapshot_id', 'canonical_device_id'])->whereIn('canonical_device_id', $deviceIds),
            'edges' => fn ($query) => $query
                ->when($edgeIds !== null, fn ($edges) => $edges->whereIn('id', $edgeIds))
                ->whereHas('fromNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds))
                ->whereHas('toNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds))
                ->with(['fromNode:id,canonical_device_id', 'toNode:id,canonical_device_id']),
            'changes' => fn ($query) => $query->select(['id', 'current_snapshot_id', 'change_type', 'before_edge_id', 'after_edge_id'])
                ->when($edgeIds !== null, fn ($changes) => $changes->where(fn ($selected) => $selected->whereIn('before_edge_id', $edgeIds)->orWhereIn('after_edge_id', $edgeIds)))
                ->where(fn ($change) => $change->whereHas('beforeEdge', fn ($edge) => $edge
                    ->whereHas('fromNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds))
                    ->whereHas('toNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds)))
                    ->orWhereHas('afterEdge', fn ($edge) => $edge
                        ->whereHas('fromNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds))
                        ->whereHas('toNode', fn ($node) => $node->whereIn('canonical_device_id', $deviceIds))))
                ->with([
                    'beforeEdge' => fn ($edge) => $edge->select(['id', 'from_node_id', 'to_node_id'])->with(['fromNode:id,canonical_device_id', 'toNode:id,canonical_device_id']),
                    'afterEdge' => fn ($edge) => $edge->select(['id', 'from_node_id', 'to_node_id'])->with(['fromNode:id,canonical_device_id', 'toNode:id,canonical_device_id']),
                ]),
        ]);
    }

    /** Pure projection also used by fixture verification. Current names, health and links are intentional. */
    public function project(TopologySnapshot $snapshot, Collection $devices, Collection $canonicalSiteIds, CarbonImmutable $evaluatedAt, int $staleAfter): array
    {
        $visible = $devices->keyBy('id');
        $nodes = $snapshot->nodes->filter(fn ($node): bool => $node->canonical_device_id !== null
            && $visible->has((int) $node->canonical_device_id)
            && (int) $canonicalSiteIds->get((int) $node->canonical_device_id) === (int) $snapshot->site_id)
            ->map(function ($node) use ($visible, $snapshot): array {
                $device = $visible->get((int) $node->canonical_device_id);

                return [
                    'id' => (int) $node->canonical_device_id,
                    'name' => $device['name'],
                    'category' => $device['category'],
                    'subcategory' => $device['subcategory'],
                    'health' => $device['health'],
                    'healthBasis' => 'current_device_state',
                    'currentState' => true,
                    'siteId' => (int) $snapshot->site_id,
                    'exploreHref' => NetworkMapFilters::exploreHref($device['id'], (int) $snapshot->site_id),
                    'site' => $snapshot->site?->name,
                    'href' => $device['href'],
                ];
            })->unique('id')->values();
        $edges = $snapshot->edges->filter(fn (TopologyEdge $edge): bool => $this->visibleEdge($edge, $snapshot, $visible, $canonicalSiteIds))
            ->map(fn (TopologyEdge $edge): array => $this->edge($edge, $snapshot, $visible, $evaluatedAt, $staleAfter))->values();
        $changes = $snapshot->changes->filter(function ($change) use ($snapshot, $visible, $canonicalSiteIds): bool {
            $before = $change->beforeEdge;
            $after = $change->afterEdge;

            return ($before !== null || $after !== null)
                && ($before === null || $this->visibleEdge($before, $snapshot, $visible, $canonicalSiteIds))
                && ($after === null || $this->visibleEdge($after, $snapshot, $visible, $canonicalSiteIds));
        })->countBy('change_type');

        return [
            'id' => (int) $snapshot->id,
            'site' => $snapshot->site ? ['id' => (int) $snapshot->site_id, 'name' => $snapshot->site->name, 'href' => "/security-devices/sites/{$snapshot->site_id}"] : null,
            'source' => $snapshot->source,
            'capturedAt' => $snapshot->captured_at?->toIso8601String(),
            'freshness' => $this->freshness($snapshot->captured_at, $evaluatedAt, $staleAfter),
            'countBasis' => 'currently_visible_canonical_devices',
            'nodeCount' => $nodes->count(),
            'edgeCount' => $edges->count(),
            'changeCount' => $changes->sum(),
            'changes' => ['added' => (int) ($changes['added'] ?? 0), 'removed' => (int) ($changes['removed'] ?? 0), 'changed' => (int) ($changes['changed'] ?? 0)],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    public function compare(?TopologySnapshot $previous, TopologySnapshot $current, Collection $devices, Collection $canonicalSiteIds, CarbonImmutable $evaluatedAt, int $staleAfter): array
    {
        $result = ['previousSnapshotId' => $previous?->id, 'state' => $previous === null ? 'baseline' : 'compared', 'addedEdges' => [], 'removedEdges' => [], 'changedEdges' => []];
        if ($previous !== null && ((int) $previous->site_id !== (int) $current->site_id || $previous->source !== $current->source)) {
            return [...$result, 'previousSnapshotId' => null, 'state' => 'unavailable'];
        }
        $visible = $devices->keyBy('id');
        $before = $previous?->edges->filter(fn (TopologyEdge $edge): bool => $this->visibleEdge($edge, $previous, $visible, $canonicalSiteIds))->keyBy('edge_hash') ?? collect();
        $after = $current->edges->filter(fn (TopologyEdge $edge): bool => $this->visibleEdge($edge, $current, $visible, $canonicalSiteIds))->keyBy('edge_hash');
        foreach ($after as $hash => $edge) {
            $old = $before->get($hash);
            if ($old === null) {
                $result['addedEdges'][] = $this->edge($edge, $current, $visible, $evaluatedAt, $staleAfter);
            } elseif (! hash_equals($old->content_hash, $edge->content_hash)) {
                $result['changedEdges'][] = ['before' => $this->edge($old, $previous, $visible, $evaluatedAt, $staleAfter), 'after' => $this->edge($edge, $current, $visible, $evaluatedAt, $staleAfter)];
            }
        }
        foreach ($before as $hash => $edge) {
            if (! $after->has($hash)) {
                $result['removedEdges'][] = $this->edge($edge, $previous, $visible, $evaluatedAt, $staleAfter);
            }
        }

        return $result;
    }

    private function visibleEdge(TopologyEdge $edge, TopologySnapshot $snapshot, Collection $visible, Collection $canonicalSiteIds): bool
    {
        $from = $edge->fromNode?->canonical_device_id;
        $to = $edge->toNode?->canonical_device_id;

        return $from !== null && $to !== null && $visible->has((int) $from) && $visible->has((int) $to)
            && (int) $canonicalSiteIds->get((int) $from) === (int) $snapshot->site_id
            && (int) $canonicalSiteIds->get((int) $to) === (int) $snapshot->site_id;
    }

    private function edge(TopologyEdge $edge, TopologySnapshot $snapshot, Collection $visible, CarbonImmutable $evaluatedAt, int $staleAfter): array
    {
        $from = $visible->get((int) $edge->fromNode->canonical_device_id);
        $to = $visible->get((int) $edge->toNode->canonical_device_id);
        $provider = is_string(data_get($edge->evidence, 'provider')) ? data_get($edge->evidence, 'provider') : null;
        $source = $edge->source === 'provider' && $provider !== null
            ? ($provider === 'unifi' ? 'UniFi' : Str::headline($provider)).' provider' : Str::headline($edge->source);

        return [
            'id' => 'runtime-'.$edge->id,
            'snapshotId' => (int) $snapshot->id,
            'siteId' => (int) $snapshot->site_id,
            'parentId' => $from['id'], 'parentName' => $from['name'],
            'childId' => $to['id'], 'childName' => $to['name'],
            'type' => $edge->kind, 'label' => Str::headline($edge->kind),
            'port' => collect([$edge->local_port, $edge->remote_port])->filter()->implode(' → ') ?: null,
            'source' => $edge->source, 'confidence' => (float) $edge->confidence,
            'reviewState' => in_array($edge->source, ['manual', 'reviewed'], true) ? 'reviewed' : 'inferred',
            'evidenceLabel' => $source.' '.Str::headline($edge->kind).' evidence',
            'capturedAt' => $snapshot->captured_at?->toIso8601String(),
            'firstSeenAt' => $edge->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $edge->last_seen_at?->toIso8601String(),
            // Legacy snapshots do not distinguish source observedAt from capture-time fallback.
            'observationTimeBasis' => 'retained_edge_timestamp',
            'freshness' => $this->freshness($edge->last_seen_at, $evaluatedAt, $staleAfter),
        ];
    }

    private function freshness($observedAt, CarbonImmutable $evaluatedAt, int $staleAfter): array
    {
        return [
            'state' => $observedAt === null || $observedAt->greaterThan($evaluatedAt) ? 'unknown'
                : ($observedAt->lessThan($evaluatedAt->subSeconds($staleAfter)) ? 'stale' : 'current'),
            'observedAt' => $observedAt?->toIso8601String(),
            'staleAfterSeconds' => $staleAfter,
            'basis' => 'topology_evidence_age',
        ];
    }
}
