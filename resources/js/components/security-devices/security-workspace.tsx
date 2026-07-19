import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BellRing,
    Building2,
    Camera,
    ChevronRight,
    CircleHelp,
    ExternalLink,
    KeyRound,
    MapPin,
    RadioTower,
    ShieldAlert,
    ShieldCheck,
    Siren,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';

type SecurityAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

type SecurityDevice = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    provider: string | null;
    status: string | null;
    health: string | null;
    lastSeenAt: string | null;
    deviceHref: string;
    site: { id: number; name: string; href: string } | null;
    assignment: { type: string; label: string } | null;
    monitoring: { state: string; count: number };
    observed: Record<string, unknown>;
    maintenance: {
        open_count: number;
        overdue_count: number;
        next: {
            id: number;
            type: string;
            status: string;
            description: string;
            scheduledFor: string | null;
        } | null;
    } | null;
    media?: {
        state: 'available' | 'restricted' | 'not_configured';
        href?: string;
    };
};

type SecurityEvent = {
    id: number;
    type: string;
    severity: string;
    source: string | null;
    occurredAt: string | null;
    processedAt: string | null;
    device: { id: number; name: string; href: string } | null;
    context: Record<string, unknown>;
};

type SecurityAlert = {
    id: number;
    reference: string | null;
    title: string;
    severity: string;
    status: string;
    triggeredAt: string | null;
    canonicalDeviceId: number | null;
    href: string;
};

export type SecurityWorkspaceData = {
    permissions: {
        events: boolean;
        maintenance: boolean;
        control_room: boolean;
        cctv_media: boolean;
    };
    overview: {
        inventory: {
            total: number;
            cctv: number;
            alarms: number;
            access_control: number;
            other: number;
        };
        attention: {
            devices: number;
            sites: number;
            overdue_maintenance: number | null;
            unprocessed_events: number | null;
            active_control_room_alerts: number | null;
        };
        requiredActions: SecurityAction[];
    };
    activeTab: {
        key: string;
        label: string;
        description: string;
        restricted: boolean;
        inventoryTotal: number;
        inventoryShown: number;
        inventoryTruncated: boolean;
        devices: SecurityDevice[];
        recentEvents: SecurityEvent[];
        controlRoomAlerts: SecurityAlert[];
    };
};

const observationLabels: Record<string, string> = {
    stream_health: 'Stream',
    recording_health: 'Recording',
    camera_state: 'Camera',
    recorder_state: 'Recorder',
    alarm_state: 'Alarm',
    partition_state: 'Partition',
    sensor_state: 'Sensor',
    zones: 'Zones',
    door_state: 'Door',
    lock_state: 'Lock',
    reader_state: 'Reader',
    panel_state: 'Panel',
    credential_count: 'Credentials',
    schedule_count: 'Schedules',
};

function headline(value: string): string {
    return value
        .replace(/_/g, ' ')
        .replace(/(^|\s)\S/g, (character) => character.toUpperCase());
}

