import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    CoverageIndicator,
    OperationalStateBadge,
} from '@/components/security-devices/estate-operations';
import { securityDevicesDomainHref } from '@/components/security-devices/security-devices-navigation';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatRelative } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BatteryLow,
    Bell,
    Building2,
    Cctv,
    Cpu,
    GitBranch,
    HeartPulse,
    History,
    MonitorOff,
    Plus,
    RadioTower,
    Server,
    Shield,
    Smartphone,
    Wrench,
    Zap,
    type LucideIcon,
} from 'lucide-react';

import { StatCard } from './devices/shared';

type SiteImpact = {
    id: number;
    name: string;
    city: string | null;
    health: string;
    devices: number;
    monitored_devices: number;
    unmonitored_devices: number;
    coverage_percent: number | null;
    active_findings: number;
    open_it_work: number | null;
    overdue_maintenance: number;
    collector: { state: string; label: string };
    last_change_at: string | null;
    href: string | null;
};

type Props = {
    stats: {
        totalDevices: number;
        active: number;
        offline: number;
        degraded: number;
        lowBattery: number;
        overdueMaintenance: number;
        serviceDueOverdue: number;
        serviceDueIn30d: number;
        criticalEvents24h: number;
        warningEvents24h: number;
    };
    domainSummary: Array<{ domain: string; label: string; count: number }>;
    healthSummary: Array<{ status: string; label: string; count: number }>;
    attentionDevices: Array<{
        id: number;
        name: string;
        device_uid: string;
        domain: string;
        category: string;
        status: string;
        health_status: string;
        battery_level: number | null;
        last_seen_at: string | null;
    }>;
    recentEvents: Array<{
        id: number;
        device_id: number;
        device_name: string | null;
        event_type: string;
        severity: string;
        occurred_at: string;
    }>;
    overdueMaintenance: Array<{
        id: number;
        device_id: number;
        device_name: string | null;
        type: string;
        description: string;
        scheduled_for: string | null;
    }>;
    groupCount: number;
    operations: {
        coverage: {
            total_devices: number;
            monitored_devices: number;
            unmonitored_devices: number;
            percent: number | null;
        };
        summary: {
            affected_sites: number;
            active_findings: number;
            open_it_work: number | null;
            failed_monitors: number;
            overdue_maintenance: number;
        };
        site_impact: SiteImpact[];
        action_queue: Array<{
            key: string;
            label: string;
            count: number | null;
            href: string | null;
            restriction_reason: string | null;
        }>;
        recent_changes: Array<{
            key: string;
            kind: string;
            device_name: string | null;
            summary: string;
            at: string | null;
            href: string | null;
        }>;
    };
    can: {
        create: boolean;
        export: boolean;
        view_devices: boolean;
        view_events: boolean;
        view_maintenance: boolean;
    };
};

const domainIcons: Record<string, LucideIcon> = {
    security: Shield,
    tracking: Smartphone,
    iot_healthcare: HeartPulse,
    it_infrastructure: Server,
    facilities: Building2,
};

function actionState(key: string, count: number | null): string {
    if (count === null) return 'unknown';
    if (count === 0) return 'healthy';
    return key === 'failed_monitors' ? 'critical' : 'warning';
}

function humanise(value: string): string {
    return value.replace(/_/g, ' ');
}

