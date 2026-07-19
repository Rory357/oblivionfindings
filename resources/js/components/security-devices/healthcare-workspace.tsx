import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    CalendarClock,
    ChevronRight,
    CircleHelp,
    ExternalLink,
    HeartPulse,
    LaptopMinimalCheck,
    RadioTower,
    Stethoscope,
    UserRound,
    UsersRound,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';

type HealthcareAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

type HealthcareMaintenanceRecord = {
    id: number;
    type: string;
    status: string;
    description: string;
    scheduledFor: string | null;
    completedAt: string | null;
    vendorReference: string | null;
    overdue: boolean;
    device: { id: number; name: string; href: string } | null;
};

type HealthcareDevice = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    provider: string | null;
    status: string | null;
    health: string | null;
    lastSeenAt: string | null;
    deviceHref: string;
    client: { id: number; displayName: string; href: string } | null;
    location: {
        site: { id: number; name: string; href: string };
        room: { id: number; name: string } | null;
    } | null;
    assignment: {
        type: string;
        assignmentType: string | null;
        label: string;
        assignedAt: string | null;
    } | null;
    supportContact: { name: string; role: string } | null;
    technical: {
        battery: {
            level: number | null;
            updatedAt: string | null;
            state: string;
        };
        connectivity: { state: string; source: string };
        integration: { state: string; source: string };
        delivery: {
            state: string;
            lastSuccessfulAt: string | null;
            staleAfterMinutes: number;
        };
        flow: { state: string; label: string; description: string };
    };
    monitoring: { state: string; enabledCount: number };
    maintenance: {
        nextServiceDue: string | null;
        openCount: number;
        overdueCount: number;
        next: HealthcareMaintenanceRecord | null;
    } | null;
    itTickets: Array<{
        id: number;
        reference: string | null;
        title: string;
        status: string;
        href: string;
    }>;
};

type FlowGroup = {
    state: string;
    label: string;
    description: string;
    count: number;
    deviceIds: number[];
};

export type HealthcareWorkspaceData = {
    permissions: {
        clientContext: boolean;
        maintenance: boolean;
        it: boolean;
    };
    boundary: {
        title: string;
        description: string;
        clinicalHref: string;
    };
    overview: {
        inventory: {
            total: number;
            client_assigned: number;
            shared_site: number;
            unassigned: number;
        };
        attention: {
            offline: number;
            data_flow_issues: number;
            overdue_calibration: number | null;
            maintenance_due: number | null;
        };
        requiredActions: HealthcareAction[];
    };
    activeTab: {
        key: string;
        label: string;
        description: string;
        restricted: boolean;
        inventoryTotal: number;
        inventoryShown: number;
        inventoryTruncated: boolean;
        devices: HealthcareDevice[];
        flowGroups: FlowGroup[];
        maintenanceRecords: HealthcareMaintenanceRecord[];
    };
};

function headline(value: string): string {
    return value
        .replace(/_/g, ' ')
        .replace(/(^|\s)\S/g, (character) => character.toUpperCase());
}

function plural(value: number, singular: string, pluralLabel?: string): string {
    return `${value} ${value === 1 ? singular : (pluralLabel ?? `${singular}s`)}`;
}

function Metric({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl border border-border bg-muted/30 px-3 py-2 text-sm font-semibold">
            {children}
        </div>
    );
}

function Boundary({ data }: { data: HealthcareWorkspaceData['boundary'] }) {
    return (
        <Card className="border-status-info/30 bg-status-info-bg">
            <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3">
                    <div className="rounded-xl bg-status-info-bg p-2 text-status-info">
                        <Stethoscope className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h2 className="font-semibold">{data.title}</h2>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            {data.description}
                        </p>
                    </div>
                </div>
                <Link
                    href={data.clinicalHref}
                    className="inline-flex min-h-11 shrink-0 items-center gap-1.5 self-start rounded-lg px-2 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Open Client Health Monitoring
                    <ExternalLink className="h-4 w-4" aria-hidden="true" />
                </Link>
            </CardContent>
        </Card>
    );
}