function sentence(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function plural(value: number, singular: string, pluralLabel?: string): string {
    return `${value} ${value === 1 ? singular : (pluralLabel ?? `${singular}s`)}`;
}

function observedValue(value: unknown): string {
    if (typeof value === 'string') return headline(value);
    if (typeof value === 'number') return value.toLocaleString();
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (value && typeof value === 'object') {
        return Object.entries(value)
            .map(
                ([key, item]) =>
                    `${String(item)} ${headline(key).toLowerCase()}`,
            )
            .join(' • ');
    }

    return 'Unknown';
}

function SectionHeading({
    icon: Icon,
    title,
    description,
}: {
    icon: typeof ShieldCheck;
    title: string;
    description: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <div className="rounded-xl bg-primary/10 p-2 text-primary">
                <Icon className="h-5 w-5" aria-hidden="true" />
            </div>
            <div>
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

function Metric({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl border border-border bg-muted/30 px-3 py-2 text-sm font-semibold">
            {children}
        </div>
    );
}

function SecurityOverview({ data }: { data: SecurityWorkspaceData }) {
    const { inventory, attention, requiredActions } = data.overview;

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="pb-3">
                    <SectionHeading
                        icon={ShieldCheck}
                        title="Security at a glance"
                        description="Physical-security technology only. User roles and application permissions remain in Access settings."
                    />
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric>
                            {plural(inventory.cctv, 'CCTV', 'CCTV')}
                        </Metric>
                        <Metric>{plural(inventory.alarms, 'alarm')}</Metric>
                        <Metric>
                            {plural(
                                inventory.access_control,
                                'access control',
                                'access control',
                            )}
                        </Metric>
                        <Metric>
                            {plural(inventory.other, 'other device')}
                        </Metric>
                    </div>
                    <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                        <Badge variant="outline" className="gap-1.5">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            {plural(
                                attention.devices,
                                'device needs attention',
                                'devices need attention',
                            )}
                        </Badge>
                        <Badge variant="outline" className="gap-1.5">
                            <Building2 className="h-3.5 w-3.5" />
                            {plural(attention.sites, 'site affected')}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <section
                aria-labelledby="security-required-actions"
                className="space-y-3"
            >
                <div>
                    <h2
                        id="security-required-actions"
                        className="text-lg font-semibold"
                    >
                        What needs action
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Each item opens the existing operational record; this
                        page does not create a second alert queue.
                    </p>
                </div>
                {requiredActions.length > 0 ? (
                    <div className="grid gap-3 lg:grid-cols-2">
                        {requiredActions.map((action) => (
                            <Link
                                key={action.key}
                                href={action.href}
                                className="frontline-focus group rounded-xl border border-border bg-card p-4 transition-colors hover:border-primary/40"
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
                ) : (
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4 text-sm">
                            <ShieldCheck className="h-5 w-5 text-status-success" />
                            No current security-device actions are recorded.
                        </CardContent>
                    </Card>
                )}
            </section>
        </div>
    );
}

function DeviceEvidence({ device }: { device: SecurityDevice }) {
    const observed = Object.entries(device.observed);

    return (
        <article
            aria-label={device.name}
            className="rounded-xl border border-border bg-card p-4"
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <Link
                        href={device.deviceHref}
                        className="frontline-focus font-semibold hover:text-primary"
                    >
                        {device.name}
                    </Link>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {headline(device.subcategory ?? device.category)}
                        {device.provider ? ` • ${device.provider}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <OperationalStateBadge state={device.health ?? 'unknown'} />
                    <OperationalStateBadge state={device.monitoring.state} />
                </div>
            </div>

            <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div className="space-y-1">
                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Location
                    </p>
                    {device.site ? (
                        <Link
                            href={device.site.href}
                            className="frontline-focus inline-flex items-center gap-1.5 hover:text-primary"
                        >
                            <MapPin className="h-4 w-4" />
                            {device.site.name}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">
                            No site assignment
                        </span>
                    )}
                </div>
                <div className="space-y-1">
                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Last observation
                    </p>
                    <span
                        title={
                            device.lastSeenAt
                                ? formatDateTime(device.lastSeenAt)
                                : undefined
                        }
                    >
                        {device.lastSeenAt
                            ? formatRelative(device.lastSeenAt)
                            : 'Never observed'}
                    </span>
                </div>
            </div>

            <div className="mt-4 border-t border-border pt-3">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Provider evidence
                </p>
                {observed.length > 0 ? (
                    <div className="mt-2 flex flex-wrap gap-2">
                        {observed.map(([key, value]) => (
                            <Badge
                                key={key}
                                variant="secondary"
                                className="font-medium"
                            >
                                {observationLabels[key] ?? headline(key)}:{' '}
                                {observedValue(value)}
                            </Badge>
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Specialist state not reported by integration
                    </p>
                )}
            </div>

            {device.maintenance?.next ? (
                <div className="mt-4 flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-sm">
                    <Wrench className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                    <div>
                        <p className="font-medium">
                            {device.maintenance.next.description}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {device.maintenance.next.scheduledFor
                                ? `Due ${formatDate(device.maintenance.next.scheduledFor)}`
                                : 'Scheduled date not recorded'}
                            {device.maintenance.overdue_count > 0
                                ? ' • Overdue'
                                : ''}
                        </p>
                    </div>
                </div>
            ) : null}

            {device.media ? (
                <div className="mt-4 border-t border-border pt-3 text-sm">
                    {device.media.state === 'available' && device.media.href ? (
                        <Link
                            href={device.media.href}
                            className="frontline-focus inline-flex min-h-11 items-center gap-2 font-semibold text-primary hover:underline"
                        >
                            <Camera className="h-4 w-4" />
                            Open authorised media
                            <ExternalLink className="h-3.5 w-3.5" />
                        </Link>
                    ) : device.media.state === 'restricted' ? (
                        <span className="inline-flex items-center gap-2 text-muted-foreground">
                            <KeyRound className="h-4 w-4" /> Media access
                            restricted
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-2 text-muted-foreground">
                            <CircleHelp className="h-4 w-4" /> Media link not
                            configured
                        </span>
                    )}
                </div>
            ) : null}
        </article>
    );
}

function SpecialistInventory({ data }: { data: SecurityWorkspaceData }) {
    const active = data.activeTab;
    const icon =
        active.key === 'cctv'
            ? Camera
            : active.key === 'alarms'
              ? Siren
              : KeyRound;

    return (
        <section aria-labelledby="specialist-inventory" className="space-y-3">
            <SectionHeading
                icon={icon}
                title={`${active.label} operational evidence`}
                description={`${plural(active.inventoryTotal, 'device')} in the authorised workspace. Provider-specific values appear only when observed.`}
            />
            {active.devices.length > 0 ? (
                <div className="grid gap-3 xl:grid-cols-2">
                    {active.devices.map((device) => (
                        <DeviceEvidence key={device.id} device={device} />
                    ))}
                </div>
            ) : (
                <Card>
                    <CardContent className="p-5 text-sm text-muted-foreground">
                        No {active.label.toLowerCase()} devices are registered
                        in the authorised scope.
                    </CardContent>
                </Card>
            )}
            {active.inventoryTruncated ? (
                <p className="text-xs text-muted-foreground">
                    Showing the first {active.inventoryShown} devices. Use the
                    inventory filters below to narrow the full list.
                </p>
            ) : null}
        </section>
    );
}

function EventsPanel({ data }: { data: SecurityWorkspaceData }) {
    const { recentEvents, controlRoomAlerts } = data.activeTab;

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <h2 className="flex items-center gap-2 text-base font-semibold">
                        <RadioTower className="h-4 w-4 text-primary" />{' '}
                        Canonical device events
                    </h2>
                </CardHeader>
                <CardContent className="space-y-3">
                    {!data.permissions.events ? (
                        <p className="text-sm text-muted-foreground">
                            Security-event history is restricted by permission.
                        </p>
                    ) : recentEvents.length > 0 ? (
                        recentEvents.map((event) => (
                            <div
                                key={event.id}
                                className="rounded-xl border border-border p-3 text-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">
                                            {sentence(event.type)}
                                        </p>
                                        {event.device ? (
                                            <Link
                                                href={event.device.href}
                                                className="frontline-focus text-muted-foreground hover:text-primary"
                                            >
                                                {event.device.name}
                                            </Link>
                                        ) : null}
                                    </div>
                                    <OperationalStateBadge
                                        state={event.severity || 'unknown'}
                                    />
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {event.source ?? 'Unknown source'}
                                    {event.occurredAt
                                        ? ` • ${formatRelative(event.occurredAt)}`
                                        : ''}
                                    {!event.processedAt
                                        ? ' • Processing incomplete'
                                        : ''}
                                </p>
                            </div>
                        ))
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No canonical security-device events are recorded.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <h2 className="flex items-center gap-2 text-base font-semibold">
                        <BellRing className="h-4 w-4 text-primary" /> Control
                        Room context
                    </h2>
                </CardHeader>
                <CardContent className="space-y-3">
                    {controlRoomAlerts.length > 0 ? (
                        controlRoomAlerts.map((alert) => (
                            <Link
                                key={alert.id}
                                href={alert.href}
                                className="frontline-focus block rounded-xl border border-border p-3 transition-colors hover:border-primary/40"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">
                                            {alert.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {alert.reference ??
                                                `Alert #${alert.id}`}
                                            {alert.triggeredAt
                                                ? ` • ${formatRelative(alert.triggeredAt)}`
                                                : ''}
                                        </p>
                                    </div>
                                    <OperationalStateBadge
                                        state={alert.severity}
                                    />
                                </div>
                            </Link>
                        ))
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {data.permissions.control_room
                                ? 'No active Control Room alerts are linked to this scope.'
                                : 'Control Room context is restricted by permission.'}
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Acknowledge, triage, and resolve alerts in Control Room
                        so there is one operational record.
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

export function SecurityWorkspacePanels({
    data,
}: {
    data: SecurityWorkspaceData;
}) {
    if (data.activeTab.restricted) {
        return (
            <Card>
                <CardContent className="flex items-start gap-3 p-5">
                    <ShieldAlert className="mt-0.5 h-5 w-5 text-muted-foreground" />
                    <div>
                        <p className="font-semibold">
                            Security event access is restricted
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Your role can view device inventory but not
                            security-event details.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (data.activeTab.key === 'overview') {
        return <SecurityOverview data={data} />;
    }

    if (data.activeTab.key === 'events') {
        return <EventsPanel data={data} />;
    }

    return (
        <div className="space-y-4">
            <SpecialistInventory data={data} />
            <EventsPanel data={data} />
        </div>
    );
}
