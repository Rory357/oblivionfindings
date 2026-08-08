import {
    MonitoringIncidentEvidenceCard,
    type MonitoringIncidentEvidence,
} from '@/components/monitoring/monitoring-incident-evidence-card';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    AlertTriangle,
    BookOpenCheck,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    CircleHelp,
    ExternalLink,
    Link2,
    LockKeyhole,
    Megaphone,
    Server,
    ShieldAlert,
    Unlink,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export interface TicketLinkedDevice {
    id: number;
    uid: string;
    name: string;
    domain: string;
    category: string;
    status: string;
    health_status: string;
    last_seen_at: string | null;
    href: string;
    is_monitoring_evidence: boolean;
    can_unlink: boolean;
}

export interface TicketDeviceOption {
    id: number;
    name: string;
    uid: string;
    site_id: number | null;
}

export interface TicketLinkedAlert {
    id: number;
    reference: string;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    href: string;
}

export interface TicketLinkedProblem {
    id: number;
    reference: string;
    title: string;
    workflow_state: string;
    root_cause: string | null;
    workaround: string | null;
    known_error_at: string | null;
    href: string | null;
    workspace_access: LinkedWorkspaceAccess;
    ticket_href: string;
}

export interface TicketLinkedChange {
    id: number;
    reference: string;
    title: string;
    workflow_state: string;
    change_type: string;
    risk_level: string;
    is_restricted: boolean;
    maintenance_starts_at: string | null;
    maintenance_ends_at: string | null;
    href: string | null;
    workspace_access: LinkedWorkspaceAccess;
    ticket_href: string;
}

export interface TicketLinkedMajorIncident {
    id: number;
    reference: string;
    title: string;
    workflow_state: string;
    severity: string;
    impact_summary: string | null;
    restored_at: string | null;
    next_update_due_at: string | null;
    href: string | null;
    workspace_access: LinkedWorkspaceAccess;
    ticket_href: string;
}

interface LinkedWorkspaceAccess {
    state: 'available' | 'restricted';
    message: string | null;
}

interface Props {
    recoveredAt: string | null;
    devices: TicketLinkedDevice[];
    alerts: TicketLinkedAlert[];
    problems?: TicketLinkedProblem[];
    changes?: TicketLinkedChange[];
    majorIncidents?: TicketLinkedMajorIncident[];
    incidentEvidence?: MonitoringIncidentEvidence[];
    canManage?: boolean;
    canLinkDevices?: boolean;
    deviceOptions?: TicketDeviceOption[];
    ticketId?: number;
}

interface StatusPresentation {
    variant: StatusVariant;
    icon: LucideIcon;
}

