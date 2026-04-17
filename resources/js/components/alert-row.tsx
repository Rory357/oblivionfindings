import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Calendar,
    ChevronRight,
    ClipboardList,
    Clock,
    MoreHorizontal,
    User,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Frontline alert row (PR 17 — Alerts/actions model refactor)               */
/* -------------------------------------------------------------------------- */
/*
 * A single actionable frontline work item. Used on `/my-day` to render the
 * Open Items list; reusable for any future frontline surface that needs to
 * show alerts, follow-ups, incidents, or shift items as operational work.
 *
 * Design goals driven by the PR brief:
 *   - urgency visible in more than just colour (icon + text badge)
 *   - one obvious main action (Open) as a tap target, not a chevron
 *   - quick secondary actions only where they're safe for the item type
 *   - mobile-first: comfortable tap targets, no hover-only UI, stacks at 375px
 *   - no dense metadata; type + title + one-line context + urgency + action
 *
 * The row intentionally owns zero backend state. It posts to small frontline
 * endpoints (acknowledgeAlert / snoozeAlert) and lets the Inertia reload
 * refresh the list. No optimistic UI, no in-row mutation state — the action
 * moves the item off the list and that's the feedback.
 */

export type AlertRowKind =
    | 'alert'
    | 'incident'
    | 'followup'
    | 'note_followup'
    | 'shift';

export type AlertRowPriority = 'critical' | 'high' | 'medium' | 'low' | string;

export interface AlertRowItem {
    id: string;
    type: AlertRowKind;
    title: string;
    priority: AlertRowPriority;
    client_name?: string | null;
    url: string;
    time: string;
    due_at?: string | null;
    sla_status?: string | null;
    /** Numeric id of the underlying ControlRoomAlert for ack/snooze actions. */
    alert_id?: number | null;
    can_ack?: boolean;
    can_snooze?: boolean;
    /** Optional pre-composed status pill element, owned by the page. */
    statusChip?: React.ReactNode;
}

const TYPE_ICON: Record<AlertRowKind, LucideIcon> = {
    alert: Bell,
    incident: AlertTriangle,
    followup: ClipboardList,
    note_followup: ClipboardList,
    shift: Calendar,
};

const TYPE_LABEL: Record<AlertRowKind, string> = {
    alert: 'Alert',
    incident: 'Incident',
    followup: 'Follow-up',
    note_followup: 'Follow-up',
    shift: 'Shift',
};

/**
 * Per-type ring + icon tone. Colour only reinforces; the icon + TYPE_LABEL
 * pill carry the meaning on their own for anyone who can't rely on hue.
 */
const TYPE_TONE: Record<
    AlertRowKind,
    { ring: string; iconBg: string; iconFg: string }
> = {
    alert: {
        ring: 'border-border',
        iconBg: 'bg-amber-100 dark:bg-amber-500/15',
        iconFg: 'text-amber-700 dark:text-amber-300',
    },
    incident: {
        ring: 'border-border',
        iconBg: 'bg-red-100 dark:bg-red-500/15',
        iconFg: 'text-red-700 dark:text-red-300',
    },
    followup: {
        ring: 'border-border',
        iconBg: 'bg-sky-100 dark:bg-sky-500/15',
        iconFg: 'text-sky-700 dark:text-sky-300',
    },
    note_followup: {
        ring: 'border-border',
        iconBg: 'bg-sky-100 dark:bg-sky-500/15',
        iconFg: 'text-sky-700 dark:text-sky-300',
    },
    shift: {
        ring: 'border-border',
        iconBg: 'bg-muted',
        iconFg: 'text-muted-foreground',
    },
};

/**
 * Urgency pill. Renders a short label AND an icon/shape cue so colour alone
 * never decides prioritisation. "Due now" wins over "Due in 12m" wins over
 * nothing — the label is the signal.
 */
function UrgencyBadge({
    priority,
    dueAt,
    slaStatus,
}: {
    priority: AlertRowPriority;
    dueAt?: string | null;
    slaStatus?: string | null;
}) {
    const { label, className } = resolveUrgency(priority, dueAt, slaStatus);
    if (!label) return null;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                className,
            )}
        >
            <span
                aria-hidden
                className="h-1.5 w-1.5 rounded-full bg-current opacity-80"
            />
            {label}
        </span>
    );
}

function resolveUrgency(
    priority: AlertRowPriority,
    dueAt?: string | null,
    slaStatus?: string | null,
): { label: string | null; className: string } {
    if (slaStatus === 'breached') {
        return {
            label: 'Breached',
            className:
                'border-red-300 bg-red-100 text-red-800 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200',
        };
    }

    if (dueAt) {
        const diffMs = new Date(dueAt).getTime() - Date.now();
        if (diffMs <= 0) {
            return {
                label: 'Due now',
                className:
                    'border-red-300 bg-red-100 text-red-800 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200',
            };
        }
        const mins = Math.floor(diffMs / 60000);
        if (mins <= 60) {
            return {
                label: `Due in ${mins}m`,
                className:
                    'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100',
            };
        }
    }

    if (slaStatus === 'at_risk') {
        return {
            label: 'At risk',
            className:
                'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100',
        };
    }

    if (priority === 'critical') {
        return {
            label: 'Critical',
            className:
                'border-red-300 bg-red-100 text-red-800 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200',
        };
    }

    if (priority === 'high') {
        return {
            label: 'High',
            className:
                'border-orange-300 bg-orange-100 text-orange-900 dark:border-orange-500/40 dark:bg-orange-500/15 dark:text-orange-100',
        };
    }

    return { label: null, className: '' };
}

