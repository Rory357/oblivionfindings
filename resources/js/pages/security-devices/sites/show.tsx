import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    CoverageIndicator,
    OperationalStateBadge,
} from '@/components/security-devices/estate-operations';
import {
    ControlRoomAlertAccessRequired,
    SiteProfileDestination,
    type ControlRoomAlertAccess,
} from '@/components/security-devices/permission-destinations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    BellRing,
    Building2,
    Cable,
    Clock3,
    Contact,
    Cpu,
    FolderTree,
    MapPin,
    Network,
    RadioTower,
    TicketCheck,
    Wrench,
} from 'lucide-react';

import { DeviceCard, StatCard, type DeviceListItem } from '../devices/shared';

interface SiteTechnology {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    address: string;
    is_active: boolean;
}

interface SiteSummary {
    health: string;
    devices: number;
    attention_devices: number;
    offline_devices: number;
    monitored_devices: number;
    unmonitored_devices: number;
    coverage_percent: number | null;
    failed_monitors: number;
    active_findings: number;
    active_control_room_alerts: number | null;
    open_it_work: number | null;
    overdue_maintenance: number;
    collector: {
        state: string;
        label: string;
        count: number;
        last_seen_at: string | null;
    };
    last_change_at: string | null;
}

type DeviceGroup = {
    id: number;
    name: string;
    type: string;
    device_count: number;
    href: string;
};

type Monitor = {
    id: number;
    device_id: number;
    device_name: string;
    name: string;
    kind: string;
    state: string;
    last_observation_at: string | null;
};

type Alert = {
    id: number;
    reference: string | null;
    title: string | null;
    severity: string | null;
    status: string | null;
    triggered_at: string | null;
    href: string | null;
    access: ControlRoomAlertAccess;
};

type ItWork = {
    id: number;
    reference: string | null;
    title: string;
    work_type: string;
    status: string;
    priority: string;
    next_action: string | null;
    updated_at: string | null;
    href: string;
};

type Maintenance = {
    id: number;
    device_id: number;
    device_name: string | null;
    type: string;
    status: string;
    description: string;
    scheduled_for: string | null;
    is_overdue: boolean;
};

type Collector = {
    id: number;
    uuid: string;
    name: string;
    status: string;
    state: string;
    last_seen_at: string | null;
};

type ContactItem = {
    id: number;
    type: string;
    name: string;
    role: string | null;
    phone: string | null;
    email: string | null;
    is_primary: boolean;
};

type Props = {
    site: SiteTechnology;
    devices: DeviceListItem[];
    summary: SiteSummary;
    wan: {
        known: boolean;
        label: string;
        devices: DeviceListItem[];
    };
    topology: {
        device_count: number;
        edge_count: number;
        is_complete: boolean;
        edges: Array<{
            id: number;
            parent_name: string | null;
            child_name: string | null;
            type: string;
            port: string | null;
        }>;
    };
    deviceGroups: DeviceGroup[];
    monitoring: {
        total_devices: number;
        monitored_devices: number;
        unmonitored_devices: number;
        failed_monitors: number;
        uncertain_monitors: number;
        monitors: Monitor[];
    };
    alerts: Alert[];
    itWork: ItWork[];
    maintenance: Maintenance[];
    collectors: Collector[];
    changes: Array<{
        key: string;
        device_name: string | null;
        summary: string;
        at: string;
        href: string;
    }>;
    contacts: ContactItem[];
    can: {
        view_control_room: boolean;
        view_it_work: boolean;
        view_site_profile: boolean;
        export: boolean;
    };
};

const localLinks = [
    ['Overview', '#site-overview'],
    ['Monitoring', '#site-monitoring'],
    ['Alerts & work', '#site-work'],
    ['Devices', '#site-devices'],
];

