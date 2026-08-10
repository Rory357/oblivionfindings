import {
    CoverageIndicator,
    OperationalStateBadge,
} from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BellRing,
    Cable,
    CircleHelp,
    Cpu,
    ExternalLink,
    Network,
    RadioTower,
    ShieldAlert,
    TicketCheck,
    Wrench,
} from 'lucide-react';

type DeviceItem = {
    id: number;
    name: string;
    domain: string;
    category: string;
    status: string;
    health_status: string;
    provider: string | null;
    last_seen_at: string | null;
    monitor_count: number | null;
    monitoring_state: string | null;
    href: string;
};

type WorkItem = {
    id: number;
    title: string;
    reference: string | null;
    status: string;
    href: string;
};

export type SiteTechnologyProjection = {
    summary: {
        health: string;
        devices: number;
        attention_devices: number;
        offline_devices: number;
        monitored_devices: number | null;
        unmonitored_devices: number | null;
        coverage_percent: number | null;
        failed_monitors: number | null;
        active_findings: number | null;
        active_control_room_alerts: number | null;
        open_it_work: number | null;
        overdue_maintenance: number | null;
        collector: {
            state: string;
            label: string;
            count: number;
            last_seen_at: string | null;
        } | null;
        last_change_at: string | null;
    };
    wan: {
        known: boolean;
        label: string;
        devices: Array<{
            id: number;
            name: string;
            status: string;
            health_status: string;
            href: string;
        }>;
        configuration: {
            state: string;
            label: string;
            observed_devices: number;
            changed_devices: number;
            total_devices: number;
            observed_at: string | null;
            href: string;
        };
    };
    topology: {
        device_count: number;
        edge_count: number;
        is_complete: boolean;
    };
    monitoring: {
        total_devices: number;
        monitored_devices: number | null;
        unmonitored_devices: number | null;
        failed_monitors: number | null;
        uncertain_monitors: number | null;
        issues: Array<{
            id: number;
            device_id: number;
            device_name: string;
            name: string;
            kind: string;
            state: string;
            last_observation_at: string | null;
        }>;
    };
    devices: DeviceItem[];
    alerts: Array<{
        id: number;
        reference: string | null;
        title: string;
        severity: string;
        status: string;
        triggered_at: string | null;
        href: string;
    }>;
    it_work: WorkItem[];
    maintenance: Array<{
        id: number;
        device_id: number;
        device_name: string | null;
        type: string;
        status: string;
        description: string;
        scheduled_for: string | null;
        is_overdue: boolean;
    }>;
    collectors: Array<{
        id: number;
        name: string;
        state: string;
        status: string;
        last_seen_at: string | null;
    }>;
    changes: Array<{
        key: string;
        device_name: string | null;
        summary: string;
        at: string;
        href: string;
    }>;
    links: {
        full: string;
        devices: string;
        monitoring: string | null;
        maintenance: string | null;
    };
    can: {
        view_control_room: boolean;
        view_it_work: boolean;
        view_monitoring: boolean;
        view_maintenance: boolean;
        view_room_placement: boolean;
    };
};

function Stat({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string | number;
    icon: typeof Cpu;
}) {
    return (
        <Card className="p-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                <Icon className="h-4 w-4 text-muted-foreground" aria-hidden />
            </div>
            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </Card>
    );
}

function DeviceRow({ device }: { device: DeviceItem }) {
    return (
        <Link
            href={device.href}
            className="frontline-focus group flex items-center justify-between gap-3 rounded-xl border p-3 hover:bg-muted/40"
        >
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">{device.name}</p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {[
                        device.category,
                        device.provider,
                        device.monitor_count !== null
                            ? `${device.monitor_count} monitors`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {device.monitoring_state ? (
                    <OperationalStateBadge state={device.monitoring_state} />
                ) : null}
                <ArrowRight
                    className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                    aria-hidden
                />
            </div>
        </Link>
    );
}

