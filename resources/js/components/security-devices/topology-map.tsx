import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Link } from '@inertiajs/react';
import { ArrowRight, GitBranch, Network } from 'lucide-react';

export type TopologyMapData = {
    source?: string;
    nodes: Array<{
        id: number;
        name: string;
        site: string | null;
        href: string;
        health: string | null;
    }>;
    edges: Array<{
        id: number | string;
        parentId: number;
        parentName: string;
        childId: number;
        childName: string;
        label: string;
        port: string | null;
        source?: string;
        confidence?: number;
        reviewState?: string;
        evidenceLabel?: string;
    }>;
    snapshots?: Array<{
        id: number;
        site: { id: number; name: string; href: string } | null;
        source: string;
        capturedAt: string | null;
        nodeCount: number;
        edgeCount: number;
        changeCount: number;
    }>;
    changes?: { added: number; removed: number; changed: number };
};

function label(value: string | null | undefined): string {
    if (!value) return 'Unknown';
    return value
        .replaceAll('_', ' ')
        .replaceAll(':', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function sourceLabel(source: string): string {
    if (source === 'snmp') return 'SNMP';
    if (source === 'native:snmp') return 'Native SNMP';
    if (source.startsWith('provider:')) {
        const provider = source.slice('provider:'.length);
        return `${provider === 'unifi' ? 'UniFi' : label(provider)} provider`;
    }

    return label(source);
}

export function TopologyMap({ topology }: { topology: TopologyMapData }) {
    if (topology.nodes.length === 0) {
        return (
            <EmptyState
                variant="compact"
                icon={GitBranch}
                title="No visible topology nodes"
                description="Topology stays empty until evidence links canonical Devices in the current Site view."
            />
        );
    }

    return (
        <div className="space-y-4">
            {(topology.snapshots?.length ?? 0) > 0 ? (
                <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                    {topology.snapshots?.map((snapshot) => (
                        <span
                            key={snapshot.id}
                            className="rounded-lg bg-muted p-2"
                        >
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
                            {snapshot.nodeCount} nodes · {snapshot.edgeCount}{' '}
                            edges · {snapshot.changeCount} changes
                        </span>
                    ))}
                </div>
            ) : null}
            {topology.changes &&
            topology.changes.added +
                topology.changes.removed +
                topology.changes.changed >
                0 ? (
                <p className="rounded-lg border bg-muted/20 p-3 text-xs text-muted-foreground">
                    Since the previous snapshots: {topology.changes.added} added
                    {' · '}
                    {topology.changes.removed} removed
                    {' · '}
                    {topology.changes.changed} changed
                </p>
            ) : null}
            <div
                aria-label="Topology visual overview"
                className="grid gap-2 rounded-xl border bg-muted/20 p-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                {topology.nodes.map((node) => (
                    <Link
                        key={node.id}
                        href={node.href}
                        className="frontline-focus flex min-h-11 items-center gap-3 rounded-lg border bg-background p-3"
                    >
                        <Network className="h-4 w-4 text-primary" aria-hidden />
                        <span className="min-w-0">
                            <strong className="block truncate text-sm">
                                {node.name}
                            </strong>
                            <span className="block truncate text-xs text-muted-foreground">
                                {node.site ?? 'Site not assigned'} ·{' '}
                                {label(node.health)}
                            </span>
                        </span>
                    </Link>
                ))}
            </div>
            <section aria-label="Keyboard-readable topology relationships">
                <h3 className="mb-2 text-sm font-semibold">
                    Evidence and relationships
                </h3>
                {topology.edges.length ? (
                    <div className="space-y-2">
                        {topology.edges.map((edge) => (
                            <article
                                key={edge.id}
                                tabIndex={0}
                                className="frontline-focus rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="font-medium">
                                        {edge.parentName}
                                    </span>
                                    <ArrowRight
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span className="font-medium">
                                        {edge.childName}
                                    </span>
                                    <Badge variant="outline">
                                        {edge.label}
                                    </Badge>
                                    {edge.port ? (
                                        <Badge variant="secondary">
                                            {edge.port}
                                        </Badge>
                                    ) : null}
                                    <Badge variant="secondary">
                                        {label(edge.reviewState ?? 'reviewed')}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {edge.evidenceLabel ??
                                        'Reviewed canonical Device relationship'}
                                    {edge.confidence === undefined
                                        ? ''
                                        : ` · ${Math.round(edge.confidence * 100)}% confidence`}
                                </p>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        No visible relationships are present in the latest
                        evidence.
                    </p>
                )}
            </section>
        </div>
    );
}
