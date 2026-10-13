import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    CheckCircle2,
    ChevronRight,
    CircleHelp,
    Router,
    Server,
    ShieldCheck,
    TicketCheck,
    Wifi,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { StateBadge } from './network-evidence-display';
import {
    NetworkOperationalRecords,
    type NetworkRecordsView,
} from './network-operational-records';

import { TopologyMap } from './topology-map';
import type { TopologyMapData } from './topology-types';

type NetworkAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

export type NetworkDevice = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    status: string | null;
    health: string | null;
    lastSeenAt: string | null;
    href: string;
    site: { id: number; name: string; href: string } | null;
    identifiers: {
        ipAddress: string | null;
        macAddress: string | null;
        serialNumber: string | null;
    };
    firmwareVersion: string | null;
    monitoring: { enabled: number; attention: number; uncertain: number };
    wanPath: boolean;
};

export type NetworkInterface = {
    monitorId: number;
    deviceId: number;
    deviceName: string;
    deviceHref: string;
    name: string;
    index: number | null;
    state: string | null;
    enabled: boolean;
    adminStatus: string | null;
    operationalStatus: string | null;
    speedBps: number | null;
    inBps: number | null;
    outBps: number | null;
    inUtilisation: number | null;
    outUtilisation: number | null;
    errors: number | null;
    discards: number | null;
    capacityState: string;
    observedAt: string | null;
};

export type NetworkService = {
    id: number;
    deviceId: number;
    deviceName: string;
    deviceHref: string;
    name: string;
    kind: string | null;
    kindLabel: string;
    state: string | null;
    enabled: boolean;
    affectsAvailability: boolean;
    lastObservationAt: string | null;
    dependentCount: number;
    collector: {
        name: string;
        status: string;
        lastSeenAt: string | null;
    } | null;
};

export type TrafficRow = {
    monitorId: number;
    deviceId: number;
    deviceName: string;
    deviceHref: string;
    interface: string;
    speedBps: number | null;
    inBps: number | null;
    outBps: number | null;
    inUtilisation: number | null;
    outUtilisation: number | null;
    state: string;
    observedAt: string | null;
    source: string;
};

export type ConfigurationSnapshotRow = {
    id: number;
    sourceKind: string;
    source: string;
    capturedAt: string | null;
    contentHash: string;
    configurationHash: string;
    contentSize: number;
    mimeType: string;
    firmwareVersion: string | null;
    storageState: string;
    previousSnapshotId: number | null;
    diff: {
        added: string[];
        removed: string[];
        changed: string[];
        count: number;
        truncated: boolean;
    };
    downloadHref: string | null;
};

export type ConfigurationRow = {
    deviceId: number;
    deviceName: string;
    deviceHref: string;
    configuration: {
        state: string;
        observedHash: string | null;
        desiredHash: string | null;
        observedAt: string | null;
    };
    firmware: {
        state: string;
        currentVersion: string | null;
        desiredVersion: string | null;
        observedAt: string | null;
    };
    latestSnapshot?: ConfigurationSnapshotRow | null;
    snapshotHistory?: ConfigurationSnapshotRow[];
    snapshotHistoryTruncated?: boolean;
};

export type NetworkItWorkspaceData = {
    permissions: { viewItWork: boolean };
    boundary: {
        title: string;
        description: string;
        collectionNote: string;
        managementNote: string;
    };
    overview: {
        inventory: {
            devices: number;
            sites: number;
            wan_paths: number;
            monitored_devices: number;
            unmonitored_devices: number;
        };
        monitoring: {
            enabled: number;
            healthy: number;
            attention: number;
            uncertain: number;
        };
        evidence: {
            topology_edges: number;
            interfaces: number;
            capacity_series: number;
            configuration: number;
            firmware: number;
        };
        attention: {
            devices: number;
            monitoring: number;
            capacity: number;
            configuration: number;
            firmware: number;
            open_work: number | null;
        };
        requiredActions: NetworkAction[];
        sites: Array<{
            id: number;
            name: string;
            href: string;
            devices: number;
            monitoredDevices: number;
            attention: number;
        }>;
        wanPaths: Array<{
            id: number;
            name: string;
            site: string | null;
            state: string;
            lastSeenAt: string | null;
            href: string;
        }>;
        itWork: Array<{
            id: number;
            reference: string | null;
            title: string;
            status: string;
            href: string;
        }>;
    };
    activeTab: {
        key: string;
        label: string;
        description: string;
        inventoryTotal: number;
        inventoryShown: number;
        inventoryTruncated: boolean;
        devices: NetworkDevice[];
        topology: TopologyMapData & {
            state: string;
            label: string;
            nodeCount: number;
            edgeCount: number;
            unlinkedCount: number;
        };
        interfaces: NetworkInterface[];
        services: NetworkService[];
        traffic: TrafficRow[];
        configuration: ConfigurationRow[];
        gaps: {
            devicesWithoutMonitors: number;
            devicesWithoutInterfaceEvidence: number;
            devicesWithoutCapacityEvidence: number;
            devicesWithoutConfigurationEvidence: number;
            devicesWithoutFirmwareEvidence: number;
            devicesWithoutServiceChecks: number;
        };
    };
};