const label = (raw: string) =>
    raw
        .replace(/[_-]/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

function statusPresentation(status: string): StatusPresentation {
    switch (status) {
        case 'active':
        case 'healthy':
        case 'online':
        case 'resolved':
        case 'closed':
        case 'restored':
            return { variant: 'success', icon: CheckCircle2 };
        case 'degraded':
        case 'warning':
        case 'medium':
        case 'waiting':
        case 'monitoring':
            return { variant: 'warning', icon: AlertTriangle };
        case 'critical':
        case 'failed':
        case 'high':
        case 'offline':
        case 'sev1':
        case 'sev2':
        case 'declared':
        case 'responding':
            return { variant: 'critical', icon: CircleAlert };
        case 'open':
        case 'in_progress':
            return { variant: 'info', icon: Activity };
        default:
            return { variant: 'neutral', icon: CircleHelp };
    }
}

function ContextStatus({ value }: { value: string }) {
    const presentation = statusPresentation(value);
    const Icon = presentation.icon;

    return (
        <StatusBadge variant={presentation.variant} size="sm">
            <Icon aria-hidden="true" className="h-3 w-3" />
            {label(value)}
        </StatusBadge>
    );
}

function WorkspaceRecordDestination({
    href,
    access,
    icon: Icon,
    iconClassName,
    reference,
    title,
}: {
    href: string | null;
    access: LinkedWorkspaceAccess;
    icon: LucideIcon;
    iconClassName: string;
    reference: string;
    title: string;
}) {
    const content = (
        <>
            <Icon
                aria-hidden="true"
                className={`mt-0.5 h-4 w-4 flex-none ${iconClassName}`}
            />
            <span className="min-w-0 flex-1">
                <span className="flex items-center justify-between gap-2">
                    <span className="font-mono text-[12px] font-bold">
                        {reference}
                    </span>
                    {href && access.state === 'available' ? (
                        <ExternalLink
                            aria-hidden="true"
                            className="h-3.5 w-3.5 text-muted-foreground"
                        />
                    ) : null}
                </span>
                <span className="mt-0.5 block truncate text-[11.5px] font-medium">
                    {title}
                </span>
                {!href || access.state === 'restricted' ? (
                    <span className="mt-1 flex items-center gap-1 text-[11px] font-medium text-muted-foreground">
                        <LockKeyhole
                            aria-hidden="true"
                            className="h-3.5 w-3.5 flex-none"
                        />
                        {access.message ??
                            'IT workspace access is required to open this record.'}
                    </span>
                ) : null}
            </span>
        </>
    );

    if (href && access.state === 'available') {
        return (
            <Link
                href={href}
                className="frontline-focus flex min-h-11 items-start gap-2.5 px-3 py-2.5 hover:bg-muted/40"
            >
                {content}
            </Link>
        );
    }

    return (
        <div className="flex min-h-11 items-start gap-2.5 px-3 py-2.5">
            {content}
        </div>
    );
}

export function TicketLinkedContext({
    recoveredAt,
    devices,
    alerts,
    problems = [],
    changes = [],
    majorIncidents = [],
    incidentEvidence = [],
    canManage = false,
    canLinkDevices = false,
    deviceOptions = [],
    ticketId,
}: Props) {
    const [selectedDevice, setSelectedDevice] = useState('none');
    const [processingDevice, setProcessingDevice] = useState<number | null>(
        null,
    );
    const linkedDeviceIds = new Set(devices.map((device) => device.id));
    const availableDevices = deviceOptions.filter(
        (device) => !linkedDeviceIds.has(device.id),
    );
    const canChangeDevices =
        canManage && canLinkDevices && ticketId !== undefined;
    const hasLinks =
        incidentEvidence.length > 0 ||
        devices.length > 0 ||
        alerts.length > 0 ||
        problems.length > 0 ||
        changes.length > 0 ||
        majorIncidents.length > 0;

    return (
        <section
            aria-labelledby="ticket-linked-context"
            className="border-t border-border/60 pt-3"
        >
            <div className="flex items-center gap-2">
                <Link2
                    aria-hidden="true"
                    className="h-3.5 w-3.5 text-muted-foreground"
                />
                <h2
                    id="ticket-linked-context"
                    className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                >
                    Linked context
                </h2>
            </div>

            {canManage && !canLinkDevices ? (
                <div
                    role="note"
                    className="mt-3 flex min-h-11 items-center gap-2 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5 text-[11.5px] text-muted-foreground"
                >
                    <LockKeyhole
                        aria-hidden="true"
                        className="h-4 w-4 flex-none"
                    />
                    Security &amp; Devices access is required to add affected
                    Devices.
                </div>
            ) : null}

            {canChangeDevices ? (
                <div className="mt-3 rounded-xl border border-border/70 bg-muted/20 p-3">
                    <label
                        htmlFor="ticket-affected-device"
                        className="text-[11.5px] font-semibold text-foreground"
                    >
                        Add affected Device
                    </label>
                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                        Choose a visible Security &amp; Devices record in this
                        ticket&apos;s Site.
                    </p>
                    {availableDevices.length > 0 ? (
                        <Select
                            value={selectedDevice}
                            onValueChange={(value) => {
                                setSelectedDevice(value);
                                if (value === 'none' || ticketId === undefined)
                                    return;

                                const deviceId = Number(value);
                                setProcessingDevice(deviceId);
                                router.post(
                                    `/it/tickets/${ticketId}/devices`,
                                    { device_id: deviceId },
                                    {
                                        preserveScroll: true,
                                        onSuccess: (page) => {
                                            const flash = page.props.flash as
                                                | {
                                                      error?: string;
                                                      success?: string;
                                                  }
                                                | undefined;
                                            if (flash?.error)
                                                toast.error(flash.error);
                                            else
                                                toast.success(
                                                    flash?.success ??
                                                        'Device linked to ticket.',
                                                );
                                            setSelectedDevice('none');
                                        },
                                        onFinish: () =>
                                            setProcessingDevice(null),
                                    },
                                );
                            }}
                            disabled={processingDevice !== null}
                        >
                            <SelectTrigger
                                id="ticket-affected-device"
                                className="frontline-focus mt-2 min-h-11 w-full"
                                aria-label="Add affected Device"
                            >
                                <SelectValue placeholder="Choose a Device" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Choose a Device
                                </SelectItem>
                                {availableDevices.map((device) => (
                                    <SelectItem
                                        key={device.id}
                                        value={String(device.id)}
                                    >
                                        {device.name} · {device.uid}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : (
                        <p className="mt-2 text-[11.5px] text-muted-foreground">
                            {deviceOptions.length === 0
                                ? 'No visible Devices are available for this Site.'
                                : 'All available Devices for this Site are already linked.'}
                        </p>
                    )}
                </div>
            ) : null}

            {recoveredAt ? (
                <div className="mt-2 rounded-xl border border-status-success/30 bg-status-success-bg px-3 py-2.5">
                    <div className="flex items-center gap-2 text-status-success">
                        <CheckCircle2
                            aria-hidden="true"
                            className="h-4 w-4 flex-none"
                        />
                        <span className="text-[12.5px] font-semibold">
                            Monitoring recovered
                        </span>
                    </div>
                    <p className="mt-1 text-[11.5px] text-muted-foreground">
                        {formatDateTime(recoveredAt)} · Technician resolution is
                        still required.
                    </p>
                </div>
            ) : null}

            {incidentEvidence.length > 0 ? (
                <div className="mt-3 space-y-2">
                    {incidentEvidence.map((evidence) => (
                        <MonitoringIncidentEvidenceCard
                            key={evidence.id}
                            evidence={evidence}
                        />
                    ))}
                    <p className="rounded-lg border border-dashed border-border px-2.5 py-2 text-[11px] text-muted-foreground">
                        The Device and Control Room records below are live now.
                        IT owns this ticket; Control Room continues to own
                        operational response.
                    </p>
                </div>
            ) : null}

            {!hasLinks ? (
                <div className="mt-2 rounded-xl border border-dashed border-border px-3 py-3 text-center">
                    <Link2
                        aria-hidden="true"
                        className="mx-auto h-4 w-4 text-muted-foreground"
                    />
                    <p className="mt-1 text-[12px] text-muted-foreground">
                        No linked operational records are visible.
                    </p>
                </div>
            ) : null}

            {majorIncidents.length > 0 ? (
                <div className="mt-3">
                    <h3 className="text-[10.5px] font-bold tracking-wide text-status-critical uppercase">
                        Major incident command
                    </h3>
                    <ul className="mt-1.5 space-y-2">
                        {majorIncidents.map((incident) => (
                            <li
                                key={incident.id}
                                className="overflow-hidden rounded-xl border border-status-critical/35 bg-status-critical-bg"
                            >
                                <WorkspaceRecordDestination
                                    href={incident.href}
                                    access={incident.workspace_access}
                                    icon={Megaphone}
                                    iconClassName="text-status-critical"
                                    reference={incident.reference}
                                    title={incident.title}
                                />
                                <div className="space-y-1 border-t border-status-critical/20 px-3 py-2">
                                    <div className="flex flex-wrap gap-1.5">
                                        <ContextStatus
                                            value={incident.severity}
                                        />
                                        <ContextStatus
                                            value={incident.workflow_state}
                                        />
                                    </div>
                                    {incident.impact_summary ? (
                                        <p className="text-[11.5px] text-foreground">
                                            {incident.impact_summary}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {devices.length > 0 ? (
                <div className="mt-3">
                    <h3 className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        Affected devices
                    </h3>
                    <ul className="mt-1.5 space-y-2">
                        {devices.map((device) => (
                            <li
                                key={device.id}
                                className="overflow-hidden rounded-xl border border-border/70 bg-muted/20"
                            >
                                <Link
                                    href={device.href}
                                    className="frontline-focus flex min-h-11 items-start gap-2.5 px-3 py-2.5 hover:bg-muted/60"
                                >
                                    <Server
                                        aria-hidden="true"
                                        className="mt-0.5 h-4 w-4 flex-none text-primary"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="truncate text-[12.5px] font-semibold">
                                                {device.name}
                                            </span>
                                            <ExternalLink
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5 flex-none text-muted-foreground"
                                            />
                                        </span>
                                        <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                                            {label(device.category)} ·{' '}
                                            {device.uid}
                                        </span>
                                    </span>
                                </Link>
                                <div className="flex flex-wrap items-center gap-1.5 border-t border-border/60 px-3 py-2">
                                    <ContextStatus
                                        value={device.health_status}
                                    />
                                    <ContextStatus value={device.status} />
                                    {device.is_monitoring_evidence ? (
                                        <StatusBadge variant="info" size="sm">
                                            <Link2
                                                aria-hidden="true"
                                                className="h-3 w-3"
                                            />
                                            Monitoring evidence
                                        </StatusBadge>
                                    ) : null}
                                    <span className="ml-auto text-[10.5px] text-muted-foreground">
                                        Last seen{' '}
                                        {formatDateTime(device.last_seen_at)}
                                    </span>
                                    {canChangeDevices && device.can_unlink ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="frontline-focus min-h-11"
                                            disabled={
                                                processingDevice === device.id
                                            }
                                            onClick={() => {
                                                if (ticketId === undefined)
                                                    return;
                                                setProcessingDevice(device.id);
                                                router.delete(
                                                    `/it/tickets/${ticketId}/devices/${device.id}`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: (page) => {
                                                            const flash = page
                                                                .props.flash as
                                                                | {
                                                                      error?: string;
                                                                      success?: string;
                                                                  }
                                                                | undefined;
                                                            if (flash?.error)
                                                                toast.error(
                                                                    flash.error,
                                                                );
                                                            else
                                                                toast.success(
                                                                    flash?.success ??
                                                                        'Device link removed.',
                                                                );
                                                        },
                                                        onFinish: () =>
                                                            setProcessingDevice(
                                                                null,
                                                            ),
                                                    },
                                                );
                                            }}
                                        >
                                            <Unlink
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5"
                                            />
                                            Remove link
                                        </Button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {alerts.length > 0 ? (
                <div className="mt-3">
                    <h3 className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        Control Room alerts
                    </h3>
                    <ul className="mt-1.5 space-y-2">
                        {alerts.map((alert) => (
                            <li
                                key={alert.id}
                                className="overflow-hidden rounded-xl border border-border/70 bg-muted/20"
                            >
                                <Link
                                    href={alert.href}
                                    className="frontline-focus flex min-h-11 items-start gap-2.5 px-3 py-2.5 hover:bg-muted/60"
                                >
                                    <ShieldAlert
                                        aria-hidden="true"
                                        className="mt-0.5 h-4 w-4 flex-none text-primary"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="font-mono text-[12px] font-bold">
                                                {alert.reference}
                                            </span>
                                            <ExternalLink
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5 flex-none text-muted-foreground"
                                            />
                                        </span>
                                        <span className="mt-0.5 block truncate text-[11.5px] text-muted-foreground">
                                            {alert.alert_type}
                                        </span>
                                    </span>
                                </Link>
                                <div className="flex flex-wrap items-center gap-1.5 border-t border-border/60 px-3 py-2">
                                    <ContextStatus value={alert.severity} />
                                    <ContextStatus value={alert.status} />
                                    <span className="ml-auto text-[10.5px] text-muted-foreground">
                                        Triggered{' '}
                                        {formatDateTime(alert.triggered_at)}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {problems.length > 0 ? (
                <div className="mt-3">
                    <h3 className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        Known problems
                    </h3>
                    <ul className="mt-1.5 space-y-2">
                        {problems.map((problem) => (
                            <li
                                key={problem.id}
                                className="overflow-hidden rounded-xl border border-status-warning/30 bg-status-warning-bg"
                            >
                                <WorkspaceRecordDestination
                                    href={problem.href}
                                    access={problem.workspace_access}
                                    icon={BookOpenCheck}
                                    iconClassName="text-status-warning"
                                    reference={problem.reference}
                                    title={problem.title}
                                />
                                <div className="space-y-1 border-t border-status-warning/20 px-3 py-2">
                                    <ContextStatus
                                        value={problem.workflow_state}
                                    />
                                    {problem.workaround ? (
                                        <p className="text-[11.5px] text-foreground">
                                            <span className="font-semibold">
                                                Workaround:
                                            </span>{' '}
                                            {problem.workaround}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {changes.length > 0 ? (
                <div className="mt-3">
                    <h3 className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        Scheduled maintenance
                    </h3>
                    <ul className="mt-1.5 space-y-2">
                        {changes.map((change) => (
                            <li
                                key={change.id}
                                className="overflow-hidden rounded-xl border border-primary/25 bg-primary/5"
                            >
                                <WorkspaceRecordDestination
                                    href={change.href}
                                    access={change.workspace_access}
                                    icon={CalendarClock}
                                    iconClassName="text-primary"
                                    reference={change.reference}
                                    title={change.title}
                                />
                                <div className="space-y-1 border-t border-primary/15 px-3 py-2">
                                    <div className="flex flex-wrap gap-1.5">
                                        <ContextStatus
                                            value={change.workflow_state}
                                        />
                                        <ContextStatus
                                            value={change.risk_level}
                                        />
                                    </div>
                                    <p className="text-[11.5px] text-muted-foreground">
                                        {change.maintenance_starts_at &&
                                        change.maintenance_ends_at
                                            ? `${formatDateTime(change.maintenance_starts_at)} – ${formatDateTime(change.maintenance_ends_at)}`
                                            : change.change_type === 'emergency'
                                              ? 'Emergency execution; no planned window.'
                                              : 'Maintenance window not recorded.'}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </section>
    );
}
