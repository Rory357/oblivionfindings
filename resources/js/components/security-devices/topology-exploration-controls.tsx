import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react';
import { WorkspaceFilterBar } from './security-devices-workspace-shell';
import type { TopologyExploration, TopologyNode } from './topology-types';

export function TopologyExplorationControls({
    exploration,
    anchor,
}: {
    exploration: TopologyExploration;
    anchor: TopologyNode | undefined;
}) {
    return (
        <WorkspaceFilterBar>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-2">
                    <h3 className="text-section-title break-words">
                        Connections around {anchor?.name ?? 'this device'}
                    </h3>
                    <p className="text-caption text-muted-foreground">
                        Current recorded connections
                        {anchor?.site ? ' · ' + anchor.site : ''}
                    </p>
                    <StatusBadge variant="info">
                        Focused device view
                    </StatusBadge>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={exploration.inventoryHref}
                            preserveState={false}
                            preserveScroll
                        >
                            <ArrowLeft aria-hidden />
                            Site map
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link
                            href={exploration.refreshHref}
                            preserveState={false}
                            preserveScroll
                        >
                            <RefreshCw aria-hidden />
                            Refresh connections
                        </Link>
                    </Button>
                </div>
            </div>
            <p className="text-caption text-muted-foreground">
                {exploration.neighborsTotal === 0
                    ? 'No visible connected devices were found in this evidence view.'
                    : 'Showing ' +
                      exploration.neighborsShown +
                      ' of ' +
                      exploration.neighborsTotal +
                      ' visible connected devices in this evidence view.'}
            </p>
            <p className="text-caption text-muted-foreground">
                This view follows direct recorded connections at this site.
                Select a connected device, then explore its connections to
                continue through the network.
            </p>
            {(exploration.sourcesTruncated || exploration.edgesTruncated) && (
                <p role="status" className="text-caption text-muted-foreground">
                    {exploration.sourcesTruncated
                        ? 'Some current evidence sources are outside this view. '
                        : ''}
                    {exploration.edgesTruncated
                        ? 'Some additional connection evidence between these devices is not shown.'
                        : ''}
                </p>
            )}
            {exploration.positionReset && (
                <p role="status" className="text-caption text-muted-foreground">
                    That connection page is no longer available. Showing the
                    first visible connected devices.
                </p>
            )}
            {(exploration.previousHref || exploration.nextHref) && (
                <nav
                    aria-label="Connected device pages"
                    className="flex flex-wrap gap-2"
                >
                    {exploration.previousHref && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={exploration.previousHref}
                                preserveState={false}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden /> Previous connected
                                devices
                            </Link>
                        </Button>
                    )}
                    {exploration.nextHref && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={exploration.nextHref}
                                preserveState={false}
                                preserveScroll
                            >
                                More connected devices{' '}
                                <ChevronRight aria-hidden />
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </WorkspaceFilterBar>
    );
}