export default function SiteTechnologyShow({
    site,
    devices,
    summary,
    wan,
    topology,
    deviceGroups,
    monitoring,
    alerts,
    itWork,
    maintenance,
    collectors,
    changes,
    contacts,
    can,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
                {
                    title: site.name,
                    href: `/security-devices/sites/${site.id}`,
                },
            ]}
        >
            <Head title={`${site.name} - Security & Devices`} />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Building2}
                    title={site.name}
                    description="One technology view for WAN context, monitoring, topology, devices, operational alerts, IT work, maintenance and local contacts."
                    stats={[
                        { label: 'Devices', value: summary.devices },
                        {
                            label: 'Monitored',
                            value: summary.monitored_devices,
                        },
                        {
                            label: 'Failed monitors',
                            value: summary.failed_monitors,
                        },
                        {
                            label: 'Open IT work',
                            value: summary.open_it_work ?? 'Restricted',
                        },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <SiteProfileDestination
                                siteId={site.id}
                                canView={can.view_site_profile}
                                tab="technology"
                            />
                            <OperationalStateBadge state={summary.health} />
                            {site.type ? (
                                <Badge variant="outline">
                                    {site.type.replace(/_/g, ' ')}
                                </Badge>
                            ) : null}
                        </div>
                    }
                />

                <nav
                    aria-label="Site technology sections"
                    className="flex flex-wrap gap-2 rounded-2xl border bg-card p-2"
                >
                    {localLinks.map(([label, href]) => (
                        <a
                            key={href}
                            href={href}
                            className="frontline-focus frontline-tap inline-flex items-center rounded-xl px-3 text-sm font-medium hover:bg-muted"
                        >
                            {label}
                        </a>
                    ))}
                </nav>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Devices"
                        value={summary.devices}
                        icon={Cpu}
                    />
                    <StatCard
                        label="Need attention"
                        value={summary.attention_devices}
                        icon={Activity}
                        variant={
                            summary.attention_devices > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                    <StatCard
                        label="Unmonitored"
                        value={summary.unmonitored_devices}
                        icon={RadioTower}
                        variant={
                            summary.unmonitored_devices > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                    <StatCard
                        label="Overdue maintenance"
                        value={summary.overdue_maintenance}
                        icon={Wrench}
                        variant={
                            summary.overdue_maintenance > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                </div>

                <section
                    id="site-overview"
                    aria-labelledby="site-overview-heading"
                    className="scroll-mt-6"
                >
                    <h2
                        id="site-overview-heading"
                        className="mb-3 text-lg font-semibold"
                    >
                        Site technology overview
                    </h2>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Network
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    WAN and topology
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <div className="flex items-start gap-3 rounded-xl border p-3">
                                    <MapPin
                                        className="mt-0.5 h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        {site.address ||
                                            site.city ||
                                            'No site address recorded'}
                                    </span>
                                </div>
                                <div className="rounded-xl border p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="font-medium">
                                            {wan.label}
                                        </p>
                                        <OperationalStateBadge
                                            state={
                                                wan.known
                                                    ? 'healthy'
                                                    : 'unknown'
                                            }
                                        />
                                    </div>
                                    {wan.devices.length > 0 ? (
                                        <ul className="mt-3 space-y-2">
                                            {wan.devices.map((device) => (
                                                <li key={device.id}>
                                                    <Link
                                                        href={`/security-devices/devices/${device.id}`}
                                                        className="frontline-focus inline-flex min-h-11 items-center rounded-lg text-sm font-medium hover:text-primary hover:underline"
                                                    >
                                                        {device.name}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            No router, gateway, firewall or
                                            SD-WAN device has been classified
                                            for this site.
                                        </p>
                                    )}
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-xl border p-3">
                                        <Cable
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="mt-2 text-lg font-semibold">
                                            {topology.edge_count}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Known links
                                        </p>
                                    </div>
                                    <div className="rounded-xl border p-3">
                                        <FolderTree
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="mt-2 text-lg font-semibold">
                                            {deviceGroups.length}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Device groups
                                        </p>
                                    </div>
                                </div>
                                {!topology.is_complete && devices.length > 1 ? (
                                    <p className="rounded-xl bg-muted p-3 text-xs text-muted-foreground">
                                        Topology is incomplete. Unknown links
                                        are not treated as healthy paths.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Contact
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Site context and contacts
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <div className="flex min-h-11 items-center gap-2 rounded-xl border px-3">
                                    <RadioTower
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>{summary.collector.label}</span>
                                    <OperationalStateBadge
                                        state={summary.collector.state}
                                        className="ml-auto"
                                    />
                                </div>
                                {collectors.length === 0 ? (
                                    <p className="rounded-xl bg-muted p-3 text-xs text-muted-foreground">
                                        A local collector is optional. SD-WAN
                                        reachable sites can be polled centrally;
                                        the Monitoring section shows actual
                                        coverage.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {collectors.map((collector) => (
                                            <li
                                                key={collector.id}
                                                className="flex min-h-11 items-center gap-3 rounded-xl border px-3"
                                            >
                                                <span className="min-w-0 flex-1 font-medium">
                                                    {collector.name}
                                                </span>
                                                <OperationalStateBadge
                                                    state={collector.state}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {contacts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No technology contact recorded.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {contacts.map((contact) => (
                                            <li
                                                key={contact.id}
                                                className="rounded-xl border p-3"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">
                                                        {contact.name}
                                                    </p>
                                                    {contact.is_primary ? (
                                                        <Badge variant="secondary">
                                                            Primary
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {contact.role ||
                                                        contact.type.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {[
                                                        contact.phone,
                                                        contact.email,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        'No contact details recorded'}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <section
                    id="site-monitoring"
                    aria-labelledby="site-monitoring-heading"
                    className="scroll-mt-6"
                >
                    <h2
                        id="site-monitoring-heading"
                        className="mb-3 text-lg font-semibold"
                    >
                        Monitoring coverage
                    </h2>
                    <Card>
                        <CardContent className="space-y-4 p-5">
                            <CoverageIndicator
                                percent={summary.coverage_percent}
                                monitored={monitoring.monitored_devices}
                                total={monitoring.total_devices}
                            />
                            {monitoring.monitors.length === 0 ? (
                                <EmptyState
                                    icon={RadioTower}
                                    title="No monitors configured"
                                    description="These devices are unmonitored. Unknown coverage is not presented as healthy."
                                />
                            ) : (
                                <ul className="grid gap-3 lg:grid-cols-2">
                                    {monitoring.monitors.map((monitor) => (
                                        <li
                                            key={monitor.id}
                                            className="rounded-xl border p-3"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="font-medium">
                                                        {monitor.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {monitor.device_name} ·{' '}
                                                        {monitor.kind.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </p>
                                                </div>
                                                <OperationalStateBadge
                                                    state={monitor.state}
                                                />
                                            </div>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {monitor.last_observation_at
                                                    ? `Observed ${formatRelative(monitor.last_observation_at)}`
                                                    : 'No observation received'}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <section
                    id="site-work"
                    aria-labelledby="site-work-heading"
                    className="scroll-mt-6"
                >
                    <h2
                        id="site-work-heading"
                        className="mb-3 text-lg font-semibold"
                    >
                        Alerts, IT work and maintenance
                    </h2>
                    <div className="grid gap-4 xl:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <BellRing
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Control Room alerts
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {!can.view_control_room ? (
                                    <p className="text-sm text-muted-foreground">
                                        Control Room permission is required.
                                    </p>
                                ) : alerts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No active alerts for this site.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {alerts.map((alert) => (
                                            <li key={alert.id}>
                                                {alert.href &&
                                                alert.access.state ===
                                                    'available' ? (
                                                    <Link
                                                        href={alert.href}
                                                        className="frontline-focus block rounded-xl border p-3 hover:bg-muted"
                                                    >
                                                        <div className="flex items-start justify-between gap-2">
                                                            <p className="font-medium">
                                                                {alert.title}
                                                            </p>
                                                            {alert.severity ? (
                                                                <OperationalStateBadge
                                                                    state={
                                                                        alert.severity ===
                                                                        'critical'
                                                                            ? 'critical'
                                                                            : 'warning'
                                                                    }
                                                                />
                                                            ) : null}
                                                        </div>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {alert.reference ||
                                                                'Alert'}{' '}
                                                            · {alert.status}
                                                        </p>
                                                    </Link>
                                                ) : (
                                                    <div className="rounded-xl border px-3">
                                                        <ControlRoomAlertAccessRequired
                                                            label={
                                                                alert.access
                                                                    .label
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <TicketCheck
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Open IT work
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {!can.view_it_work ? (
                                    <p className="text-sm text-muted-foreground">
                                        IT & Support permission is required.
                                    </p>
                                ) : itWork.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No open IT work for this site.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {itWork.map((ticket) => (
                                            <li key={ticket.id}>
                                                <Link
                                                    href={ticket.href}
                                                    className="frontline-focus block rounded-xl border p-3 hover:bg-muted"
                                                >
                                                    <p className="font-medium">
                                                        {ticket.title}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {ticket.reference ||
                                                            'IT work'}{' '}
                                                        ·{' '}
                                                        {ticket.status.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </p>
                                                    <p className="mt-2 text-xs">
                                                        {ticket.next_action ||
                                                            'Open the work item for the next action.'}
                                                    </p>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Wrench
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Maintenance
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {maintenance.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No open maintenance for this site.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {maintenance.map((record) => (
                                            <li
                                                key={record.id}
                                                className="rounded-xl border p-3"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="font-medium">
                                                        {record.description}
                                                    </p>
                                                    <OperationalStateBadge
                                                        state={
                                                            record.is_overdue
                                                                ? 'warning'
                                                                : 'pending'
                                                        }
                                                    />
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {record.device_name ||
                                                        'Device'}
                                                    {record.scheduled_for
                                                        ? ` · ${formatDateTime(record.scheduled_for)}`
                                                        : ' · Not scheduled'}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <section aria-labelledby="site-changes-heading">
                    <h2
                        id="site-changes-heading"
                        className="mb-3 flex items-center gap-2 text-lg font-semibold"
                    >
                        <Clock3 className="h-5 w-5" aria-hidden="true" />
                        Recent changes
                    </h2>
                    <Card>
                        <CardContent className="p-5">
                            {changes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No recent technology changes recorded.
                                </p>
                            ) : (
                                <ul className="grid gap-2 md:grid-cols-2">
                                    {changes.map((change) => (
                                        <li key={change.key}>
                                            <Link
                                                href={change.href}
                                                className="frontline-focus flex min-h-11 items-center justify-between gap-3 rounded-xl border px-3 hover:bg-muted"
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate font-medium">
                                                        {change.device_name ||
                                                            'Device'}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {change.summary}
                                                    </span>
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {formatRelative(change.at)}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <section
                    id="site-devices"
                    aria-labelledby="site-devices-heading"
                    className="scroll-mt-6"
                >
                    <h2
                        id="site-devices-heading"
                        className="mb-3 text-lg font-semibold"
                    >
                        Devices at this site
                    </h2>
                    {devices.length === 0 ? (
                        <EmptyState
                            icon={Cpu}
                            title="No devices assigned"
                            description="No canonical devices are currently assigned to this site or one of its rooms."
                        />
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {devices.map((device) => (
                                <DeviceCard key={device.id} device={device} />
                            ))}
                        </div>
                    )}
                </section>
            </PageShell>
        </AppLayout>
    );
}
