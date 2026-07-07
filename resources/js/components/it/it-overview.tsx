/* eslint-disable no-restricted-syntax -- The Overview dashboard mirrors the
 * gold-standard /hr/leave board: KPI stat cards and lane rows are bespoke
 * link-buttons, not shadcn <Button>/<Card> cases. Every colour is a design
 * token; deep-links reuse the queue's saved-view params. */
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import {
    AlarmClock,
    Clock,
    Inbox,
    Timer,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface SlaLaneRow {
    id: number;
    reference: string | null;
    title: string;
    priority: string;
    sla_state: string;
    resolution_due_at: string | null;
    assignee: string | null;
}
interface AwaitingLaneRow {
    id: number;
    reference: string | null;
    title: string;
    priority: string;
    requester: string;
    age: string | null;
}
interface AgingLaneRow {
    id: number;
    reference: string | null;
    title: string;
    priority: string;
    assignee: string | null;
    age: string | null;
}

export interface OverviewPayload {
    avg_first_response_mins: number | null;
    sla_lane: SlaLaneRow[];
    awaiting_lane: AwaitingLaneRow[];
    aging_lane: AgingLaneRow[];
    unassigned_by_priority: Record<string, number>;
}

interface OverviewKpis {
    open: number;
    unassigned: number;
    at_risk: number;
    breached: number;
}

const priorityVariant: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};

const label = (raw: string) => raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const go = (href: string) => router.get(href, {}, { preserveState: true, preserveScroll: true, replace: true });

/** Minutes → compact en-NZ duration ("2h 14m", "45m", "—"). */
function fmtMins(m: number | null): string {
    if (m === null) return '—';
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    const mm = m % 60;
    return mm ? `${h}h ${mm}m` : `${h}h`;
}

/* ------------------------------------------------------------------ */
/*  Board                                                             */
/* ------------------------------------------------------------------ */

/**
 * §F1 Overview — the agent command board. A KPI row (each card deep-links into
 * the filtered queue) over four "needs attention" lanes: SLA at-risk/breached,
 * awaiting agent reply, aging, and unassigned-by-priority. Clicking a lane row
 * quick-peeks the ticket; "View all" jumps to the matching saved view.
 */