function MetricCard({
    value,
    label,
    note,
}: {
    icon: ReactNode;
    value: number;
    label: string;
    note: string;
}) {
    const factLabel = label
        .replace(/^\d+\s+/, '')
        .replace(/^./, (character) => character.toUpperCase());
    return (
        <ReviewRow
            label={factLabel}
            value={
                <span className="inline-flex flex-col items-end gap-0.5">
                    <span className="tabular-nums">{value}</span>
                    <span className="text-caption font-normal">{note}</span>
                </span>
            }
        />
    );
}

function EvidenceBoundary({
    data,
}: {
    data: NetworkItWorkspaceData['boundary'];
}) {
    return (
        <section className="rounded-xl border border-status-info/30 bg-status-info-bg p-4 text-foreground">
            <div className="flex items-start gap-3">
                <ShieldCheck
                    className="mt-0.5 h-5 w-5 shrink-0 text-status-info"
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <h2 className="font-semibold">{data.title}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {data.description}
                    </p>
                    <div className="mt-3 grid gap-2 text-xs text-muted-foreground md:grid-cols-2">
                        <p>{data.collectionNote}</p>
                        <p>{data.managementNote}</p>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Overview({ data }: { data: NetworkItWorkspaceData }) {
    const { overview } = data;
    return (
        <div className="space-y-5">
            <section>
                <div className="mb-3">
                    <h2 className="text-lg font-semibold">
                        Network operations at a glance
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        SD-WAN visibility, monitoring coverage, retained
                        evidence, and linked technical work.
                    </p>
                </div>
                <div className="grid gap-5 xl:grid-cols-2">
                    <ReviewCard icon={Server} title="Equipment and coverage">
                        <MetricCard
                            icon={
                                <Server
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            }
                            value={overview.inventory.devices}
                            label={`${overview.inventory.devices} devices`}
                            note="Visible canonical Network & IT inventory"
                        />
                        <MetricCard
                            icon={
                                <Router
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            }
                            value={overview.inventory.wan_paths}
                            label={`${overview.inventory.wan_paths} WAN paths identified`}
                            note="Classified edge, gateway, firewall, or SD-WAN devices"
                        />
                        <MetricCard
                            icon={
                                <Activity
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            }
                            value={overview.inventory.monitored_devices}
                            label={`${overview.inventory.monitored_devices} monitored devices`}
                            note={`${overview.inventory.unmonitored_devices} without an enabled check`}
                        />
                    </ReviewCard>
                    <ReviewCard icon={Building2} title="Sites and support">
                        <MetricCard
                            icon={
                                <Building2
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            }
                            value={overview.inventory.sites}
                            label={`${overview.inventory.sites} authorised sites`}
                            note="Sites represented by current assignments"
                        />
                        <MetricCard
                            icon={
                                <TicketCheck
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            }
                            value={overview.attention.open_work ?? 0}
                            label={
                                overview.attention.open_work === null
                                    ? 'IT work restricted'
                                    : `${overview.attention.open_work} open IT items`
                            }
                            note="Permission-aware linked technical work"
                        />
                    </ReviewCard>
                </div>
            </section>

            <EvidenceBoundary data={data.boundary} />

            <section className="grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
                <Card className="min-w-0">
                    <CardHeader className="pb-3">
                        <h3 className="font-semibold">Required action</h3>
                        <p className="text-sm text-muted-foreground">
                            Evidence-backed issues only.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overview.requiredActions.length === 0 ? (
                            <EmptyLine
                                icon={<CheckCircle2 className="h-4 w-4" />}
                                text="No current evidence-backed actions."
                            />
                        ) : (
                            overview.requiredActions.map((action) => (
                                <Link
                                    key={action.key}
                                    href={action.href}
                                    className="flex min-h-12 items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <AlertTriangle
                                        className="h-4 w-4 shrink-0 text-status-warning"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium">
                                            {action.count} {action.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {action.description}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        className="h-4 w-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="pb-3">
                        <h3 className="font-semibold">
                            Native monitoring state
                        </h3>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3">
                        <SmallMetric
                            label="Enabled checks"
                            value={overview.monitoring.enabled}
                        />
                        <SmallMetric
                            label="Healthy"
                            value={overview.monitoring.healthy}
                            tone="healthy"
                        />
                        <SmallMetric
                            label="Needs attention"
                            value={overview.monitoring.attention}
                            tone="warning"
                        />
                        <SmallMetric
                            label="Uncertain or stale"
                            value={overview.monitoring.uncertain}
                            tone="unknown"
                        />
                    </CardContent>
                </Card>
            </section>

            <section className="grid gap-4 xl:grid-cols-3">
                <Card className="min-w-0">
                    <CardHeader className="pb-3">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <Wifi className="h-4 w-4" aria-hidden="true" /> WAN
                            / SD-WAN
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overview.wanPaths.length === 0 ? (
                            <EmptyLine
                                icon={<CircleHelp className="h-4 w-4" />}
                                text="No WAN path is classified yet."
                            />
                        ) : (
                            overview.wanPaths.map((path) => (
                                <Link
                                    key={path.id}
                                    href={path.href}
                                    className="flex min-h-12 items-center justify-between gap-3 rounded-lg border p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">
                                            {path.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {path.site ?? 'Site not assigned'} ·{' '}
                                            {path.lastSeenAt
                                                ? formatRelative(
                                                      path.lastSeenAt,
                                                  )
                                                : 'Never observed'}
                                        </span>
                                    </span>
                                    <StateBadge state={path.state} />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="pb-3">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <Building2 className="h-4 w-4" aria-hidden="true" />{' '}
                            Site coverage
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overview.sites.length === 0 ? (
                            <EmptyLine
                                icon={<CircleHelp className="h-4 w-4" />}
                                text="No site assignments are visible."
                            />
                        ) : (
                            overview.sites.map((site) => (
                                <Link
                                    key={site.id}
                                    href={site.href}
                                    className="flex min-h-12 items-center justify-between gap-3 rounded-lg border p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span>
                                        <span className="block text-sm font-medium">
                                            {site.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {site.monitoredDevices}/
                                            {site.devices} devices monitored
                                        </span>
                                    </span>
                                    <StateBadge
                                        state={
                                            site.attention > 0
                                                ? 'warning'
                                                : 'healthy'
                                        }
                                    />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="pb-3">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <TicketCheck
                                className="h-4 w-4"
                                aria-hidden="true"
                            />{' '}
                            Open technical work
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {!data.permissions.viewItWork ? (
                            <EmptyLine
                                icon={<ShieldCheck className="h-4 w-4" />}
                                text="IT work is restricted by destination permission."
                            />
                        ) : overview.itWork.length === 0 ? (
                            <EmptyLine
                                icon={<CheckCircle2 className="h-4 w-4" />}
                                text="No linked open IT work."
                            />
                        ) : (
                            overview.itWork.map((ticket) => (
                                <Link
                                    key={ticket.id}
                                    href={ticket.href}
                                    className="flex min-h-12 items-center justify-between gap-3 rounded-lg border p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">
                                            {ticket.reference} {ticket.title}
                                        </span>
                                    </span>
                                    <StateBadge state={ticket.status} />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>
            </section>
        </div>
    );
}

function SmallMetric({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: string;
}) {
    return (
        <div className="rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">{label}</p>
                {tone ? (
                    <StateBadge state={tone} label={String(value)} />
                ) : null}
            </div>
            {!tone ? (
                <p className="mt-1 text-xl font-semibold tabular-nums">
                    {value}
                </p>
            ) : null}
        </div>
    );
}

function EmptyLine({ icon, text }: { icon: ReactNode; text: string }) {
    return (
        <div className="flex min-h-12 items-center gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
            {icon}
            <span>{text}</span>
        </div>
    );
}

function TopologyPanel({
    topology,
}: {
    topology: NetworkItWorkspaceData['activeTab']['topology'];
}) {
    return (
        <Panel
            title="Known topology evidence"
            description="Latest native and provider snapshots are shown with source, confidence, changes, and inferred-versus-reviewed labels; only canonical visible Devices are linked."
        >
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3">
                <StateBadge state={topology.state} />
                <p className="text-sm font-medium">{topology.label}</p>
                <span className="text-xs text-muted-foreground">
                    {topology.nodeCount} nodes · {topology.edgeCount} edges
                </span>
            </div>
            {topology.unlinkedCount > 0 ? (
                <GapBanner
                    text={`${topology.unlinkedCount} ${topology.unlinkedCount === 1 ? 'device has' : 'devices have'} ${topology.inventory?.isPartial ? 'no relationship shown in this view' : 'no known relationship'}`}
                />
            ) : null}
            <TopologyMap topology={topology} />
        </Panel>
    );
}

function Panel({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

function GapBanner({ text }: { text: string }) {
    return (
        <div className="mb-3 flex items-start gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-foreground">
            <CircleHelp
                className="mt-0.5 h-4 w-4 shrink-0"
                aria-hidden="true"
            />
            <span>{text}</span>
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <dl>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium break-words">{value}</dd>
        </dl>
    );
}

export function NetworkItWorkspacePanels({
    data,
    view = 'cards',
}: {
    data: NetworkItWorkspaceData;
    view?: NetworkRecordsView;
}) {
    const { activeTab } = data;
    if (activeTab.key === 'overview') return <Overview data={data} />;
    return (
        <div className="space-y-5">
            <EvidenceBoundary data={data.boundary} />
            {activeTab.key === 'map' ? (
                <TopologyPanel topology={activeTab.topology} />
            ) : null}
            {[
                'devices',
                'interfaces',
                'services',
                'traffic-capacity',
                'configuration-firmware',
            ].includes(activeTab.key) ? (
                <NetworkOperationalRecords data={data} view={view} />
            ) : null}
        </div>
    );
}