export default function Dashboard({
    stats,
    domainSummary,
    healthSummary,
    attentionDevices,
    recentEvents,
    overdueMaintenance,
    groupCount,
    operations,
    can,
}: Props) {
    const totalAttention = stats.offline + stats.degraded + stats.lowBattery;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
            ]}
        >
            <Head title="Estate - Security & Devices" />

            <PageShell>
                <PageHero
                    icon={Cctv}
                    title="Security & Devices estate"
                    description="See what is affected, where it is happening, and the next action across every accessible site."
                    stats={[
                        {
                            label: 'Affected sites',
                            value: operations.summary.affected_sites,
                        },
                        {
                            label: 'Active findings',
                            value: operations.summary.active_findings,
                        },
                        {
                            label: 'Monitoring coverage',
                            value:
                                operations.coverage.percent === null
                                    ? 'Not measured'
                                    : `${operations.coverage.percent}%`,
                        },
                        {
                            label: 'Open IT work',
                            value:
                                operations.summary.open_it_work ?? 'Restricted',
                        },
                    ]}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.view_devices ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/security-devices/devices">
                                        <Cpu className="mr-2 h-4 w-4" /> All
                                        devices
                                    </Link>
                                </Button>
                            ) : (
                                <p className="self-center text-xs text-muted-foreground">
                                    Device inventory access is required to open
                                    device records.
                                </p>
                            )}
                            {can.create ? (
                                <Button size="sm" asChild>
                                    <Link href="/security-devices/devices/create">
                                        <Plus className="mr-2 h-4 w-4" />{' '}
                                        Register device
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <section
                    aria-labelledby="attention-heading"
                    className="space-y-3"
                >
                    <div>
                        <h2
                            id="attention-heading"
                            className="text-lg font-semibold"
                        >
                            What needs attention
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Current operational queues from the latest available
                            evidence. Select a queue to investigate or assign
                            work.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {operations.action_queue.map((item) => {
                            const content = (
                                <>
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="text-sm font-medium">
                                            {item.label}
                                        </p>
                                        <OperationalStateBadge
                                            state={actionState(
                                                item.key,
                                                item.count,
                                            )}
                                        />
                                    </div>
                                    <p className="mt-4 text-3xl font-semibold">
                                        {item.count ?? 'Restricted'}
                                    </p>
                                    {item.href ? (
                                        <p className="mt-2 flex items-center gap-1 text-xs font-medium text-primary">
                                            Open queue
                                            <ArrowRight className="h-3.5 w-3.5" />
                                        </p>
                                    ) : (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {item.restriction_reason ??
                                                'Additional permission required'}
                                        </p>
                                    )}
                                </>
                            );

                            return item.href ? (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    className="min-h-32 rounded-xl border bg-card p-4 text-card-foreground shadow-sm transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {content}
                                </Link>
                            ) : (
                                <Card key={item.key} className="min-h-32 p-4">
                                    {content}
                                </Card>
                            );
                        })}
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.6fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <RadioTower className="h-5 w-5" /> Monitoring
                                coverage
                            </CardTitle>
                            <CardDescription>
                                Native Oblivion monitoring across operational
                                devices. Empty estates are never reported as
                                healthy.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <CoverageIndicator
                                percent={operations.coverage.percent}
                                monitored={
                                    operations.coverage.monitored_devices
                                }
                                total={operations.coverage.total_devices}
                            />
                            <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                <StatCard
                                    label="Operational devices"
                                    value={operations.coverage.total_devices}
                                    icon={Cpu}
                                />
                                <StatCard
                                    label="Monitored"
                                    value={
                                        operations.coverage.monitored_devices
                                    }
                                    icon={Activity}
                                />
                                <StatCard
                                    label="Not monitored"
                                    value={
                                        operations.coverage.unmonitored_devices
                                    }
                                    icon={MonitorOff}
                                    variant={
                                        operations.coverage
                                            .unmonitored_devices > 0
                                            ? 'warning'
                                            : 'default'
                                    }
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <History className="h-5 w-5" /> Recent changes
                            </CardTitle>
                            <CardDescription>
                                Latest device, monitoring, and operational
                                changes
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {operations.recent_changes.length > 0 ? (
                                <div className="space-y-2">
                                    {operations.recent_changes
                                        .slice(0, 6)
                                        .map((change) => {
                                            const row = (
                                                <div className="flex min-h-11 items-start justify-between gap-3 rounded-lg border p-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {change.device_name ??
                                                                humanise(
                                                                    change.kind,
                                                                )}
                                                        </p>
                                                        <p className="line-clamp-2 text-xs text-muted-foreground">
                                                            {change.summary}
                                                        </p>
                                                    </div>
                                                    <span className="shrink-0 text-xs text-muted-foreground">
                                                        {formatRelative(
                                                            change.at,
                                                        )}
                                                    </span>
                                                </div>
                                            );

                                            return change.href ? (
                                                <Link
                                                    key={change.key}
                                                    href={change.href}
                                                    className="block rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    {row}
                                                </Link>
                                            ) : (
                                                <div key={change.key}>
                                                    {row}
                                                </div>
                                            );
                                        })}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No recent operational changes are available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>Affected sites</CardTitle>
                            <CardDescription>
                                Sites with a failed monitor, active finding,
                                unmonitored device, stale data, or overdue work
                            </CardDescription>
                        </div>
                        {can.view_devices ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/security-devices/sites">
                                    View all sites
                                </Link>
                            </Button>
                        ) : (
                            <p className="max-w-56 text-right text-xs text-muted-foreground">
                                Device inventory access is required to open Site
                                technology.
                            </p>
                        )}
                    </CardHeader>
                    <CardContent>
                        {operations.site_impact.length > 0 ? (
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {operations.site_impact.map((site) => {
                                    const content = (
                                        <>
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold">
                                                        {site.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {site.city ||
                                                            'Location not recorded'}
                                                    </p>
                                                </div>
                                                <OperationalStateBadge
                                                    state={site.health}
                                                />
                                            </div>
                                            <CoverageIndicator
                                                className="mt-4"
                                                percent={site.coverage_percent}
                                                monitored={
                                                    site.monitored_devices
                                                }
                                                total={site.devices}
                                            />
                                            <div className="mt-4 grid grid-cols-3 gap-2 text-xs">
                                                <div>
                                                    <p className="font-semibold">
                                                        {site.active_findings}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        Findings
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="font-semibold">
                                                        {site.open_it_work ??
                                                            'Restricted'}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        IT work
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="font-semibold">
                                                        {
                                                            site.overdue_maintenance
                                                        }
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        Overdue
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="mt-4 flex items-center justify-between gap-3 border-t pt-3 text-xs text-muted-foreground">
                                                <span>
                                                    {site.collector.label}
                                                </span>
                                                <span>
                                                    {formatRelative(
                                                        site.last_change_at,
                                                    )}
                                                </span>
                                            </div>
                                            {!site.href ? (
                                                <p className="mt-3 border-t pt-3 text-xs text-muted-foreground">
                                                    Device inventory access is
                                                    required to open Site
                                                    technology.
                                                </p>
                                            ) : null}
                                        </>
                                    );

                                    return site.href ? (
                                        <Link
                                            key={site.id}
                                            href={site.href}
                                            className="rounded-lg border p-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {content}
                                        </Link>
                                    ) : (
                                        <Card key={site.id} className="p-4">
                                            {content}
                                        </Card>
                                    );
                                })}
                            </div>
                        ) : (
                            <EmptyState
                                icon={Building2}
                                title="No affected sites"
                                description={
                                    stats.totalDevices === 0
                                        ? 'No devices are registered yet. Monitoring health is not measured.'
                                        : 'No accessible site currently requires attention.'
                                }
                                variant="compact"
                            />
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Total devices"
                        value={stats.totalDevices}
                        icon={Cpu}
                    />
                    <StatCard
                        label="Active"
                        value={stats.active}
                        icon={Activity}
                    />
                    <StatCard
                        label="Offline / degraded"
                        value={stats.offline + stats.degraded}
                        icon={MonitorOff}
                        variant={totalAttention > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Overdue maintenance"
                        value={stats.overdueMaintenance}
                        icon={Wrench}
                        variant={
                            stats.overdueMaintenance > 0 ? 'warning' : 'default'
                        }
                    />
                    <StatCard
                        label="Service overdue"
                        value={stats.serviceDueOverdue}
                        icon={Wrench}
                        variant={
                            stats.serviceDueOverdue > 0 ? 'warning' : 'default'
                        }
                    />
                    <StatCard
                        label="Service due in 30d"
                        value={stats.serviceDueIn30d}
                        icon={Wrench}
                    />
                    <StatCard
                        label="Low battery"
                        value={stats.lowBattery}
                        icon={BatteryLow}
                        variant={stats.lowBattery > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Device groups"
                        value={groupCount}
                        icon={GitBranch}
                    />
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Estate by domain</CardTitle>
                                <CardDescription>
                                    Security, tracking, healthcare, network IT,
                                    and facilities remain separate workspaces.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {!can.view_devices ? (
                                    <p className="text-sm text-muted-foreground">
                                        Device inventory access is required to
                                        open estate workspaces.
                                    </p>
                                ) : stats.totalDevices > 0 ? (
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {domainSummary.map((domain) => {
                                            const Icon =
                                                domainIcons[domain.domain] ??
                                                Cpu;
                                            return (
                                                <Link
                                                    key={domain.domain}
                                                    href={securityDevicesDomainHref(
                                                        domain.domain,
                                                    )}
                                                    className="flex min-h-14 items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    <div className="rounded-md bg-muted p-2">
                                                        <Icon className="h-4 w-4 text-primary" />
                                                    </div>
                                                    <div>
                                                        <p className="text-xl font-semibold">
                                                            {domain.count}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {domain.label}
                                                        </p>
                                                    </div>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        icon={Shield}
                                        title="No devices registered"
                                        description="Register a device or run discovery to begin monitoring the estate."
                                        variant="compact"
                                        action={
                                            can.create ? (
                                                <Button size="sm" asChild>
                                                    <Link href="/security-devices/devices/create">
                                                        Register device
                                                    </Link>
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex-row items-start justify-between gap-4">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <Bell className="h-4 w-4" /> Recent
                                        findings
                                    </CardTitle>
                                    <CardDescription>
                                        Critical and warning device events in
                                        the last 24 hours
                                    </CardDescription>
                                </div>
                                {can.view_events ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href="/security-devices/alerts-events">
                                            View all
                                        </Link>
                                    </Button>
                                ) : (
                                    <p className="max-w-56 text-right text-xs text-muted-foreground">
                                        Event access is required to open
                                        findings.
                                    </p>
                                )}
                            </CardHeader>
                            <CardContent>
                                {!can.view_events ? (
                                    <p className="text-sm text-muted-foreground">
                                        Event access is required to review
                                        recent findings.
                                    </p>
                                ) : recentEvents.length > 0 ? (
                                    <div className="space-y-2">
                                        {recentEvents.map((event) => (
                                            <div
                                                key={event.id}
                                                className="flex min-h-11 flex-col justify-between gap-2 rounded-md border p-3 text-sm sm:flex-row sm:items-center"
                                            >
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <OperationalStateBadge
                                                        state={event.severity}
                                                    />
                                                    <span className="truncate font-medium">
                                                        {humanise(
                                                            event.event_type,
                                                        )}
                                                    </span>
                                                    {event.device_name &&
                                                    can.view_devices ? (
                                                        <Link
                                                            href={`/security-devices/devices/${event.device_id}`}
                                                            className="truncate text-xs text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                        >
                                                            {event.device_name}
                                                        </Link>
                                                    ) : event.device_name ? (
                                                        <span className="truncate text-xs text-muted-foreground">
                                                            {event.device_name}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {formatRelative(
                                                        event.occurred_at,
                                                    )}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No critical or warning findings in the
                                        last 24 hours.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex-row items-start justify-between gap-4">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <Wrench className="h-4 w-4" /> Overdue
                                        maintenance
                                    </CardTitle>
                                    <CardDescription>
                                        {stats.overdueMaintenance} overdue
                                        record
                                        {stats.overdueMaintenance === 1
                                            ? ''
                                            : 's'}
                                    </CardDescription>
                                </div>
                                {can.view_maintenance ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href="/security-devices/maintenance-health">
                                            View all
                                        </Link>
                                    </Button>
                                ) : (
                                    <p className="max-w-56 text-right text-xs text-muted-foreground">
                                        Maintenance access is required to open
                                        maintenance work.
                                    </p>
                                )}
                            </CardHeader>
                            <CardContent>
                                {!can.view_maintenance ? (
                                    <p className="text-sm text-muted-foreground">
                                        Maintenance access is required to review
                                        overdue work.
                                    </p>
                                ) : overdueMaintenance.length > 0 ? (
                                    <div className="space-y-2">
                                        {overdueMaintenance.map((record) => (
                                            <div
                                                key={record.id}
                                                className="flex min-h-11 items-start justify-between gap-3 rounded-md border p-3 text-sm"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {humanise(
                                                                record.type,
                                                            )}
                                                        </span>
                                                        <OperationalStateBadge state="warning" />
                                                    </div>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {record.description}
                                                    </p>
                                                    {record.device_name &&
                                                    can.view_devices ? (
                                                        <Link
                                                            href={`/security-devices/devices/${record.device_id}`}
                                                            className="mt-1 inline-block text-xs text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                        >
                                                            {record.device_name}
                                                        </Link>
                                                    ) : record.device_name ? (
                                                        <span className="mt-1 inline-block text-xs text-muted-foreground">
                                                            {record.device_name}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    Due{' '}
                                                    {formatDate(
                                                        record.scheduled_for,
                                                    )}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No overdue maintenance.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Health distribution
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {healthSummary.map((health) => (
                                    <div
                                        key={health.status}
                                        className="flex min-h-11 items-center justify-between gap-3"
                                    >
                                        <OperationalStateBadge
                                            state={health.status}
                                        />
                                        <span className="font-semibold">
                                            {health.count}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                                    Devices needing attention
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {!can.view_devices ? (
                                    <p className="text-sm text-muted-foreground">
                                        Device inventory access is required to
                                        open devices needing attention.
                                    </p>
                                ) : attentionDevices.length > 0 ? (
                                    <div className="max-h-[32rem] space-y-2 overflow-y-auto">
                                        {attentionDevices.map((device) => (
                                            <Link
                                                key={device.id}
                                                href={`/security-devices/devices/${device.id}`}
                                                className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3 text-sm transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">
                                                        {device.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {humanise(
                                                            device.category,
                                                        )}{' '}
                                                        · Seen{' '}
                                                        {formatRelative(
                                                            device.last_seen_at,
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="flex shrink-0 flex-col items-end gap-1">
                                                    <OperationalStateBadge
                                                        state={
                                                            device.health_status
                                                        }
                                                    />
                                                    <OperationalStateBadge
                                                        state={device.status}
                                                    />
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {stats.totalDevices === 0
                                            ? 'No devices are registered, so health is not measured.'
                                            : 'No device currently requires attention.'}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    24-hour signals
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <StatCard
                                    label="Critical findings"
                                    value={stats.criticalEvents24h}
                                    icon={AlertTriangle}
                                    variant={
                                        stats.criticalEvents24h > 0
                                            ? 'warning'
                                            : 'default'
                                    }
                                />
                                <StatCard
                                    label="Warning findings"
                                    value={stats.warningEvents24h}
                                    icon={Zap}
                                    variant={
                                        stats.warningEvents24h > 0
                                            ? 'warning'
                                            : 'default'
                                    }
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