function HealthcareOverview({ data }: { data: HealthcareWorkspaceData }) {
    const { inventory, attention, requiredActions } = data.overview;

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex items-start gap-3">
                        <div className="rounded-xl bg-primary/10 p-2 text-primary">
                            <HeartPulse
                                className="h-5 w-5"
                                aria-hidden="true"
                            />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold">
                                Healthcare devices at a glance
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Who the equipment supports, where shared devices
                                live, and whether their technical service is
                                dependable.
                            </p>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric>{plural(inventory.total, 'device')}</Metric>
                        <Metric>
                            {plural(
                                inventory.client_assigned,
                                'client assigned',
                                'client assigned',
                            )}
                        </Metric>
                        <Metric>
                            {plural(
                                inventory.shared_site,
                                'shared or site',
                                'shared or site',
                            )}
                        </Metric>
                        <Metric>
                            {plural(
                                inventory.unassigned,
                                'unassigned',
                                'unassigned',
                            )}
                        </Metric>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="outline" className="gap-1.5">
                            <RadioTower className="h-3.5 w-3.5" />
                            {plural(attention.offline, 'offline device')}
                        </Badge>
                        <Badge variant="outline" className="gap-1.5">
                            <Activity className="h-3.5 w-3.5" />
                            {plural(
                                attention.data_flow_issues,
                                'data-flow issue',
                            )}
                        </Badge>
                        {attention.overdue_calibration !== null ? (
                            <Badge variant="outline" className="gap-1.5">
                                <CalendarClock className="h-3.5 w-3.5" />
                                {plural(
                                    attention.overdue_calibration,
                                    'overdue calibration',
                                    'overdue calibrations',
                                )}
                            </Badge>
                        ) : null}
                    </div>
                </CardContent>
            </Card>

            <section aria-labelledby="healthcare-actions" className="space-y-3">
                <div>
                    <h2
                        id="healthcare-actions"
                        className="text-lg font-semibold"
                    >
                        What needs action
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Each item opens the technical workspace that owns the
                        next action.
                    </p>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                    {requiredActions.map((action) => (
                        <Link
                            key={action.key}
                            href={action.href}
                            className="group rounded-xl border border-border bg-card p-4 shadow-sm transition hover:border-primary/40 hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-semibold">
                                        {action.label}{' '}
                                        <span className="text-primary">
                                            ({action.count})
                                        </span>
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {action.description}
                                    </p>
                                </div>
                                <ChevronRight className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground group-hover:text-primary" />
                            </div>
                        </Link>
                    ))}
                </div>
            </section>
        </div>
    );
}

function TechnicalFact({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string | null;
}) {
    return (
        <div className="rounded-lg border border-border/70 bg-muted/20 p-2.5">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-0.5 text-sm font-semibold">{value}</p>
            {detail ? (
                <p className="mt-0.5 text-xs text-muted-foreground">{detail}</p>
            ) : null}
        </div>
    );
}

