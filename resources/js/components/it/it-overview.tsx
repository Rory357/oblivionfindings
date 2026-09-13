/* eslint-disable no-restricted-syntax -- The Overview dashboard mirrors the
 * gold-standard /hr/leave board: KPI stat cards and lane rows are bespoke
 * link-buttons, not shadcn <Button>/<Card> cases. Every colour is a design
 * token; deep-links reuse the queue's saved-view params. */
import { SlaChip } from '@/components/it/sla-chip';
import type { SlaVerdict } from '@/components/it/sla-evidence';
import { ticketWatcherActivity } from '@/components/it/ticket-watcher-activity';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    AlarmClock,
    CheckCircle2,
    Clock,
    Eye,
    Flag,
    Inbox,
    Play,
    Plus,
    RotateCcw,
    Timer,
    TriangleAlert,
    UserCog,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { ItOverviewBoard } from './it-overview-board';
import type {
    OverviewTicket,
    OverviewWorkboard,
} from './it-overview-workboard';
import {
    TicketConversationSummary,
    type TicketConversation,
} from './ticket-conversation-summary';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface SlaLaneRow {
    id: number;
    reference: string | null;
    title: string;
    priority: string;
    sla_state: string;
    sla?: SlaVerdict;
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
interface AwaitingItLaneRow extends Omit<AwaitingLaneRow, 'age'> {
    created_age: string | null;
    conversation?: TicketConversation;
}

interface ActivityRow {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    actor: string | null;
    ticket_id: number;
    reference: string | null;
    at: string | null;
}

export interface OverviewPayload {
    workboard?: OverviewWorkboard | null;
    conversation_ready?: boolean;
    awaiting_it_lane?: AwaitingItLaneRow[];
    avg_first_response_mins: number | null;
    sla_lane: SlaLaneRow[];
    awaiting_lane: AwaitingLaneRow[];
    aging_lane: AgingLaneRow[];
    unassigned_by_priority: Record<string, number>;
    recent_activity: ActivityRow[];
}

const priorityVariant: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const go = (href: string) =>
    router.get(
        href,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );

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
export function ItOverview(props: {
    overview: OverviewPayload;
    priority?: string | null;
    onOpenTicket: (id: number) => void;
    onAssign?: (ticket: OverviewTicket) => void;
    actorId?: number;
    canManage?: boolean;
    assignmentBusy?: boolean;
    onClearPriority?: () => void;
}) {
    if (props.overview.workboard) {
        return (
            <ItOverviewBoard
                {...props}
                board={props.overview.workboard}
                conversationReady={props.overview.conversation_ready === true}
                average={props.overview.avg_first_response_mins}
                activity={
                    <ActivityFeed
                        rows={props.overview.recent_activity.slice(0, 3)}
                        onOpenTicket={props.onOpenTicket}
                        compact
                    />
                }
            />
        );
    }
    return <LegacyOverview {...props} />;
}

/** Retain a safe presentation for older cached payloads without the bounded workboard. */
function LegacyOverview({
    overview,
    priority = null,
    onOpenTicket,
}: {
    overview: OverviewPayload;
    /** Header filter-row pill: narrow the attention lanes to one priority. */
    priority?: string | null;
    onOpenTicket: (id: number) => void;
}) {
    const byPriority = <T extends { priority: string }>(rows: T[]): T[] =>
        priority ? rows.filter((row) => row.priority === priority) : rows;
    const slaLane = byPriority(overview.sla_lane);
    const awaitingLane = byPriority(overview.awaiting_lane);
    const conversationReady =
        overview.conversation_ready === true &&
        Array.isArray(overview.awaiting_it_lane);
    const awaitingItLane = conversationReady
        ? byPriority(overview.awaiting_it_lane ?? [])
        : [];
    const agingLane = byPriority(overview.aging_lane);
    return (
        <div className="flex flex-col gap-4">
            {/* The queue KPIs (open / unassigned / breaching / breached) live
                in the header meter row — no duplicate stat grid here
                (DESIGN.md: fold key numbers into the meter row). Only the
                metric without a header home stays on the board. */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <Kpi
                    label="Avg first response · 30d"
                    value={fmtMins(overview.avg_first_response_mins)}
                />
            </div>

            {/* Needs attention lanes */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <LaneCard
                    icon={TriangleAlert}
                    title="SLA at risk / breached"
                    tone={slaLane.length > 0 ? 'critical' : 'neutral'}
                    viewHref="/it?tab=tickets&view=breaching"
                    empty="No at-risk or breached tickets in this selection. Check SLA measurement and watchdog status in the header."
                    rows={slaLane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={<SlaChip ticket={t} />}
                        />
                    )}
                />

                <LaneCard
                    icon={Inbox}
                    title="Awaiting first reply"
                    tone={awaitingLane.length > 0 ? 'warning' : 'neutral'}
                    viewHref="/it?tab=tickets&view=awaiting_reply"
                    empty="No tickets are waiting on a first response."
                    rows={awaitingLane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={
                                <span className="text-[11.5px] text-muted-foreground">
                                    Ticket age: {t.age ?? 'unavailable'}
                                </span>
                            }
                        />
                    )}
                />

                {conversationReady ? (
                    <LaneCard
                        icon={Inbox}
                        title="Awaiting IT"
                        tone={awaitingItLane.length > 0 ? 'warning' : 'neutral'}
                        viewHref="/it?tab=tickets&view=awaiting_it"
                        empty="No open tickets in this selection have a recorded next public response with IT."
                        rows={awaitingItLane}
                        render={(ticket) => (
                            <LaneRow
                                key={ticket.id}
                                reference={ticket.reference}
                                title={ticket.title}
                                priority={ticket.priority}
                                onClick={() => onOpenTicket(ticket.id)}
                                meta={
                                    <span className="text-caption block">
                                        <TicketConversationSummary
                                            conversation={ticket.conversation}
                                            ready
                                        />
                                        <span className="block">
                                            Ticket age:{' '}
                                            {ticket.created_age ??
                                                'unavailable'}
                                        </span>
                                    </span>
                                }
                            />
                        )}
                    />
                ) : (
                    <div className="rounded-2xl border border-border bg-card p-4">
                        <p className="text-[13px] font-bold">
                            Current public reply responsibility
                        </p>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Reply responsibility is unavailable. The first-reply
                            view remains available.
                        </p>
                    </div>
                )}

                <LaneCard
                    icon={Clock}
                    title="Aging · open >7 days"
                    tone={agingLane.length > 0 ? 'warning' : 'neutral'}
                    viewHref="/it?tab=tickets&view=all_open&sort=created&dir=asc"
                    empty="Nothing has been sitting open for more than a week."
                    rows={agingLane}
                    render={(t) => (
                        <LaneRow
                            key={t.id}
                            reference={t.reference}
                            title={t.title}
                            priority={t.priority}
                            onClick={() => onOpenTicket(t.id)}
                            meta={
                                <span className="text-[11.5px] text-muted-foreground">
                                    {t.assignee ?? 'Unassigned'} ·{' '}
                                    {t.age ?? '—'}
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
                        <span className="text-[13px] font-bold">
                            Unassigned by priority
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {(['urgent', 'high', 'normal', 'low'] as const).map(
                            (pri) => {
                                const n =
                                    overview.unassigned_by_priority[pri] ?? 0;
                                return (
                                    <button
                                        key={pri}
                                        type="button"
                                        onClick={() =>
                                            go(
                                                `/it?tab=tickets&view=unassigned&ticket_priority=${pri}`,
                                            )
                                        }
                                        className="flex flex-col items-start gap-1 rounded-xl border border-border/70 px-3 py-2.5 text-left transition-colors hover:border-primary/50 hover:bg-muted/40"
                                    >
                                        <span className="text-[20px] leading-none font-bold tabular-nums">
                                            {n}
                                        </span>
                                        <StatusBadge
                                            variant={
                                                priorityVariant[pri] ??
                                                'neutral'
                                            }
                                            size="sm"
                                        >
                                            {label(pri)}
                                        </StatusBadge>
                                    </button>
                                );
                            },
                        )}
                    </div>
                </div>
            </div>

            <ActivityFeed
                rows={overview.recent_activity}
                onOpenTicket={onOpenTicket}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Recent activity                                                   */
/* ------------------------------------------------------------------ */

const ACTIVITY_ICON: Record<string, LucideIcon> = {
    created: Plus,
    assigned: UserCog,
    status_changed: Play,
    priority_changed: Flag,
    sla_at_risk: TriangleAlert,
    sla_breached: TriangleAlert,
    sla_escalated: TriangleAlert,
    resolved: CheckCircle2,
    closed: XCircle,
    reopened: RotateCcw,
    watcher_added: Eye,
    watcher_removed: Eye,
};

/** Compact past-tense phrase for the feed line (payload-aware where it helps). */
function activityVerb(
    type: string,
    payload: Record<string, unknown> | null,
): string {
    const to =
        payload && typeof payload.to === 'string' ? label(payload.to) : null;
    switch (type) {
        case 'created':
            return 'raised this ticket';
        case 'assigned':
            return 'reassigned this ticket';
        case 'status_changed':
            return to ? `moved it to ${to}` : 'changed the status';
        case 'priority_changed':
            return to ? `set priority ${to}` : 'changed the priority';
        case 'sla_at_risk':
            return 'flagged SLA at risk';
        case 'sla_breached':
            return 'SLA breached';
        case 'sla_escalated':
            return 'escalated to admins';
        case 'resolved':
            return 'resolved this ticket';
        case 'closed':
            return 'closed this ticket';
        case 'reopened':
            return 'reopened this ticket';
        case 'watcher_added':
        case 'watcher_removed':
            return ticketWatcherActivity(type, payload);
        default:
            return label(type).toLowerCase();
    }
}

function ActivityFeed({
    rows,
    onOpenTicket,
    compact = false,
}: {
    rows: ActivityRow[];
    onOpenTicket: (id: number) => void;
    compact?: boolean;
}) {
    return (
        <div
            className={
                compact
                    ? 'border-t border-border pt-4'
                    : 'rounded-2xl border border-border bg-card p-4'
            }
        >
            <div className="mb-2 flex items-center gap-2">
                <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                    <Activity className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-bold">Recent activity</span>
            </div>
            {rows.length === 0 ? (
                <p className="px-1 py-4 text-center text-[12px] text-muted-foreground">
                    No ticket activity yet — it lands here as agents work the
                    queue.
                </p>
            ) : (
                <div
                    className={
                        compact ? 'grid gap-4 lg:grid-cols-3' : 'flex flex-col'
                    }
                >
                    {rows.map((row) => {
                        const Icon = ACTIVITY_ICON[row.type] ?? Timer;
                        return (
                            <button
                                key={row.id}
                                type="button"
                                onClick={() => onOpenTicket(row.ticket_id)}
                                className="flex min-w-0 items-center gap-2.5 border-b border-border/55 py-2 text-left last:border-0 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="grid h-6 w-6 flex-none place-items-center rounded-md bg-muted text-muted-foreground">
                                    <Icon className="h-3 w-3" />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[12.5px]">
                                    <span className="font-semibold">
                                        {row.actor ?? 'System'}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {activityVerb(row.type, row.payload)}
                                    </span>
                                    {row.reference ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {row.reference}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="flex-none text-[11px] text-muted-foreground">
                                    {row.at ?? ''}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
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
            <span className="mt-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {lbl}
            </span>
        </>
    );
    if (!href) {
        return (
            <div className="flex flex-col items-start rounded-2xl border border-border bg-card px-4 py-3.5">
                {inner}
            </div>
        );
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
                <span
                    className={`grid h-7 w-7 flex-none place-items-center rounded-lg ${iconTone}`}
                >
                    <Icon className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-bold">{title}</span>
                {rows.length > 0 ? (
                    <Link
                        href={viewHref}
                        aria-label={`View all ${title.toLowerCase()}`}
                        className="ml-auto text-[11.5px] font-semibold text-primary hover:underline"
                    >
                        View all →
                    </Link>
                ) : null}
            </div>
            {rows.length === 0 ? (
                <p className="px-1 py-4 text-center text-[12px] text-muted-foreground">
                    {empty}
                </p>
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
                <span className="block truncate text-[12.5px] font-semibold">
                    {title}
                </span>
                {reference ? (
                    <span className="block truncate text-[11px] text-muted-foreground">
                        {reference}
                    </span>
                ) : null}
            </span>
            <StatusBadge
                variant={priorityVariant[priority] ?? 'neutral'}
                size="sm"
            >
                {label(priority)}
            </StatusBadge>
            <span className="flex-none">{meta}</span>
        </button>
    );
}

export default ItOverview;
