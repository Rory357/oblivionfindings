import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    AlertTriangle,
    BookOpenCheck,
    CheckCircle2,
    CircleAlert,
    CircleHelp,
    ExternalLink,
    Link2,
    Server,
    ShieldAlert,
} from 'lucide-react';

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
    href: string;
    ticket_href: string;
}

interface Props {
    recoveredAt: string | null;
    devices: TicketLinkedDevice[];
    alerts: TicketLinkedAlert[];
    problems?: TicketLinkedProblem[];
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
            return { variant: 'success', icon: CheckCircle2 };
        case 'degraded':
        case 'warning':
        case 'medium':
        case 'waiting':
            return { variant: 'warning', icon: AlertTriangle };
        case 'critical':
        case 'failed':
        case 'high':
        case 'offline':
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

export function TicketLinkedContext({
    recoveredAt,
    devices,
    alerts,
    problems = [],
}: Props) {
    const hasLinks =
        devices.length > 0 || alerts.length > 0 || problems.length > 0;

    return (
        <section
            aria-labelledby="ticket-monitoring-context"
            className="border-t border-border/60 pt-3"
        >
            <div className="flex items-center gap-2">
                <Link2
                    aria-hidden="true"
                    className="h-3.5 w-3.5 text-muted-foreground"
                />
                <h2
                    id="ticket-monitoring-context"
                    className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                >
                    Monitoring context
                </h2>
            </div>

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

            {!hasLinks ? (
                <div className="mt-2 rounded-xl border border-dashed border-border px-3 py-3 text-center">
                    <Link2
                        aria-hidden="true"
                        className="mx-auto h-4 w-4 text-muted-foreground"
                    />
                    <p className="mt-1 text-[12px] text-muted-foreground">
                        No linked monitoring records are visible.
                    </p>
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
                                    <span className="ml-auto text-[10.5px] text-muted-foreground">
                                        Last seen{' '}
                                        {formatDateTime(device.last_seen_at)}
                                    </span>
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
                                <Link
                                    href={problem.href}
                                    className="frontline-focus flex min-h-11 items-start gap-2.5 px-3 py-2.5 hover:bg-muted/40"
                                >
                                    <BookOpenCheck
                                        aria-hidden="true"
                                        className="mt-0.5 h-4 w-4 flex-none text-status-warning"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="font-mono text-[12px] font-bold">
                                                {problem.reference}
                                            </span>
                                            <ExternalLink
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5 text-muted-foreground"
                                            />
                                        </span>
                                        <span className="mt-0.5 block truncate text-[11.5px] font-medium">
                                            {problem.title}
                                        </span>
                                    </span>
                                </Link>
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
        </section>
    );
}
