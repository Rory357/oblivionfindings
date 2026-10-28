import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { deviceCategoryIcon } from '@/pages/security-devices/devices/device-profile-header';
import type {
    SiteTopologyTree,
    SiteTopologyTreeNode,
} from '@/pages/security-devices/sites/_types';
import { Link } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    GitBranch,
    Network,
    Router,
    Wifi,
} from 'lucide-react';
import { createElement, useMemo, useState } from 'react';

function humanise(value: string | null | undefined): string {
    if (!value) return 'Unknown';

    const spaced = value.replace(/_/g, ' ').trim().toLowerCase();
    if (!spaced) return 'Unknown';

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function nodeStatusVariant(
    status: string,
    health: string,
): StatusVariant {
    if (status === 'offline' || health === 'critical') return 'critical';
    if (status === 'degraded' || health === 'warning') return 'warning';
    if (status === 'active' || health === 'healthy') return 'success';
    if (status === 'maintenance') return 'info';

    return 'neutral';
}

function tierLabel(tier: string): string {
    switch (tier) {
        case 'gateway':
            return 'Gateway';
        case 'switch':
            return 'Switch';
        case 'access_point':
            return 'Access point';
        default:
            return 'Device';
    }
}

function renderTierIcon(node: SiteTopologyTreeNode) {
    const className = 'h-4 w-4 shrink-0 text-muted-foreground';
    if (node.tier === 'gateway') return <Router className={className} />;
    if (node.tier === 'switch') return <Network className={className} />;
    if (node.tier === 'access_point') return <Wifi className={className} />;

    return createElement(deviceCategoryIcon(node.category), { className });
}

function sourceDisplayLabel(source: string): string {
    switch (source) {
        case 'lldp':
            return 'LLDP';
        case 'cdp':
            return 'CDP';
        case 'unifi':
            return 'UniFi';
        case 'bridge':
            return 'Switch MAC table';
        case 'arp':
        case 'route':
            return 'ARP gateway';
        case 'lan_subnet':
            return 'LAN subnet';
        case 'manual':
            return 'Linked';
        default:
            return humanise(source);
    }
}

function collectBranchIds(nodes: SiteTopologyTreeNode[]): number[] {
    const ids: number[] = [];
    const walk = (list: SiteTopologyTreeNode[]) => {
        for (const item of list) {
            if (item.children.length > 0) {
                ids.push(item.id);
                walk(item.children);
            }
        }
    };
    walk(nodes);

    return ids;
}

function TopologyBranch({
    node,
    depth,
    collapsedIds,
    onToggleCollapse,
}: {
    node: SiteTopologyTreeNode;
    depth: number;
    collapsedIds: Set<number>;
    onToggleCollapse: (id: number) => void;
}) {
    const hasChildren = node.children.length > 0;
    const isCollapsed = collapsedIds.has(node.id);

    return (
        <div
            data-testid={`site-topology-node-${node.id}`}
            className="space-y-2"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 transition-colors hover:border-primary/40">
                <div className="flex min-w-0 items-center gap-2.5">
                    {hasChildren ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-7 w-7 shrink-0 p-0"
                            aria-label={
                                isCollapsed
                                    ? `Expand ${node.name}`
                                    : `Collapse ${node.name}`
                            }
                            onClick={() => onToggleCollapse(node.id)}
                        >
                            {isCollapsed ? (
                                <ChevronRight className="h-4 w-4" />
                            ) : (
                                <ChevronDown className="h-4 w-4" />
                            )}
                        </Button>
                    ) : (
                        <span
                            aria-hidden="true"
                            className="inline-block w-7 shrink-0 text-center text-muted-foreground"
                        >
                            {depth > 0 ? '└' : '•'}
                        </span>
                    )}
                    {renderTierIcon(node)}
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={node.href}
                                className="truncate text-sm font-semibold text-primary underline-offset-2 hover:underline"
                            >
                                {node.name}
                            </Link>
                            <StatusBadge variant="neutral">
                                {tierLabel(node.tier)}
                            </StatusBadge>
                        </div>
                        <p className="text-caption">
                            {[
                                node.ip_address,
                                [node.manufacturer, node.model]
                                    .filter(Boolean)
                                    .join(' '),
                            ]
                                .filter(Boolean)
                                .join(' · ') || node.device_uid}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    {node.uplink && (
                        <StatusBadge variant="info">
                            {node.uplink.label}
                        </StatusBadge>
                    )}
                    {hasChildren && (
                        <span className="text-caption">
                            {node.children.length}{' '}
                            {node.children.length === 1
                                ? 'connected device'
                                : 'connected devices'}
                        </span>
                    )}
                    <StatusBadge
                        variant={nodeStatusVariant(node.status, node.health)}
                    >
                        {humanise(node.status)}
                    </StatusBadge>
                </div>
            </div>

            {hasChildren && !isCollapsed && (
                <div className="ml-3.5 space-y-2 border-l-2 border-border pl-4">
                    {node.children.map((child) => (
                        <TopologyBranch
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            collapsedIds={collapsedIds}
                            onToggleCollapse={onToggleCollapse}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export function SiteTopologyTreeCard({
    tree,
    siteName,
}: {
    tree?: SiteTopologyTree;
    siteName?: string;
}) {
    const branchIds = useMemo(
        () => (tree ? collectBranchIds(tree.roots) : []),
        [tree],
    );
    const [collapsedIds, setCollapsedIds] = useState<Set<number>>(
        () => new Set(),
    );
    const [showUnplaced, setShowUnplaced] = useState(true);

    if (!tree) return null;

    const totalNodes =
        tree.tier_counts.gateways +
        tree.tier_counts.switches +
        tree.tier_counts.access_points +
        tree.tier_counts.devices;

    const toggleNode = (id: number) => {
        setCollapsedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    return (
        <Card data-testid="site-topology-tree">
            <CardHeader className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2">
                            <GitBranch className="h-4 w-4" />
                            Automatic Site network map
                        </CardTitle>
                        <CardDescription>
                            Hierarchy built from hub discovery (LLDP, CDP,
                            switch MAC tables, ARP, and UniFi)
                            {siteName ? ` at ${siteName}` : ''}.
                            {tree.last_captured_at
                                ? ` Last updated ${formatDateTime(tree.last_captured_at)}.`
                                : ''}
                        </CardDescription>
                    </div>
                    {branchIds.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setCollapsedIds(new Set())}
                            >
                                Expand all
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setCollapsedIds(new Set(branchIds))
                                }
                            >
                                Collapse all
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant="neutral">
                        {tree.tier_counts.gateways}{' '}
                        {tree.tier_counts.gateways === 1
                            ? 'Gateway'
                            : 'Gateways'}
                    </StatusBadge>
                    <StatusBadge variant="neutral">
                        {tree.tier_counts.switches}{' '}
                        {tree.tier_counts.switches === 1
                            ? 'Switch'
                            : 'Switches'}
                    </StatusBadge>
                    <StatusBadge variant="neutral">
                        {tree.tier_counts.access_points}{' '}
                        {tree.tier_counts.access_points === 1
                            ? 'Access point'
                            : 'Access points'}
                    </StatusBadge>
                    <StatusBadge variant="neutral">
                        {tree.tier_counts.devices}{' '}
                        {tree.tier_counts.devices === 1 ? 'Device' : 'Devices'}
                    </StatusBadge>
                    {tree.sources.map((src) => (
                        <StatusBadge key={src} variant="info">
                            {sourceDisplayLabel(src)}
                        </StatusBadge>
                    ))}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {totalNodes === 0 ? (
                    <EmptyState
                        icon={GitBranch}
                        title="No devices at this Site yet"
                        description="Scan this Site from a hub or assign devices to build the Site network map."
                        variant="compact"
                    />
                ) : (
                    <>
                        {tree.roots.length > 0 ? (
                            <div className="space-y-3">
                                {tree.roots.map((root) => (
                                    <TopologyBranch
                                        key={root.id}
                                        node={root}
                                        depth={0}
                                        collapsedIds={collapsedIds}
                                        onToggleCollapse={toggleNode}
                                    />
                                ))}
                            </div>
                        ) : (
                            <p className="rounded-lg bg-muted/30 p-3 text-caption">
                                No gateway, switch, or access point root has
                                been identified yet. Devices without a reported
                                uplink are listed below.
                            </p>
                        )}

                        {tree.unplaced.length > 0 && (
                            <div className="space-y-2 border-t pt-3">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-semibold">
                                        Unplaced devices ({tree.unplaced.length}
                                        )
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setShowUnplaced((prev) => !prev)
                                        }
                                    >
                                        {showUnplaced ? 'Hide' : 'Show'}
                                    </Button>
                                </div>
                                <p className="text-caption">
                                    These devices are assigned to this Site,
                                    but no switch port, access point, or LAN
                                    gateway uplink has been reported for them
                                    yet.
                                </p>
                                {showUnplaced && (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {tree.unplaced.map((item) => (
                                            <div
                                                key={item.id}
                                                className="flex items-center justify-between gap-2 rounded-lg border p-2.5"
                                            >
                                                <div className="flex min-w-0 items-center gap-2">
                                                    {renderTierIcon(item)}
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={item.href}
                                                            className="truncate text-sm font-medium text-primary underline-offset-2 hover:underline"
                                                        >
                                                            {item.name}
                                                        </Link>
                                                        <p className="text-caption">
                                                            {item.ip_address ??
                                                                item.device_uid}
                                                        </p>
                                                    </div>
                                                </div>
                                                <StatusBadge
                                                    variant={nodeStatusVariant(
                                                        item.status,
                                                        item.health,
                                                    )}
                                                >
                                                    {humanise(item.status)}
                                                </StatusBadge>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