function HealthcareDeviceCard({ device }: { device: HealthcareDevice }) {
    const battery =
        device.technical.battery.level === null
            ? 'Battery not reported'
            : `${device.technical.battery.level}% battery`;

    return (
        <article
            aria-label={device.name}
            className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={device.deviceHref}
                        className="font-semibold hover:text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {device.name}
                    </Link>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {headline(device.subcategory ?? device.category)}
                        {device.provider ? ` • ${device.provider}` : ''}
                    </p>
                </div>
                <OperationalStateBadge
                    state={device.health ?? device.status ?? 'unknown'}
                />
            </div>

            <div className="space-y-2 text-sm">
                {device.client ? (
                    <div className="flex items-center gap-2">
                        <UserRound className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground">
                            Assigned client
                        </span>
                        <Link
                            href={device.client.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {device.client.displayName}
                        </Link>
                    </div>
                ) : null}
                {device.location ? (
                    <div className="flex items-center gap-2">
                        <Building2 className="h-4 w-4 text-muted-foreground" />
                        <Link
                            href={device.location.site.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {device.location.site.name}
                        </Link>
                        {device.location.room ? (
                            <span className="text-muted-foreground">
                                • {device.location.room.name}
                            </span>
                        ) : null}
                    </div>
                ) : null}
                {device.assignment ? (
                    <p className="text-muted-foreground">
                        {device.assignment.label}
                    </p>
                ) : (
                    <p className="text-muted-foreground">
                        Not currently assigned
                    </p>
                )}
                {device.supportContact ? (
                    <div className="flex items-center gap-2">
                        <UsersRound className="h-4 w-4 text-muted-foreground" />
                        <span className="font-medium">
                            {device.supportContact.name}
                        </span>
                        <span className="text-muted-foreground">
                            • {device.supportContact.role}
                        </span>
                    </div>
                ) : null}
            </div>

            <div className="grid gap-2 sm:grid-cols-2">
                <TechnicalFact
                    label="Battery"
                    value={battery}
                    detail={
                        device.technical.battery.updatedAt
                            ? `Updated ${formatDateTime(device.technical.battery.updatedAt)}`
                            : 'Update time not reported'
                    }
                />
                <TechnicalFact
                    label="Connectivity"
                    value={headline(device.technical.connectivity.state)}
                />
                <TechnicalFact
                    label="Integration"
                    value={headline(device.technical.integration.state)}
                />
                <TechnicalFact
                    label="Delivery"
                    value={headline(device.technical.delivery.state)}
                    detail={
                        device.technical.delivery.lastSuccessfulAt
                            ? `Last success ${formatDateTime(device.technical.delivery.lastSuccessfulAt)}`
                            : 'No successful delivery observed'
                    }
                />
            </div>

            <div className="rounded-lg border border-border bg-muted/20 p-3">
                <div className="flex items-center gap-2">
                    <LaptopMinimalCheck className="h-4 w-4 text-primary" />
                    <span className="font-semibold">
                        {device.technical.flow.label}
                    </span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    {device.technical.flow.description}
                </p>
            </div>

            {device.maintenance ? (
                <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <Wrench className="h-3.5 w-3.5" />
                        {plural(
                            device.maintenance.openCount,
                            'open maintenance item',
                        )}
                    </span>
                    {device.maintenance.nextServiceDue ? (
                        <span>
                            Next service{' '}
                            {formatDate(device.maintenance.nextServiceDue)}
                        </span>
                    ) : null}
                </div>
            ) : null}

            {device.itTickets.length > 0 ? (
                <div className="space-y-1.5 border-t border-border pt-3">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Linked IT work
                    </p>
                    {device.itTickets.map((ticket) => (
                        <Link
                            key={ticket.id}
                            href={ticket.href}
                            className="flex min-h-10 items-center justify-between gap-2 rounded-md px-2 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span>
                                {ticket.reference ?? `Ticket ${ticket.id}`}{' '}
                                {ticket.title}
                            </span>
                            <Badge variant="outline">
                                {headline(ticket.status)}
                            </Badge>
                        </Link>
                    ))}
                </div>
            ) : null}
        </article>
    );
}

function DeviceGrid({ devices }: { devices: HealthcareDevice[] }) {
    if (devices.length === 0) {
        return (
            <Card>
                <CardContent className="flex items-start gap-3 py-6">
                    <CircleHelp className="h-5 w-5 text-muted-foreground" />
                    <div>
                        <p className="font-medium">No devices in this view</p>
                        <p className="text-sm text-muted-foreground">
                            Change the tab or register and assign a healthcare
                            device.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            {devices.map((device) => (
                <HealthcareDeviceCard key={device.id} device={device} />
            ))}
        </div>
    );
}

function FlowGroups({ groups }: { groups: FlowGroup[] }) {
    return (
        <section
            aria-labelledby="healthcare-flow-heading"
            className="space-y-3"
        >
            <div>
                <h2
                    id="healthcare-flow-heading"
                    className="text-lg font-semibold"
                >
                    Technical flow states
                </h2>
                <p className="text-sm text-muted-foreground">
                    Healthy requires positive connectivity, integration, and
                    fresh delivery evidence. Missing evidence remains unknown or
                    unsupported.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {groups.map((group) => (
                    <article
                        key={group.state}
                        className="rounded-xl border border-border bg-card p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="font-semibold">{group.label}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {group.description}
                                </p>
                            </div>
                            <Badge variant="outline">{group.count}</Badge>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function MaintenanceRecords({
    records,
}: {
    records: HealthcareMaintenanceRecord[];
}) {
    return (
        <section
            aria-labelledby="healthcare-maintenance-heading"
            className="space-y-3"
        >
            <div>
                <h2
                    id="healthcare-maintenance-heading"
                    className="text-lg font-semibold"
                >
                    Canonical calibration & maintenance
                </h2>
                <p className="text-sm text-muted-foreground">
                    These records come from the device maintenance history;
                    integration metadata is never treated as proof of
                    calibration.
                </p>
            </div>
            {records.length > 0 ? (
                <div className="grid gap-3 lg:grid-cols-2">
                    {records.map((record) => (
                        <article
                            key={record.id}
                            className="rounded-xl border border-border bg-card p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {headline(record.type)}
                                    </p>
                                    <h3 className="mt-1 font-semibold">
                                        {record.description}
                                    </h3>
                                </div>
                                {record.overdue ? (
                                    <Badge variant="destructive">Overdue</Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {headline(record.status)}
                                    </Badge>
                                )}
                            </div>
                            <div className="mt-3 space-y-1 text-sm text-muted-foreground">
                                {record.device ? (
                                    <Link
                                        href={record.device.href}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {record.device.name}
                                    </Link>
                                ) : null}
                                {record.scheduledFor ? (
                                    <p>
                                        Scheduled{' '}
                                        {formatDate(record.scheduledFor)}
                                    </p>
                                ) : null}
                                {record.completedAt ? (
                                    <p>
                                        Completed{' '}
                                        {formatDateTime(record.completedAt)}
                                    </p>
                                ) : null}
                                {record.vendorReference ? (
                                    <p>{record.vendorReference}</p>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <Card>
                    <CardContent className="flex items-center gap-3 py-6 text-sm text-muted-foreground">
                        <Wrench className="h-5 w-5" />
                        No canonical calibration or maintenance records are
                        available.
                    </CardContent>
                </Card>
            )}
        </section>
    );
}

function Restricted({ tab }: { tab: string }) {
    const message =
        tab === 'client-devices'
            ? 'Client-device context is restricted by permission.'
            : 'Calibration and maintenance history is restricted by permission.';

    return (
        <Card>
            <CardContent className="flex items-start gap-3 py-6">
                <AlertTriangle className="h-5 w-5 text-status-warning" />
                <div>
                    <p className="font-medium">Restricted workspace</p>
                    <p className="text-sm text-muted-foreground">{message}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function HealthcareWorkspacePanels({
    data,
}: {
    data: HealthcareWorkspaceData;
}) {
    const tab = data.activeTab.key;

    return (
        <div className="space-y-4">
            <Boundary data={data.boundary} />

            {data.activeTab.restricted ? (
                <Restricted tab={tab} />
            ) : (
                <>
                    {tab === 'overview' ? (
                        <HealthcareOverview data={data} />
                    ) : null}
                    {tab === 'data-flow' ? (
                        <FlowGroups groups={data.activeTab.flowGroups} />
                    ) : null}
                    {tab === 'calibration-maintenance' ? (
                        <MaintenanceRecords
                            records={data.activeTab.maintenanceRecords}
                        />
                    ) : null}
                    {[
                        'overview',
                        'client-devices',
                        'shared-site-devices',
                        'data-flow',
                    ].includes(tab) ? (
                        <section
                            className="space-y-3"
                            aria-labelledby="healthcare-devices-heading"
                        >
                            <div>
                                <h2
                                    id="healthcare-devices-heading"
                                    className="text-lg font-semibold"
                                >
                                    {tab === 'overview'
                                        ? 'Device readiness'
                                        : data.activeTab.label}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {data.activeTab.inventoryShown} of{' '}
                                    {data.activeTab.inventoryTotal} authorised
                                    devices shown.
                                </p>
                            </div>
                            <DeviceGrid devices={data.activeTab.devices} />
                        </section>
                    ) : null}
                </>
            )}
        </div>
    );
}