export function ItOverview({
    overview,
    kpis,
    onOpenTicket,
}: {
    overview: OverviewPayload;
    kpis: OverviewKpis;
    onOpenTicket: (id: number) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            {/* KPI row */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <Kpi label="Open" value={kpis.open} href="/it?tab=tickets&view=all_open" />
                <Kpi label="Unassigned" value={kpis.unassigned} href="/it?tab=tickets&view=unassigned" />
                <Kpi label="Breaching soon" value={kpis.at_risk} href="/it?tab=tickets&view=breaching" amber={kpis.at_risk > 0} />
                <Kpi label="Breached" value={kpis.breached} href="/it?tab=tickets&view=breached" critical={kpis.breached > 0} />
                <Kpi label="Avg first response · 30d" value={fmtMins(overview.avg_first_response_mins)} />
            </div>

            {/* Needs attention lanes */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <LaneCard
                    icon={TriangleAlert}
                    title="SLA at risk / breached"
                    tone={overview.sla_lane.length > 0 ? 'critical' : 'neutral'}
                    viewHref="/it?tab=tickets&view=breaching"
                    empty="Every open ticket is comfortably within SLA."
                    rows={overview.sla_lane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={
                                <StatusBadge variant={t.sla_state === 'breached' ? 'critical' : 'warning'} size="sm">
                                    {t.sla_state === 'breached' ? 'Breached' : 'At risk'}
                                </StatusBadge>
                            }
                        />
                    )}
                />

                <LaneCard
                    icon={Inbox}
                    title="Awaiting agent reply"
                    tone={overview.awaiting_lane.length > 0 ? 'warning' : 'neutral'}
                    viewHref="/it?tab=tickets&view=awaiting_reply"
                    empty="No tickets are waiting on a first response."
                    rows={overview.awaiting_lane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={<span className="text-[11.5px] text-muted-foreground">{t.age ?? '—'}</span>}
                        />
                    )}
                />

                <LaneCard
                    icon={Clock}
                    title="Aging · open >7 days"
                    tone={overview.aging_lane.length > 0 ? 'warning' : 'neutral'}
                    viewHref="/it?tab=tickets&view=all_open&sort=created&dir=asc"
                    empty="Nothing has been sitting open for more than a week."
                    rows={overview.aging_lane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={
                                <span className="text-[11.5px] text-muted-foreground">
                                    {t.assignee ?? 'Unassigned'} · {t.age ?? '—'}
                                </span>
                            }
                        />
                    )}
                />

                {/* Unassigned by priority */}
                <div className="rounded-2xl border border-border bg-card p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                            <AlarmClock className="h-3.5 w-3.5" />
                        </span>
                        <span className="text-[13px] font-bold">Unassigned by priority</span>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {(['urgent', 'high', 'normal', 'low'] as const).map((pri) => {
                            const n = overview.unassigned_by_priority[pri] ?? 0;
                            return (
                                <button
                                    key={pri}
                                    type="button"
                                    onClick={() => go(`/it?tab=tickets&view=unassigned&ticket_priority=${pri}`)}
                                    className="flex flex-col items-start gap-1 rounded-xl border border-border/70 px-3 py-2.5 text-left transition-colors hover:border-primary/50 hover:bg-muted/40"
                                >
                                    <span className="text-[20px] leading-none font-bold tabular-nums">{n}</span>
                                    <StatusBadge variant={priorityVariant[pri] ?? 'neutral'} size="sm">
                                        {label(pri)}
                                    </StatusBadge>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

function Kpi({
    label: lbl,
    value,
    href,
    amber,
    critical,
}: {
    label: string;
    value: string | number;
    href?: string;
    amber?: boolean;
    critical?: boolean;
}) {
    const valueClass = critical
        ? 'text-[26px] leading-none font-bold tabular-nums text-[color:var(--status-critical)]'
        : amber
          ? 'text-[26px] leading-none font-bold tabular-nums text-[color:var(--status-warning)]'
          : 'text-[26px] leading-none font-bold tabular-nums';
    const inner = (
        <>
            <span className={valueClass}>{value}</span>
            <span className="mt-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{lbl}</span>
        </>
    );
    if (!href) {
        return <div className="flex flex-col items-start rounded-2xl border border-border bg-card px-4 py-3.5">{inner}</div>;
    }
    return (
        <button
            type="button"
            onClick={() => go(href)}
            className="flex flex-col items-start rounded-2xl border border-border bg-card px-4 py-3.5 text-left transition-colors hover:border-primary/50 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {inner}
        </button>
    );
}

function LaneCard<T>({
    icon: Icon,
    title,
    tone,
    viewHref,
    empty,
    rows,
    render,
}: {
    icon: LucideIcon;
    title: string;
    tone: 'critical' | 'warning' | 'neutral';
    viewHref: string;
    empty: string;
    rows: T[];
    render: (row: T) => React.ReactNode;
}) {
    const iconTone =
        tone === 'critical'
            ? 'bg-[color:var(--status-critical)]/12 text-[color:var(--status-critical)]'
            : tone === 'warning'
              ? 'bg-[color:var(--status-warning)]/12 text-[color:var(--status-warning)]'
              : 'bg-accent text-primary';
    return (
        <div className="rounded-2xl border border-border bg-card p-4">
            <div className="mb-2 flex items-center gap-2">
                <span className={`grid h-7 w-7 flex-none place-items-center rounded-lg ${iconTone}`}>
                    <Icon className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-bold">{title}</span>
                {rows.length > 0 ? (
                    <button
                        type="button"
                        onClick={() => go(viewHref)}
                        className="ml-auto text-[11.5px] font-semibold text-primary hover:underline"
                    >
                        View all →
                    </button>
                ) : null}
            </div>
            {rows.length === 0 ? (
                <p className="px-1 py-4 text-center text-[12px] text-muted-foreground">{empty}</p>
            ) : (
                <div className="flex flex-col">{rows.map(render)}</div>
            )}
        </div>
    );
}

function LaneRow({
    reference,
    title,
    priority,
    meta,
    onClick,
}: {
    reference: string | null;
    title: string;
    priority: string;
    meta: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex items-center gap-2 border-b border-border/55 py-2 text-left last:border-0 hover:bg-muted/40"
        >
            <Timer className="h-3.5 w-3.5 flex-none text-muted-foreground" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[12.5px] font-semibold">{title}</span>
                {reference ? <span className="block truncate text-[11px] text-muted-foreground">{reference}</span> : null}
            </span>
            <StatusBadge variant={priorityVariant[priority] ?? 'neutral'} size="sm">
                {label(priority)}
            </StatusBadge>
            <span className="flex-none">{meta}</span>
        </button>
    );
}

export default ItOverview;