export function SiteTechnologyProjectionPanel({
    siteId,
    data,
    canViewHardwarePlacement,
}: {
    siteId: number;
    data: SiteTechnologyProjection;
    canViewHardwarePlacement: boolean;
}) {
    const { summary } = data;

    return (
        <div
            className="space-y-4"
            data-test="site-technology-projection"
            data-testid="site-technology-projection"
        >
            <Card className="overflow-hidden border-primary/20">
                <CardContent className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-semibold">
                                Technology &amp; monitoring
                            </h2>
                            <OperationalStateBadge state={summary.health} />
                        </div>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Read-only Site context from the canonical Security
                            &amp; Devices register. Device management,
                            monitoring and IT work remain in their owning
                            modules.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canViewHardwarePlacement &&
                        data.can.view_room_placement ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={`/sites/${siteId}/hardware`}>
                                    <Cable
                                        className="mr-2 h-4 w-4"
                                        aria-hidden
                                    />
                                    Room placement
                                </Link>
                            </Button>
                        ) : null}
                        <Button asChild size="sm">
                            <Link href={data.links.full}>
                                Open full technology view
                                <ExternalLink
                                    className="ml-2 h-4 w-4"
                                    aria-hidden
                                />
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Devices" value={summary.devices} icon={Cpu} />
                <Stat
                    label="Need attention"
                    value={summary.attention_devices}
                    icon={Activity}
                />
                <Stat
                    label="Active findings"
                    value={summary.active_findings ?? 'Restricted'}
                    icon={BellRing}
                />
                <Stat
                    label="Open IT work"
                    value={summary.open_it_work ?? 'Restricted'}
                    icon={TicketCheck}
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="text-base">
                                Device snapshot
                            </CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Attention and coverage gaps appear first.
                            </p>
                        </div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={data.links.devices}>
                                View all devices
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {data.devices.length > 0 ? (
                            data.devices.map((device) => (
                                <DeviceRow key={device.id} device={device} />
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={Cpu}
                                title="No Site devices"
                                description="No canonical Device is currently assigned to this Site or one of its rooms."
                            />
                        )}
                    </CardContent>
                </Card>

                <div className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <RadioTower className="h-4 w-4" aria-hidden />
                                Monitoring coverage
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {data.can.view_monitoring &&
                            data.links.monitoring ? (
                                <>
                                    <CoverageIndicator
                                        percent={summary.coverage_percent}
                                        monitored={
                                            summary.monitored_devices ?? 0
                                        }
                                        total={summary.devices}
                                    />
                                    <div className="grid grid-cols-2 gap-2 text-sm">
                                        <div className="rounded-lg bg-muted/40 p-3">
                                            <p className="text-xs text-muted-foreground">
                                                Failed monitors
                                            </p>
                                            <p className="mt-1 font-semibold">
                                                {summary.failed_monitors}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-muted/40 p-3">
                                            <p className="text-xs text-muted-foreground">
                                                Not monitored
                                            </p>
                                            <p className="mt-1 font-semibold">
                                                {summary.unmonitored_devices}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                    >
                                        <Link href={data.links.monitoring}>
                                            Open monitoring
                                        </Link>
                                    </Button>
                                </>
                            ) : (
                                <div className="flex items-start gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                                    <ShieldAlert
                                        className="mt-0.5 h-4 w-4 shrink-0"
                                        aria-hidden
                                    />
                                    <span>
                                        Monitoring access is required to view
                                        coverage, findings, and monitor status.
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Network className="h-4 w-4" aria-hidden />
                                Network &amp; collection path
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">WAN / SD-WAN</p>
                                    <p className="text-xs text-muted-foreground">
                                        {data.wan.label}
                                    </p>
                                </div>
                                <Badge variant="outline">
                                    {data.wan.known
                                        ? 'Identified'
                                        : 'Not classified'}
                                </Badge>
                            </div>
                            <div className="flex items-start justify-between gap-3 border-t pt-3">
                                <div>
                                    <p className="font-medium">
                                        Configuration evidence
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {data.wan.configuration.label}
                                        {data.wan.configuration.observed_at
                                            ? ` · ${formatDateTime(data.wan.configuration.observed_at)}`
                                            : ''}
                                    </p>
                                </div>
                                <OperationalStateBadge
                                    state={data.wan.configuration.state}
                                />
                            </div>
                            <div className="flex items-start justify-between gap-3 border-t pt-3">
                                {data.can.view_monitoring &&
                                summary.collector ? (
                                    <>
                                        <div>
                                            <p className="font-medium">
                                                Collector
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {summary.collector.label}
                                                {summary.collector.last_seen_at
                                                    ? ` · ${formatDateTime(summary.collector.last_seen_at)}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <OperationalStateBadge
                                            state={summary.collector.state}
                                        />
                                    </>
                                ) : (
                                    <div className="flex items-start gap-2 text-muted-foreground">
                                        <ShieldAlert
                                            className="mt-0.5 h-4 w-4 shrink-0"
                                            aria-hidden
                                        />
                                        <div>
                                            <p className="font-medium">
                                                Collector restricted
                                            </p>
                                            <p className="text-xs">
                                                Monitoring access is required.
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                            <div className="flex items-start justify-between gap-3 border-t pt-3">
                                <div>
                                    <p className="font-medium">Topology</p>
                                    <p className="text-xs text-muted-foreground">
                                        {data.topology.edge_count} observed
                                        connection
                                        {data.topology.edge_count === 1
                                            ? ''
                                            : 's'}{' '}
                                        across {data.topology.device_count}{' '}
                                        devices
                                    </p>
                                </div>
                                <OperationalStateBadge
                                    state={
                                        data.topology.is_complete
                                            ? 'healthy'
                                            : 'unknown'
                                    }
                                />
                            </div>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="frontline-focus min-h-11 w-full"
                            >
                                <Link href={data.wan.configuration.href}>
                                    Open configuration evidence
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <BellRing className="h-4 w-4" aria-hidden />
                            Control Room
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {!data.can.view_control_room ? (
                            <p className="text-sm text-muted-foreground">
                                Restricted by Control Room permissions.
                            </p>
                        ) : data.alerts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No active technology alerts for this Site.
                            </p>
                        ) : (
                            data.alerts.map((alert) => (
                                <Link
                                    key={alert.id}
                                    href={alert.href}
                                    className="frontline-focus block rounded-lg border p-3 hover:bg-muted/40"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {alert.title}
                                        </p>
                                        <OperationalStateBadge
                                            state={alert.severity}
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {alert.reference ?? 'Active alert'}
                                    </p>
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <TicketCheck className="h-4 w-4" aria-hidden />
                            IT work
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {!data.can.view_it_work ? (
                            <p className="text-sm text-muted-foreground">
                                Restricted by IT &amp; Support permissions.
                            </p>
                        ) : data.it_work.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No open technology work for this Site.
                            </p>
                        ) : (
                            data.it_work.map((work) => (
                                <Link
                                    key={work.id}
                                    href={work.href}
                                    className="frontline-focus block rounded-lg border p-3 hover:bg-muted/40"
                                >
                                    <p className="truncate text-sm font-medium">
                                        {work.title}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {[work.reference, work.status]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Wrench className="h-4 w-4" aria-hidden />
                            Maintenance
                        </CardTitle>
                        {data.can.view_maintenance && data.links.maintenance ? (
                            <Button asChild variant="ghost" size="sm">
                                <Link href={data.links.maintenance}>
                                    View all
                                </Link>
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {!data.can.view_maintenance ? (
                            <div className="flex items-start gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                                <ShieldAlert
                                    className="mt-0.5 h-4 w-4 shrink-0"
                                    aria-hidden
                                />
                                <span>
                                    Maintenance access is required to view this
                                    queue.
                                </span>
                            </div>
                        ) : data.maintenance.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No open Device maintenance for this Site.
                            </p>
                        ) : (
                            data.maintenance.map((item) => (
                                <div
                                    key={item.id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {item.description}
                                        </p>
                                        {item.is_overdue ? (
                                            <OperationalStateBadge state="warning" />
                                        ) : null}
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {[
                                            item.device_name,
                                            formatDateTime(item.scheduled_for),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            {data.monitoring.issues.length > 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CircleHelp className="h-4 w-4" aria-hidden />
                            Monitor next actions
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 md:grid-cols-2">
                        {data.monitoring.issues.map((monitor) => (
                            <div
                                key={monitor.id}
                                className="flex items-center justify-between gap-3 rounded-xl border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {monitor.device_name}: {monitor.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {monitor.kind} ·{' '}
                                        {formatDateTime(
                                            monitor.last_observation_at,
                                        )}
                                    </p>
                                </div>
                                <OperationalStateBadge state={monitor.state} />
                            </div>
                        ))}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
