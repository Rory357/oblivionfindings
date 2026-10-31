import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    GitBranch,
    RefreshCw,
    Search,
    X,
} from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { TopologyExplorationControls } from './topology-exploration-controls';
import { TopologyGraph } from './topology-graph';
import { TopologyInventoryControls } from './topology-inventory-controls';
import { connectionPath, edgeKey, visibleEdges } from './topology-layout';
import type { TopologyEdge, TopologyMapData } from './topology-types';
export type { TopologyMapData } from './topology-types';

function label(value: string | null | undefined) {
    return value
        ? value
              .replaceAll('_', ' ')
              .replaceAll(':', ' ')
              .replace(/\b\w/g, (letter) => letter.toUpperCase())
        : 'Unknown';
}
function sourceLabel(source: string) {
    if (source === 'snmp') return 'SNMP';
    if (source === 'native:snmp') return 'Native SNMP';
    if (source.startsWith('provider:'))
        return (
            (source.slice(9) === 'unifi' ? 'UniFi' : label(source.slice(9))) +
            ' provider'
        );
    return label(source);
}
function Evidence({ edge }: { edge: TopologyEdge }) {
    const evidenceState = edge.evidenceState ?? edge.reviewState;
    const reviewed = evidenceState === 'reviewed';
    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    variant="outline"
                    className="max-w-full break-words whitespace-normal"
                >
                    {edge.label}
                </Badge>
                {edge.port && (
                    <Badge
                        variant="secondary"
                        className="max-w-full break-all whitespace-normal"
                    >
                        {edge.port}
                    </Badge>
                )}
                <StatusBadge variant={reviewed ? 'info' : 'neutral'}>
                    {reviewed
                        ? 'Reviewed'
                        : evidenceState === 'inferred'
                          ? 'Inferred'
                          : 'Review not recorded'}
                </StatusBadge>
                {edge.freshness && (
                    <StatusBadge
                        variant={
                            edge.freshness.state === 'stale'
                                ? 'warning'
                                : 'neutral'
                        }
                    >
                        {edge.freshness.state === 'current'
                            ? 'Recent evidence'
                            : edge.freshness.state === 'stale'
                              ? 'Older evidence'
                              : 'Evidence age unknown'}
                    </StatusBadge>
                )}
            </div>
            <p className="text-caption text-muted-foreground">
                {edge.evidenceLabel ?? 'Recorded device relationship'}
                {edge.confidence === undefined
                    ? ''
                    : ' · ' +
                      Math.round(edge.confidence * 100) +
                      '% confidence'}
            </p>
            {edge.lastSeenAt && (
                <p className="text-caption text-muted-foreground">
                    Last recorded {formatDateTimeLong(edge.lastSeenAt)}
                    {edge.freshness?.state === 'stale'
                        ? ' · This link needs a newer observation.'
                        : ''}
                </p>
            )}
        </div>
    );
}

export function TopologyMap({ topology }: { topology: TopologyMapData }) {
    // A server history page replaces the inspected snapshot and local graph state.
    return (
        <TopologyMapPage
            key={
                (topology.inventory?.pageKey ?? 'all') +
                ':' +
                (topology.history?.pageKey ?? 'latest') +
                ':' +
                (topology.exploration?.pageKey ?? 'inventory')
            }
            topology={topology}
        />
    );
}

