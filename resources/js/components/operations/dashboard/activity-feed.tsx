import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BadgeCheck,
    CalendarX,
    Check,
    ClipboardCheck,
    FileText,
    Filter,
    MessageSquare,
    Receipt,
    Shield,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';

import { PulseDot } from './hover-popover';
import type { ActivityItem } from './types';
import { Card as GuardrailCard } from '@/components/ui/card';

type TypeStyle = {
    icon: LucideIcon;
    bg: string;
    fg: string;
    text: (item: ActivityItem) => React.ReactNode;
    detail: (item: ActivityItem) => string;
};

const TYPE_STYLES: Record<string, TypeStyle> = {
    shift: {
        icon: Check,
        bg: 'var(--status-success-bg)',
        fg: 'var(--status-success)',
        text: (item) => (
            <span>
                <span className="font-semibold">{item.staff ?? 'Staff'}</span>
                {item.status === 'in_progress'
                    ? ' clocked in'
                    : item.status === 'completed'
                    ? ' completed shift'
                    : ` ${item.status?.replace(/_/g, ' ')}`}
                {item.client ? (
                    <>
                        {' '}for <span className="font-medium">{item.client}</span>
                    </>
                ) : null}
            </span>
        ),
        detail: (item) => `Shift · ${item.status?.replace(/_/g, ' ') ?? 'updated'}`,
    },
    timesheet: {
        icon: ClipboardCheck,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: (item) => (
            <span>
                <span className="font-semibold">Timesheet</span>{' '}
                {item.status === 'submitted' ? 'submitted by' : item.status} {' '}
                <span className="font-medium">{item.staff ?? '—'}</span>
            </span>
        ),
        detail: (item) =>
            item.work_date ? `Work date ${item.work_date} · awaiting approval` : 'Timesheet update',
    },
    incident: {
        icon: AlertTriangle,
        bg: 'var(--status-critical-bg)',
        fg: 'var(--status-critical)',
        text: (item) => (
            <span>
                <span className="font-semibold">Incident reported</span>{' '}
                {item.incident_type ? `· ${item.incident_type}` : null}
            </span>
        ),
        detail: (item) =>
            `${item.client ?? 'Client'} · severity ${item.severity ?? 'unknown'}`,
    },
    client: {
        icon: UserPlus,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: (item) => (
            <span>
                New client{' '}
                <span className="font-semibold">{item.client ?? '—'}</span> moved to{' '}
                <span className="font-medium capitalize">{item.status?.replace(/_/g, ' ')}</span>
            </span>
        ),
        detail: () => 'Onboarding milestone',
    },
    client_state_change: {
        icon: UserPlus,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: (item) => <span>Client state change · {item.client ?? '—'}</span>,
        detail: (item) => item.status?.replace(/_/g, ' ') ?? 'updated',
    },
    cancellation: {
        icon: CalendarX,
        bg: 'var(--status-warning-bg)',
        fg: 'var(--status-warning)',
        text: (item) => (
            <span>
                <span className="font-semibold">Shift cancelled</span>{' '}
                {item.client ? <>by client ({item.client})</> : null}
            </span>
        ),
        detail: () => 'Re-roster pending',
    },
    roster_published: {
        icon: BadgeCheck,
        bg: 'var(--status-success-bg)',
        fg: 'var(--status-success)',
        text: (item) => (
            <span>
                Roster <span className="font-semibold">published</span> for {item.title ?? '—'}
            </span>
        ),
        detail: () => 'All assigned',
    },
    progress_note: {
        icon: FileText,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: (item) => (
            <span>
                <span className="font-semibold">Progress note</span> filed for{' '}
                <span className="font-medium">{item.client ?? '—'}</span>
            </span>
        ),
        detail: (item) => `${item.staff ?? 'Staff'} · routine PRN log`,
    },
    compliance_expiry: {
        icon: Shield,
        bg: 'var(--status-warning-bg)',
        fg: 'var(--status-warning)',
        text: (item) => (
            <span>
                <span className="font-semibold">Compliance expiring</span> · {item.staff ?? '—'}
            </span>
        ),
        detail: () => 'Auto-reminder sent · compliance impact: low',
    },
    handover: {
        icon: MessageSquare,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: (item) => (
            <span>
                <span className="font-semibold">Handover</span> · {item.title ?? 'shift'}
            </span>
        ),
        detail: () => 'All notes complete',
    },
    invoice_run: {
        icon: Receipt,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: () => (
            <span>
                <span className="font-semibold">Invoice run</span> exported
            </span>
        ),
        detail: () => 'May fortnight · awaiting send',
    },
};

function fallback(item: ActivityItem): TypeStyle {
    return TYPE_STYLES[item.type] ?? {
        icon: Check,
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        text: () => <span className="font-medium capitalize">{item.type.replace(/_/g, ' ')}</span>,
        detail: () => item.status ?? '',
    };
}

function formatRelative(iso?: string): string {
    if (!iso) return '';
    const d = new Date(iso);
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return `${diff}s`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return `${Math.floor(diff / 86400)}d`;
}

type Props = {
    items: ActivityItem[];
    totalEventsToday?: number;
};

export function ActivityFeed({ items, totalEventsToday }: Props) {
    return (
        <GuardrailCard unstyled
            className="flex flex-col rounded-xl border bg-card lg:col-span-2"
            style={{ borderColor: 'var(--border)' }}
        >
            <div
                className="flex items-center justify-between border-b px-4 py-3"
                style={{ borderColor: 'var(--border)' }}
            >
                <div className="flex items-center gap-2">
                    <h3 className="text-[14px] font-semibold">Live activity</h3>
                    <span
                        className="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px]"
                        style={{
                            background: 'var(--status-success-bg)',
                            color: 'var(--status-success)',
                        }}
                    >
                        <PulseDot className="h-1.5 w-1.5" /> Live
                    </span>
                </div>
                <Link
                    href="/operations/activity"
                    className="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary"
                >
                    View all <ArrowRight className="h-3 w-3" />
                </Link>
            </div>
            <div className="max-h-[560px] flex-1 overflow-y-auto">
                {items.length === 0 ? (
                    <div className="py-8 text-center text-[12px] text-muted-foreground">
                        No recent activity.
                    </div>
                ) : (
                    <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                        {items.slice(0, 10).map((item) => {
                            const style = fallback(item);
                            const Icon = style.icon;
                            return (
                                <li
                                    key={item.id}
                                    className={cn(
                                        'flex items-start gap-3 px-4 py-2.5 hover:bg-muted/50',
                                    )}
                                >
                                    <div
                                        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                                        style={{ background: style.bg, color: style.fg }}
                                    >
                                        <Icon className="h-3.5 w-3.5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[12px]">{style.text(item)}</div>
                                        <div className="truncate text-[10.5px] text-muted-foreground">
                                            {style.detail(item)}
                                        </div>
                                    </div>
                                    <span className="shrink-0 text-[10px] tabular-nums text-muted-foreground">
                                        {formatRelative(item.updated_at)}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
            <div
                className="flex items-center justify-between border-t px-4 py-2"
                style={{ borderColor: 'var(--border)' }}
            >
                <div className="text-[10.5px] text-muted-foreground">
                    Showing last {Math.min(10, items.length)} of {totalEventsToday ?? items.length} events today
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                >
                    <Filter className="h-3 w-3" /> Filter
                </button>
            </div>
        </GuardrailCard>
    );
}