/* -------------------------------------------------------------------------- */
/*  Snooze sheet — compact popover over the row                               */
/* -------------------------------------------------------------------------- */

function SnoozeMenu({
    alertId,
    onDone,
}: {
    alertId: number;
    onDone: () => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const onClick = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                onDone();
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onDone();
        };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [onDone]);

    const snooze = (window: '15m' | '1h' | 'shift') => {
        router.post(
            `/my-day/alerts/${alertId}/snooze`,
            { window },
            { preserveScroll: true, onFinish: onDone },
        );
    };

    return (
        <div
            ref={ref}
            role="menu"
            aria-label="Snooze alert"
            className="absolute right-0 top-full z-10 mt-1 w-48 overflow-hidden rounded-lg border bg-popover shadow-md"
        >
            <button
                type="button"
                onClick={() => snooze('15m')}
                className="frontline-focus flex w-full min-h-11 items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-muted"
            >
                Snooze 15m
                <Clock aria-hidden className="h-3.5 w-3.5 text-muted-foreground" />
            </button>
            <button
                type="button"
                onClick={() => snooze('1h')}
                className="frontline-focus flex w-full min-h-11 items-center justify-between border-t px-3 py-2.5 text-left text-sm hover:bg-muted"
            >
                Snooze 1h
                <Clock aria-hidden className="h-3.5 w-3.5 text-muted-foreground" />
            </button>
            <button
                type="button"
                onClick={() => snooze('shift')}
                className="frontline-focus flex w-full min-h-11 items-center justify-between border-t px-3 py-2.5 text-left text-sm hover:bg-muted"
            >
                Until end of shift
                <Clock aria-hidden className="h-3.5 w-3.5 text-muted-foreground" />
            </button>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Row                                                                       */
/* -------------------------------------------------------------------------- */

export default function AlertRow({ item }: { item: AlertRowItem }) {
    const [snoozeOpen, setSnoozeOpen] = useState(false);

    const TypeIcon = TYPE_ICON[item.type] ?? Bell;
    const typeLabel = TYPE_LABEL[item.type] ?? 'Item';
    const tone = TYPE_TONE[item.type] ?? TYPE_TONE.alert;

    const isAlert = item.type === 'alert' && !!item.alert_id;
    const canAck = isAlert && !!item.can_ack;
    const canSnooze = isAlert && !!item.can_snooze;
    const hasQuickActions = canAck || canSnooze;

    const ack = () => {
        if (!item.alert_id) return;
        router.post(
            `/my-day/alerts/${item.alert_id}/ack`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <li
            className={cn(
                'relative rounded-lg border bg-card transition-shadow hover:shadow-sm',
                tone.ring,
            )}
        >
            {/* Top row — icon + title + urgency + chevron affordance.
                The whole top row is one big tap target that opens the item. */}
            <Link
                href={item.url}
                aria-label={`Open ${typeLabel.toLowerCase()}: ${item.title}`}
                className="frontline-focus group flex items-start gap-3 px-3 pb-2 pt-3"
            >
                <div
                    className={cn(
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                        tone.iconBg,
                    )}
                >
                    <TypeIcon className={cn('h-4 w-4', tone.iconFg)} />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="inline-flex shrink-0 items-center rounded-md border bg-muted/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {typeLabel}
                        </span>
                        <UrgencyBadge
                            priority={item.priority}
                            dueAt={item.due_at}
                            slaStatus={item.sla_status}
                        />
                        {item.statusChip}
                    </div>
                    <p className="mt-1 line-clamp-2 text-sm font-medium leading-snug group-hover:text-primary">
                        {item.title}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                        {item.client_name ? (
                            <span className="flex items-center gap-1">
                                <User className="h-3 w-3" />
                                {item.client_name}
                            </span>
                        ) : null}
                        <span className="flex items-center gap-1">
                            <Clock className="h-3 w-3" />
                            {formatRelative(item.time)}
                        </span>
                    </div>
                </div>

                <ChevronRight
                    aria-hidden
                    className="mt-1 h-4 w-4 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5"
                />
            </Link>

            {/* Action row — always renders Open as the primary tap target so
                the worker never has to decode a chevron. Ack/Snooze appear
                only for alert-type items where the action is safe. */}
            <div className="flex items-center justify-end gap-2 border-t bg-muted/30 px-2 py-1.5">
                {canSnooze && item.alert_id ? (
                    <div className="relative">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setSnoozeOpen((v) => !v)}
                            aria-expanded={snoozeOpen}
                            aria-haspopup="menu"
                            aria-label="Snooze this alert"
                            className="min-h-11 gap-1 px-3 text-sm"
                        >
                            <Clock aria-hidden className="h-4 w-4" />
                            Snooze
                            <MoreHorizontal aria-hidden className="h-3.5 w-3.5 opacity-60" />
                        </Button>
                        {snoozeOpen ? (
                            <SnoozeMenu
                                alertId={item.alert_id}
                                onDone={() => setSnoozeOpen(false)}
                            />
                        ) : null}
                    </div>
                ) : null}
                {canAck ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={ack}
                        aria-label={`Acknowledge ${item.title}`}
                        className="min-h-11 px-3 text-sm"
                    >
                        Acknowledge
                    </Button>
                ) : null}
                <Button
                    type="button"
                    asChild
                    size="sm"
                    variant={hasQuickActions ? 'default' : 'outline'}
                    className="min-h-11 px-4 text-sm"
                >
                    <Link href={item.url} aria-label={`Open ${item.title}`}>
                        Open
                    </Link>
                </Button>
            </div>
        </li>
    );
}