function TopologyMapPage({ topology }: { topology: TopologyMapData }) {
    const [snapshotId, setSnapshotId] = useState(() =>
        !topology.exploration &&
        topology.history?.isOlderPage &&
        topology.history.entries[0]
            ? String(topology.history.entries[0].id)
            : 'latest',
    );
    const [selected, setSelected] = useState<number | null>(
        topology.exploration?.anchorId ?? null,
    );
    const [selectedEdge, setSelectedEdge] = useState<string | null>(null);
    const [destination, setDestination] = useState('');
    const [search, setSearch] = useState('');
    const id = useId();
    const history = topology.exploration
        ? []
        : (topology.history?.entries ?? []);
    const historical = history.find(
        (snapshot) => String(snapshot.id) === snapshotId,
    );
    const nodes = historical?.nodes ?? topology.nodes;
    const supplied = historical?.edges ?? topology.edges;
    const edges = useMemo(
        () => visibleEdges(nodes, supplied),
        [nodes, supplied],
    );
    const selectedNode = nodes.find((node) => node.id === selected);
    const currentEdge = edges.find((edge) => edgeKey(edge) === selectedEdge);
    const target = nodes.find((node) => String(node.id) === destination);
    const path =
        selectedNode && target
            ? connectionPath(nodes, edges, selectedNode.id, target.id)
            : null;
    const matches = search.trim()
        ? nodes.filter((node) =>
              (node.name + ' ' + (node.site ?? ''))
                  .toLowerCase()
                  .includes(search.trim().toLowerCase()),
          )
        : [];
    const selectNode = (node: number) => {
        setSelected(node);
        setSelectedEdge(null);
        setDestination('');
    };
    const snapshots = historical ? [historical] : (topology.snapshots ?? []);
    const changes = historical?.changes ?? topology.changes;
    const viewKey =
        (historical?.id ?? 'latest') +
        ':' +
        nodes.map((node) => node.id).join(',') +
        ':' +
        edges.map((edge) => edgeKey(edge)).join(',');
    if (
        topology.nodes.length === 0 &&
        history.length === 0 &&
        !topology.history?.latestHref &&
        !topology.inventory
    )
        return (
            <EmptyState
                variant="compact"
                icon={GitBranch}
                title="No visible topology nodes"
                description="Topology stays empty until links between registered Devices are found in the current Site view."
            />
        );
    return (
        <div className="space-y-4">
            {topology.exploration && (
                <TopologyExplorationControls
                    exploration={topology.exploration}
                    anchor={nodes.find(
                        (node) => node.id === topology.exploration?.anchorId,
                    )}
                />
            )}
            {!topology.exploration && topology.inventory && (
                <TopologyInventoryControls inventory={topology.inventory} />
            )}
            {!topology.exploration && topology.history?.latestHref && (
                <section
                    aria-label="Snapshot history"
                    className="space-y-3 rounded-lg border bg-card p-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0 space-y-1">
                            <h3 className="text-section-title">
                                {topology.history.isOlderPage
                                    ? 'Older snapshots'
                                    : 'Recent snapshots'}
                            </h3>
                            <p className="text-caption text-muted-foreground">
                                {history.length
                                    ? history.length +
                                      ' recorded ' +
                                      (history.length === 1
                                          ? 'snapshot'
                                          : 'snapshots') +
                                      ' on this page. Choose a snapshot to inspect its connections.'
                                    : 'No recorded snapshots are visible in this view.'}
                            </p>
                        </div>
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={topology.history.latestHref}
                                preserveState={false}
                                preserveScroll
                            >
                                <RefreshCw aria-hidden />
                                {topology.history.isOlderPage
                                    ? 'Latest topology'
                                    : 'Refresh view'}
                            </Link>
                        </Button>
                    </div>
                    {topology.history.positionReset && (
                        <p
                            role="status"
                            className="text-caption text-muted-foreground"
                        >
                            That history position is no longer available.
                            Showing the newest visible snapshots.
                        </p>
                    )}
                    {(topology.history.previousHref ||
                        topology.history.nextHref ||
                        topology.history.isOlderPage) && (
                        <nav
                            aria-label="Topology history pages"
                            className="flex flex-wrap items-center gap-2"
                        >
                            {topology.history.previousHref && (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={topology.history.previousHref}
                                        preserveState={false}
                                        preserveScroll
                                    >
                                        <ChevronLeft aria-hidden /> Newer
                                        snapshots
                                    </Link>
                                </Button>
                            )}
                            {topology.history.nextHref && (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={topology.history.nextHref}
                                        preserveState={false}
                                        preserveScroll
                                    >
                                        Older snapshots{' '}
                                        <ChevronRight aria-hidden />
                                    </Link>
                                </Button>
                            )}
                            {topology.history.isOlderPage &&
                                !topology.history.nextHref && (
                                    <span className="text-caption text-muted-foreground">
                                        Oldest visible snapshots
                                    </span>
                                )}
                        </nav>
                    )}
                </section>
            )}
            <div className="grid items-end gap-4 sm:grid-cols-2">
                <div className="min-w-0 flex-1 space-y-2">
                    <Label htmlFor={id + '-find'}>Find within this view</Label>
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            id={id + '-find'}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="pl-9"
                            placeholder="Device or site name"
                        />
                    </div>
                </div>
                {history.length > 0 && (
                    <div className="min-w-0 flex-1 space-y-2">
                        <Label htmlFor={id + '-snapshot'}>
                            Topology snapshot
                        </Label>
                        <Select
                            value={
                                historical ? String(historical.id) : 'latest'
                            }
                            onValueChange={(value) => {
                                setSnapshotId(value);
                                setSelected(null);
                                setSelectedEdge(null);
                                setDestination('');
                                setSearch('');
                            }}
                        >
                            <SelectTrigger
                                id={id + '-snapshot'}
                                className="[&>span]:block [&>span]:min-w-0 [&>span]:truncate [&>span]:text-left"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="latest">
                                    Latest visible topology
                                </SelectItem>
                                {history.map((snapshot) => (
                                    <SelectItem
                                        key={snapshot.id}
                                        value={String(snapshot.id)}
                                    >
                                        Snapshot {snapshot.id} ·{' '}
                                        {snapshot.site?.name ?? 'Site'} ·{' '}
                                        {sourceLabel(snapshot.source)} ·{' '}
                                        {formatDateTimeLong(
                                            snapshot.capturedAt,
                                        )}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </div>
            {search.trim() && (
                <div
                    className="space-y-2 rounded-lg border p-3"
                    aria-label="Device search results"
                >
                    <p className="text-caption text-muted-foreground">
                        {matches.length}{' '}
                        {matches.length === 1
                            ? 'device matches'
                            : 'devices match'}
                        {matches.length > 10
                            ? ' · Showing the first 10; refine your search.'
                            : ''}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {matches.slice(0, 10).map((node) => (
                            <Button
                                key={node.id}
                                variant="outline"
                                onClick={() => {
                                    selectNode(node.id);
                                    setSearch('');
                                }}
                            >
                                {node.name}
                            </Button>
                        ))}
                    </div>
                </div>
            )}
            {historical && (
                <div className="text-caption rounded-lg border bg-muted/30 p-3">
                    <strong>Historical connections</strong> · Recorded{' '}
                    {formatDateTimeLong(historical.capturedAt)}. Device names,
                    health and access reflect the current authorised records;
                    this is not a replay of past device health.
                </div>
            )}
            {snapshots.length > 0 && (
                <div className="text-caption flex flex-wrap gap-3 text-muted-foreground">
                    {snapshots.map((snapshot) => (
                        <div
                            key={snapshot.id}
                            className="space-y-1 rounded-lg bg-muted p-2"
                        >
                            <div>
                                {snapshot.site ? (
                                    <Link
                                        href={snapshot.site.href}
                                        className="frontline-focus rounded-sm hover:text-primary hover:underline"
                                    >
                                        {snapshot.site.name}
                                    </Link>
                                ) : (
                                    'Site'
                                )}{' '}
                                · {sourceLabel(snapshot.source)} ·{' '}
                                {snapshot.nodeCount} nodes ·{' '}
                                {snapshot.edgeCount} edges ·{' '}
                                {snapshot.changeCount} changes
                            </div>
                            <p>
                                Captured{' '}
                                {formatDateTimeLong(
                                    snapshot.capturedAt,
                                    'Time unavailable',
                                )}
                                {snapshot.freshness?.state === 'stale'
                                    ? ' · Older evidence'
                                    : ''}
                            </p>
                        </div>
                    ))}
                </div>
            )}
            {changes &&
                changes.added + changes.removed + changes.changed > 0 && (
                    <p className="text-caption text-muted-foreground">
                        Since the previous snapshots: {changes.added} added ·{' '}
                        {changes.removed} removed · {changes.changed} changed
                    </p>
                )}
            {!topology.exploration &&
                topology.currentSnapshotsTruncated &&
                !historical && (
                    <p className="text-caption text-muted-foreground">
                        This view includes up to {topology.currentSnapshotLimit}{' '}
                        current source snapshots. Other sources are not shown.
                    </p>
                )}
            {!topology.exploration &&
                topology.history?.truncated &&
                !topology.history.latestHref && (
                    <p className="text-caption text-muted-foreground">
                        Showing up to {topology.history.limit} recent snapshots
                        in this view. Older history is not included.
                    </p>
                )}
            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0">
                    {nodes.length ? (
                        <TopologyGraph
                            key={viewKey}
                            nodes={nodes}
                            edges={edges}
                            selectedNode={selectedNode?.id ?? null}
                            selectedEdge={
                                currentEdge ? edgeKey(currentEdge) : null
                            }
                            path={path}
                            onNode={selectNode}
                            onEdge={(value) => {
                                setSelectedEdge(value);
                                setSelected(null);
                                setDestination('');
                            }}
                        />
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={GitBranch}
                            title={
                                historical
                                    ? 'No visible devices in this snapshot'
                                    : 'No devices match this view'
                            }
                            description={
                                historical
                                    ? 'Historical records are filtered using current device and site access.'
                                    : 'Change the site or device name above to see other devices you may view.'
                            }
                        />
                    )}
                    <p className="text-caption mt-2 text-muted-foreground">
                        Connections show recorded evidence. A connection path
                        does not confirm that traffic can currently pass between
                        devices.
                    </p>
                </div>
                <section
                    aria-label="Topology selection"
                    className="min-w-0 space-y-4 rounded-lg border bg-card p-4"
                >
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-section-title">
                            {selectedNode
                                ? 'Device details'
                                : currentEdge
                                  ? 'Connection details'
                                  : 'Explore the map'}
                        </h3>
                        {(selectedNode || currentEdge) && (
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Clear map selection"
                                onClick={() => {
                                    setSelected(null);
                                    setSelectedEdge(null);
                                    setDestination('');
                                }}
                            >
                                <X aria-hidden />
                            </Button>
                        )}
                    </div>
                    {!selectedNode && !currentEdge && (
                        <p className="text-subtle">
                            Choose a device to inspect its connections, or
                            choose a link to see its ports and evidence.
                        </p>
                    )}
                    {selectedNode && (
                        <>
                            <div className="space-y-2">
                                <Link
                                    className="frontline-focus font-medium break-words text-primary hover:underline"
                                    href={selectedNode.href}
                                >
                                    Open {selectedNode.name}
                                </Link>
                                <p className="text-subtle">
                                    {selectedNode.site ?? 'Site not assigned'}
                                </p>
                                <StatusBadge
                                    status={selectedNode.health ?? 'unknown'}
                                />
                            </div>
                            {selectedNode.exploreHref &&
                                selectedNode.id !==
                                    topology.exploration?.anchorId && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="h-auto w-full whitespace-normal"
                                    >
                                        <Link
                                            href={selectedNode.exploreHref}
                                            preserveState={false}
                                            preserveScroll
                                        >
                                            <GitBranch aria-hidden />
                                            Explore current connections
                                        </Link>
                                    </Button>
                                )}
                            <div className="space-y-2">
                                <Label htmlFor={id + '-trace'}>
                                    Trace a connection to
                                </Label>
                                <Select
                                    value={target ? String(target.id) : ''}
                                    onValueChange={setDestination}
                                >
                                    <SelectTrigger id={id + '-trace'}>
                                        <SelectValue placeholder="Choose another device" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {nodes
                                            .filter(
                                                (node) =>
                                                    node.id !== selectedNode.id,
                                            )
                                            .map((node) => (
                                                <SelectItem
                                                    key={node.id}
                                                    value={String(node.id)}
                                                >
                                                    {node.name}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {target && (
                                <div
                                    aria-live="polite"
                                    className="space-y-2 rounded-lg bg-muted p-3"
                                >
                                    <p className="text-caption">
                                        {path
                                            ? 'Recorded connection path'
                                            : 'No recorded connection path in this view.'}
                                    </p>
                                    {path &&
                                        edges.some(
                                            (edge) =>
                                                path.edges.includes(
                                                    edgeKey(edge),
                                                ) &&
                                                edge.freshness?.state ===
                                                    'stale',
                                        ) && (
                                            <StatusBadge variant="warning">
                                                This path includes older
                                                evidence
                                            </StatusBadge>
                                        )}
                                    {path &&
                                        edges.some(
                                            (edge) =>
                                                path.edges.includes(
                                                    edgeKey(edge),
                                                ) &&
                                                (!edge.freshness ||
                                                    edge.freshness.state ===
                                                        'unknown'),
                                        ) && (
                                            <p className="text-caption text-muted-foreground">
                                                Some links have no confirmed
                                                observation time.
                                            </p>
                                        )}
                                    {path && (
                                        <ol className="space-y-2">
                                            {path.nodes.map((nodeId, index) => {
                                                const node = nodes.find(
                                                    (item) =>
                                                        item.id === nodeId,
                                                )!;
                                                return (
                                                    <li
                                                        key={nodeId}
                                                        className="text-caption flex items-center gap-2"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            {index + 1}.
                                                        </span>
                                                        <Button
                                                            variant="link"
                                                            className="text-caption h-auto justify-start p-0 text-left whitespace-normal"
                                                            onClick={() =>
                                                                selectNode(
                                                                    nodeId,
                                                                )
                                                            }
                                                        >
                                                            {node.name}
                                                        </Button>
                                                    </li>
                                                );
                                            })}
                                        </ol>
                                    )}
                                </div>
                            )}
                            <div className="space-y-2">
                                <h4 className="font-medium">
                                    Recorded connections
                                </h4>
                                {edges.filter(
                                    (edge) =>
                                        edge.parentId === selectedNode.id ||
                                        edge.childId === selectedNode.id,
                                ).length === 0 ? (
                                    <p className="text-subtle">
                                        No connections are shown for this device
                                        in this view.
                                    </p>
                                ) : (
                                    edges
                                        .filter(
                                            (edge) =>
                                                edge.parentId ===
                                                    selectedNode.id ||
                                                edge.childId ===
                                                    selectedNode.id,
                                        )
                                        .map((edge) => (
                                            <Button
                                                variant="outline"
                                                key={edgeKey(edge)}
                                                className="block h-auto w-full p-3 text-left whitespace-normal"
                                                onClick={() => {
                                                    setSelectedEdge(
                                                        edgeKey(edge),
                                                    );
                                                    setSelected(null);
                                                    setDestination('');
                                                }}
                                            >
                                                <span className="text-caption block font-medium break-words">
                                                    {edge.parentId ===
                                                    selectedNode.id
                                                        ? edge.childName
                                                        : edge.parentName}
                                                </span>
                                                <span className="text-caption text-muted-foreground">
                                                    {edge.port ?? edge.label}
                                                </span>
                                            </Button>
                                        ))
                                )}
                            </div>
                        </>
                    )}
                    {currentEdge && (
                        <>
                            <p className="font-medium break-words">
                                {currentEdge.parentName} →{' '}
                                {currentEdge.childName}
                            </p>
                            <Evidence edge={currentEdge} />
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        selectNode(currentEdge.parentId)
                                    }
                                >
                                    Inspect source
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        selectNode(currentEdge.childId)
                                    }
                                >
                                    Inspect destination
                                </Button>
                            </div>
                        </>
                    )}
                </section>
            </div>
            {historical && (
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-label="Snapshot comparison"
                >
                    <h3 className="text-section-title">
                        Changes from the previous snapshot
                    </h3>
                    {historical.comparison.state !== 'compared' ? (
                        <p className="text-subtle">
                            {historical.comparison.state === 'baseline'
                                ? 'This is the first available snapshot for this site and source.'
                                : 'A comparable earlier snapshot is not available in this view.'}
                        </p>
                    ) : (
                        <>
                            <p className="text-caption text-muted-foreground">
                                Compared with the previous snapshot from this
                                site and source. Only relationships between
                                currently visible devices are included.
                            </p>
                            {(['addedEdges', 'removedEdges'] as const).map(
                                (kind) => (
                                    <div key={kind}>
                                        <h4 className="font-medium">
                                            {kind === 'addedEdges'
                                                ? 'Added'
                                                : 'Removed'}{' '}
                                            ·{' '}
                                            {historical.comparison[kind].length}
                                        </h4>
                                        <ul className="mt-2 space-y-2">
                                            {historical.comparison[kind].map(
                                                (edge) => (
                                                    <li
                                                        key={edgeKey(edge)}
                                                        className="space-y-2 rounded-lg bg-muted/30 p-3"
                                                    >
                                                        <p className="text-caption">
                                                            {edge.parentName} →{' '}
                                                            {edge.childName}
                                                        </p>
                                                        <Evidence edge={edge} />
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                ),
                            )}
                            <div>
                                <h4 className="font-medium">
                                    Changed ·{' '}
                                    {historical.comparison.changedEdges.length}
                                </h4>
                                <ul className="mt-2 space-y-2">
                                    {historical.comparison.changedEdges.map(
                                        ({ before, after }) => (
                                            <li
                                                key={edgeKey(after)}
                                                className="space-y-2 rounded-lg bg-muted/30 p-3"
                                            >
                                                <p className="text-caption">
                                                    {after.parentName} →{' '}
                                                    {after.childName}
                                                </p>
                                                <p className="text-caption text-muted-foreground">
                                                    Previously:{' '}
                                                    {before.port ??
                                                        'No port recorded'}{' '}
                                                    · {before.label}
                                                </p>
                                                <Evidence edge={after} />
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        </>
                    )}
                </section>
            )}
            <section aria-label="Device directory" className="space-y-2">
                <h3 className="text-section-title">Devices in this view</h3>
                <div className="flex flex-wrap gap-2">
                    {nodes.map((node) => (
                        <Link
                            key={node.id}
                            href={node.href}
                            className="frontline-focus text-caption rounded-lg border px-3 py-2 hover:bg-accent"
                        >
                            {node.name}
                        </Link>
                    ))}
                </div>
            </section>
            <section
                aria-label="Keyboard-readable topology relationships"
                className="space-y-2"
            >
                <h3 className="text-section-title">
                    Evidence and relationships
                </h3>
                {edges.length ? (
                    <div className="grid gap-2 lg:grid-cols-2">
                        {edges.map((edge) => (
                            <article
                                key={edgeKey(edge)}
                                tabIndex={0}
                                className="frontline-focus space-y-2 rounded-lg border p-3"
                            >
                                <div className="text-caption flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {edge.parentName}
                                    </span>
                                    <ArrowRight
                                        className="size-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span className="font-medium">
                                        {edge.childName}
                                    </span>
                                </div>
                                <Evidence edge={edge} />
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="text-subtle">
                        No visible relationships are present in this view.
                    </p>
                )}
            </section>
        </div>
    );
}
