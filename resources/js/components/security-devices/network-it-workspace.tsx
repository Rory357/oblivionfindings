import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowDownToLine,
    ArrowRight,
    ArrowUpFromLine,
    Building2,
    Cable,
    CheckCircle2,
    ChevronRight,
    CircleHelp,
    FileDiff,
    Gauge,
    GitBranch,
    Network,
    Router,
    Server,
    ShieldCheck,
    TicketCheck,
    Wifi,
} from 'lucide-react';
import type { ReactNode } from 'react';

type NetworkAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

type NetworkDevice = {
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

type TopologyNode = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    health: string | null;
    site: string | null;
    href: string;
};

type TopologyEdge = {
    id: number;
    parentId: number;
    parentName: string;
    childId: number;
    childName: string;
    type: string | null;
    label: string;
    port: string | null;
};

type NetworkInterface = {
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

type NetworkService = {
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

type TrafficRow = {
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

type ConfigurationRow = {
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
        topology: {
            state: string;
            label: string;
            nodeCount: number;
            edgeCount: number;
            unlinkedCount: number;
            nodes: TopologyNode[];
            edges: TopologyEdge[];
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

function stateLabel(state: string | null | undefined): string {
    if (!state) return 'Unknown';
    const labels: Record<string, string> = {
        update_available: 'Update available',
        not_observed: 'Not observed',
        not_collected: 'Not collected',
        no_evidence: 'No evidence',
        desired_only: 'Desired only',
        in_progress: 'In progress',
    };
    return (
        labels[state] ??
        state
            .replaceAll('_', ' ')
            .replace(/^./, (letter) => letter.toUpperCase())
    );
}

function stateVariant(state: string): StatusVariant {
    if (['healthy', 'active', 'normal', 'aligned', 'known'].includes(state)) {
        return 'success';
    }
    if (
        [
            'warning',
            'degraded',
            'partial',
            'update_available',
            'drifted',
        ].includes(state)
    ) {
        return 'warning';
    }
    if (['failed', 'critical', 'offline'].includes(state)) {
        return 'critical';
    }
    if (['open', 'in_progress'].includes(state)) return 'info';
    return 'neutral';
}

function StateBadge({
    state,
    label,
}: {
    state: string | null | undefined;
    label?: string;
}) {
    const resolved = state ?? 'unknown';
    return (
        <StatusBadge variant={stateVariant(resolved)}>
            {label ?? stateLabel(resolved)}
        </StatusBadge>
    );
}

function MetricCard({
    icon,
    value,
    label,
    note,
}: {
    icon: ReactNode;
    value: number;
    label: string;
    note: string;
}) {
    return (
        <Card className="shadow-xs">
            <CardContent className="p-4">
                <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    {icon}
                </div>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-sm font-medium">{label}</p>
                <p className="mt-1 text-xs text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
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
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <MetricCard
                        icon={<Server className="h-5 w-5" aria-hidden="true" />}
                        value={overview.inventory.devices}
                        label={`${overview.inventory.devices} devices`}
                        note="Visible canonical Network & IT inventory"
                    />
                    <MetricCard
                        icon={
                            <Building2 className="h-5 w-5" aria-hidden="true" />
                        }
                        value={overview.inventory.sites}
                        label={`${overview.inventory.sites} authorised sites`}
                        note="Sites represented by current assignments"
                    />
                    <MetricCard
                        icon={<Router className="h-5 w-5" aria-hidden="true" />}
                        value={overview.inventory.wan_paths}
                        label={`${overview.inventory.wan_paths} WAN paths identified`}
                        note="Classified edge, gateway, firewall, or SD-WAN devices"
                    />
                    <MetricCard
                        icon={
                            <Activity className="h-5 w-5" aria-hidden="true" />
                        }
                        value={overview.inventory.monitored_devices}
                        label={`${overview.inventory.monitored_devices} monitored devices`}
                        note={`${overview.inventory.unmonitored_devices} without an enabled check`}
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

function DevicesPanel({
    devices,
    truncated,
}: {
    devices: NetworkDevice[];
    truncated: boolean;
}) {
    return (
        <Panel
            title="Canonical network and IT devices"
            description="Identifiers and health come from the shared device registry."
        >
            {truncated ? (
                <GapBanner text="Only the first 100 matching devices are shown. Narrow the workspace filters to review the rest." />
            ) : null}
            {devices.length === 0 ? (
                <EmptyLine
                    icon={<Server className="h-4 w-4" />}
                    text="No matching network or IT devices."
                />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {devices.map((device) => (
                        <article
                            key={device.id}
                            aria-label={device.name}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={device.href}
                                        className="font-semibold text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {device.name}
                                    </Link>
                                    <p className="text-xs text-muted-foreground">
                                        {stateLabel(
                                            device.subcategory ??
                                                device.category,
                                        )}
                                        {device.site
                                            ? ` · ${device.site.name}`
                                            : ' · Site not assigned'}
                                    </p>
                                </div>
                                <StateBadge
                                    state={device.health ?? device.status}
                                />
                            </div>
                            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <Detail
                                    label="IP address"
                                    value={
                                        device.identifiers.ipAddress ??
                                        'Not recorded'
                                    }
                                />
                                <Detail
                                    label="MAC address"
                                    value={
                                        device.identifiers.macAddress ??
                                        'Not recorded'
                                    }
                                />
                                <Detail
                                    label="Firmware"
                                    value={
                                        device.firmwareVersion ?? 'Not observed'
                                    }
                                />
                                <Detail
                                    label="Last contact"
                                    value={
                                        device.lastSeenAt
                                            ? formatRelative(device.lastSeenAt)
                                            : 'Never observed'
                                    }
                                />
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Badge variant="outline">
                                    {device.monitoring.enabled} enabled checks
                                </Badge>
                                {device.monitoring.attention > 0 ? (
                                    <StateBadge
                                        state="warning"
                                        label={`${device.monitoring.attention} need attention`}
                                    />
                                ) : null}
                                {device.monitoring.uncertain > 0 ? (
                                    <StateBadge
                                        state="unknown"
                                        label={`${device.monitoring.uncertain} uncertain`}
                                    />
                                ) : null}
                                {device.wanPath ? (
                                    <Badge variant="outline">WAN path</Badge>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </Panel>
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
            description="Only explicit canonical relationships are drawn; no inferred edge is presented as fact."
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
                    text={`${topology.unlinkedCount} ${topology.unlinkedCount === 1 ? 'device has' : 'devices have'} no known relationship`}
                />
            ) : null}
            {topology.nodes.length === 0 ? (
                <EmptyLine
                    icon={<GitBranch className="h-4 w-4" />}
                    text="No visible topology nodes."
                />
            ) : (
                <div className="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]">
                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">Devices</h3>
                        {topology.nodes.map((node) => (
                            <article
                                key={node.id}
                                className="flex items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <div className="rounded-lg bg-primary/10 p-2 text-primary">
                                        <Network
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="min-w-0">
                                        <Link
                                            href={node.href}
                                            className="block truncate text-sm font-medium text-primary hover:underline"
                                        >
                                            {node.name}
                                        </Link>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {node.site ?? 'Site not assigned'}
                                        </p>
                                    </div>
                                </div>
                                <StateBadge state={node.health} />
                            </article>
                        ))}
                    </div>
                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            Known relationships
                        </h3>
                        {topology.edges.length === 0 ? (
                            <EmptyLine
                                icon={<Cable className="h-4 w-4" />}
                                text="No explicit relationships have been recorded."
                            />
                        ) : (
                            topology.edges.map((edge) => (
                                <article
                                    key={edge.id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium">
                                            {edge.parentName}
                                        </span>
                                        <ArrowRight
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
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
                                    </div>
                                </article>
                            ))
                        )}
                    </div>
                </div>
            )}
        </Panel>
    );
}

function InterfacesPanel({
    rows,
    gap,
}: {
    rows: NetworkInterface[];
    gap: number;
}) {
    return (
        <Panel
            title="Observed interfaces"
            description="Allowlisted counters from each interface monitor's latest retained native observation."
        >
            {gap > 0 ? (
                <GapBanner
                    text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no interface evidence`}
                />
            ) : null}
            {rows.length === 0 ? (
                <EmptyLine
                    icon={<Cable className="h-4 w-4" />}
                    text="No interface observations are retained for the current scope."
                />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {rows.map((row) => (
                        <article
                            key={row.monitorId}
                            aria-label={`${row.name} on ${row.deviceName}`}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="font-semibold">
                                        {row.name}
                                    </h3>
                                    <Link
                                        href={row.deviceHref}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        {row.deviceName}
                                    </Link>
                                </div>
                                <StateBadge
                                    state={row.enabled ? row.state : 'disabled'}
                                />
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <Detail
                                    label="Link speed"
                                    value={formatRate(row.speedBps)}
                                />
                                <Detail
                                    label="Interface index"
                                    value={
                                        row.index === null
                                            ? 'Not collected'
                                            : String(row.index)
                                    }
                                />
                                <Detail
                                    label="Inbound"
                                    value={formatUtilisation(
                                        row.inUtilisation,
                                        'inbound',
                                    )}
                                />
                                <Detail
                                    label="Outbound"
                                    value={formatUtilisation(
                                        row.outUtilisation,
                                        'outbound',
                                    )}
                                />
                                <Detail
                                    label="Errors"
                                    value={
                                        row.errors === null
                                            ? 'Not collected'
                                            : String(row.errors)
                                    }
                                />
                                <Detail
                                    label="Discards"
                                    value={
                                        row.discards === null
                                            ? 'Not collected'
                                            : String(row.discards)
                                    }
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <StateBadge
                                    state={row.capacityState}
                                    label={capacityLabel(row.capacityState)}
                                />
                                <Badge variant="outline">
                                    Admin {row.adminStatus ?? 'unknown'}
                                </Badge>
                                <Badge variant="outline">
                                    Operational{' '}
                                    {row.operationalStatus ?? 'unknown'}
                                </Badge>
                                <span
                                    className="text-xs text-muted-foreground"
                                    title={
                                        row.observedAt
                                            ? formatDateTime(row.observedAt)
                                            : undefined
                                    }
                                >
                                    {row.observedAt
                                        ? formatRelative(row.observedAt)
                                        : 'No retained observation'}
                                </span>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function ServicesPanel({ rows, gap }: { rows: NetworkService[]; gap: number }) {
    return (
        <Panel
            title="Native service checks"
            description="Availability and service state with known device dependency context."
        >
            {gap > 0 ? (
                <GapBanner
                    text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no service checks`}
                />
            ) : null}
            {rows.length === 0 ? (
                <EmptyLine
                    icon={<Activity className="h-4 w-4" />}
                    text="No service checks are configured for the current scope."
                />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {rows.map((row) => (
                        <article
                            key={row.id}
                            aria-label={row.name}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h3 className="font-semibold">
                                        {row.name}
                                    </h3>
                                    <Link
                                        href={row.deviceHref}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        {row.deviceName}
                                    </Link>
                                </div>
                                <StateBadge
                                    state={row.enabled ? row.state : 'disabled'}
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Badge variant="outline">{row.kindLabel}</Badge>
                                {row.affectsAvailability ? (
                                    <Badge variant="outline">
                                        Affects availability
                                    </Badge>
                                ) : null}
                                <Badge variant="outline">
                                    {row.dependentCount} known{' '}
                                    {row.dependentCount === 1
                                        ? 'dependant'
                                        : 'dependants'}
                                </Badge>
                            </div>
                            <div className="mt-4 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                <p>
                                    {row.lastObservationAt
                                        ? `Observed ${formatRelative(row.lastObservationAt)}`
                                        : 'No retained observation'}
                                </p>
                                <p>
                                    {row.collector
                                        ? `${row.collector.name} · ${stateLabel(row.collector.status)}`
                                        : 'Central runtime path'}
                                </p>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function TrafficPanel({ rows, gap }: { rows: TrafficRow[]; gap: number }) {
    return (
        <Panel
            title="Traffic and capacity evidence"
            description="Only metrics already retained by the native observation contract are shown."
        >
            {gap > 0 ? (
                <GapBanner
                    text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no retained capacity evidence`}
                />
            ) : null}
            {rows.length === 0 ? (
                <EmptyLine
                    icon={<Gauge className="h-4 w-4" />}
                    text="No retained traffic or capacity metrics are available."
                />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {rows.map((row) => (
                        <article
                            key={row.monitorId}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="font-semibold">
                                        {row.interface}
                                    </h3>
                                    <Link
                                        href={row.deviceHref}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        {row.deviceName}
                                    </Link>
                                </div>
                                <StateBadge
                                    state={row.state}
                                    label={capacityLabel(row.state)}
                                />
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-3">
                                <RateMetric
                                    icon={
                                        <ArrowDownToLine className="h-4 w-4" />
                                    }
                                    label="Inbound"
                                    rate={row.inBps}
                                    utilisation={row.inUtilisation}
                                />
                                <RateMetric
                                    icon={
                                        <ArrowUpFromLine className="h-4 w-4" />
                                    }
                                    label="Outbound"
                                    rate={row.outBps}
                                    utilisation={row.outUtilisation}
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <Badge variant="outline">
                                    {formatRate(row.speedBps)} link
                                </Badge>
                                <Badge variant="outline">
                                    Retained native observation
                                </Badge>
                                <span>
                                    {row.observedAt
                                        ? formatDateTime(row.observedAt)
                                        : 'Observation time unavailable'}
                                </span>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function RateMetric({
    icon,
    label,
    rate,
    utilisation,
}: {
    icon: ReactNode;
    label: string;
    rate: number | null;
    utilisation: number | null;
}) {
    return (
        <div className="rounded-lg bg-muted/50 p-3">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                {icon}
                {label}
            </div>
            <p className="mt-1 font-semibold">{formatRate(rate)}</p>
            <p className="text-xs text-muted-foreground">
                {utilisation === null
                    ? 'Utilisation not collected'
                    : `${formatNumber(utilisation)}% utilisation`}
            </p>
        </div>
    );
}

function ConfigurationPanel({
    rows,
    configurationGap,
    firmwareGap,
}: {
    rows: ConfigurationRow[];
    configurationGap: number;
    firmwareGap: number;
}) {
    return (
        <Panel
            title="Configuration and firmware evidence"
            description="Observed and desired state only; this workspace does not execute changes."
        >
            {configurationGap > 0 || firmwareGap > 0 ? (
                <GapBanner
                    text={`${configurationGap} without configuration evidence · ${firmwareGap} without firmware evidence`}
                />
            ) : null}
            {rows.length === 0 ? (
                <EmptyLine
                    icon={<FileDiff className="h-4 w-4" />}
                    text="No configuration or firmware evidence is visible."
                />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {rows.map((row) => (
                        <article
                            key={row.deviceId}
                            aria-label={row.deviceName}
                            className="rounded-xl border p-4"
                        >
                            <Link
                                href={row.deviceHref}
                                className="font-semibold text-primary hover:underline"
                            >
                                {row.deviceName}
                            </Link>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <section className="rounded-lg bg-muted/40 p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <h3 className="text-sm font-medium">
                                            Configuration
                                        </h3>
                                        <StateBadge
                                            state={row.configuration.state}
                                            label={
                                                row.configuration.state ===
                                                'drifted'
                                                    ? 'Configuration drift'
                                                    : undefined
                                            }
                                        />
                                    </div>
                                    <dl className="mt-3 space-y-2 text-xs">
                                        <Detail
                                            label="Observed fingerprint"
                                            value={shortHash(
                                                row.configuration.observedHash,
                                            )}
                                        />
                                        <Detail
                                            label="Desired fingerprint"
                                            value={shortHash(
                                                row.configuration.desiredHash,
                                            )}
                                        />
                                        <Detail
                                            label="Observed"
                                            value={
                                                row.configuration.observedAt
                                                    ? formatDateTime(
                                                          row.configuration
                                                              .observedAt,
                                                      )
                                                    : 'Not observed'
                                            }
                                        />
                                    </dl>
                                </section>
                                <section className="rounded-lg bg-muted/40 p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <h3 className="text-sm font-medium">
                                            Firmware
                                        </h3>
                                        <StateBadge
                                            state={row.firmware.state}
                                        />
                                    </div>
                                    <dl className="mt-3 space-y-2 text-xs">
                                        <Detail
                                            label="Current"
                                            value={
                                                row.firmware.currentVersion ??
                                                'Not observed'
                                            }
                                        />
                                        <Detail
                                            label="Desired"
                                            value={
                                                row.firmware.desiredVersion ??
                                                'Not configured'
                                            }
                                        />
                                        <Detail
                                            label="Observed"
                                            value={
                                                row.firmware.observedAt
                                                    ? formatDateTime(
                                                          row.firmware
                                                              .observedAt,
                                                      )
                                                    : 'Not observed'
                                            }
                                        />
                                    </dl>
                                </section>
                            </div>
                        </article>
                    ))}
                </div>
            )}
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
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium break-words">{value}</dd>
        </div>
    );
}

function formatNumber(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function formatRate(value: number | null): string {
    if (value === null) return 'Not collected';
    if (value >= 1_000_000_000)
        return `${formatNumber(value / 1_000_000_000)} Gbps`;
    if (value >= 1_000_000) return `${formatNumber(value / 1_000_000)} Mbps`;
    if (value >= 1_000) return `${formatNumber(value / 1_000)} Kbps`;
    return `${value} bps`;
}

function formatUtilisation(value: number | null, direction: string): string {
    return value === null
        ? 'Not collected'
        : `${formatNumber(value)}% ${direction}`;
}

function capacityLabel(state: string): string {
    if (state === 'warning') return 'Capacity warning';
    if (state === 'critical') return 'Capacity critical';
    if (state === 'normal') return 'Capacity normal';
    return stateLabel(state);
}

function shortHash(value: string | null): string {
    if (!value) return 'Not observed';
    return value.length > 18
        ? `${value.slice(0, 8)}…${value.slice(-6)}`
        : value;
}

export function NetworkItWorkspacePanels({
    data,
}: {
    data: NetworkItWorkspaceData;
}) {
    const { activeTab } = data;

    if (activeTab.key === 'overview') return <Overview data={data} />;

    return (
        <div className="space-y-5">
            <EvidenceBoundary data={data.boundary} />
            {activeTab.key === 'map' ? (
                <TopologyPanel topology={activeTab.topology} />
            ) : null}
            {activeTab.key === 'devices' ? (
                <DevicesPanel
                    devices={activeTab.devices}
                    truncated={activeTab.inventoryTruncated}
                />
            ) : null}
            {activeTab.key === 'interfaces' ? (
                <InterfacesPanel
                    rows={activeTab.interfaces}
                    gap={activeTab.gaps.devicesWithoutInterfaceEvidence}
                />
            ) : null}
            {activeTab.key === 'services' ? (
                <ServicesPanel
                    rows={activeTab.services}
                    gap={activeTab.gaps.devicesWithoutServiceChecks}
                />
            ) : null}
            {activeTab.key === 'traffic-capacity' ? (
                <TrafficPanel
                    rows={activeTab.traffic}
                    gap={activeTab.gaps.devicesWithoutCapacityEvidence}
                />
            ) : null}
            {activeTab.key === 'configuration-firmware' ? (
                <ConfigurationPanel
                    rows={activeTab.configuration}
                    configurationGap={
                        activeTab.gaps.devicesWithoutConfigurationEvidence
                    }
                    firmwareGap={activeTab.gaps.devicesWithoutFirmwareEvidence}
                />
            ) : null}
        </div>
    );
}
