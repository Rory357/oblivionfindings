/* H&S corrective-actions register — sibling view for the events register.
 * Rows open the parent H&S event detail modal on the corrective-actions pane,
 * so the register stays cross-linked to the governance workspace. */
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    HeroShell,
    HeroStatusPill,
    HeroMedallion,
    HeroCluster,
    HeroClusterTile,
    HeroSegmented,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    EventDetailDialog,
    EVENT_CATEGORY_LABELS,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Calendar,
    CheckCircle2,
    Clock,
    Eye,
    LayoutList,
    Link2,
    ListChecks,
    Search,
    Shield,
    ShieldCheck,
    UserRound,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

type ActionRow = {
    id: number;
    reference_number: string;
    title: string;
    action_type: string;
    priority: string;
    status: string;
    assigned_to_name: string | null;
    due_date: string | null;
    is_overdue: boolean;
    completed_at: string | null;
    verified_at: string | null;
    event: {
        id: number;
        reference_number: string;
        event_category: string;
        severity: string;
        status: string;
        site_name: string | null;
        url: string;
        monitoring: boolean;
    } | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Filters = {
    q: string | null;
    tab: string;
    priority: string | null;
    site_id: number | null;
    from: string | null;
    to: string | null;
};

type Props = {
    actions: Paginated<ActionRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: {
            open: number;
            in_progress: number;
            awaiting_verification: number;
            verified: number;
        };
        attention: {
            overdue: number;
            critical_open: number;
            unassigned: number;
            monitoring_events: number;
        };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean };
};

const PRI: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const ACTION_STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    open: { label: 'Open', cls: 'bg-status-info-bg text-status-info', icon: ListChecks },
    in_progress: { label: 'In progress', cls: 'bg-primary/10 text-primary', icon: Activity },
    completed: { label: 'Awaiting verification', cls: 'bg-status-warning-bg text-status-warning', icon: ShieldCheck },
    verified: { label: 'Verified', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
    closed: { label: 'Closed', cls: 'bg-muted text-muted-foreground', icon: CheckCircle2 },
};

const EVENT_STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    open: { label: 'Open', cls: 'bg-status-info-bg text-status-info', icon: AlertTriangle },
    investigating: { label: 'Investigating', cls: 'bg-primary/10 text-primary', icon: Search },
    corrective_action: { label: 'Corrective action', cls: 'bg-status-warning-bg text-status-warning', icon: ListChecks },
    monitoring: { label: 'Monitoring', cls: 'bg-[var(--live-bg)] text-[var(--live)]', icon: Activity },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
};

const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmtDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

const todayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

const daysAgoStr = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

export default function CorrectiveActionsIndex({ actions, tab, tabCounts, hero, filters, sites, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<EventSectionKey>('actions');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get('/health-safety/corrective-actions', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/health-safety/corrective-actions', { ...filters, tab: id }, { preserveScroll: true });

    const openEvent = (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => {
        setPendingSection(opts?.section ?? 'actions');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/corrective-actions', { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };

    const closeDetail = () =>
        router.get('/health-safety/corrective-actions', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () =>
        router.get('/health-safety/corrective-actions', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(filters.q || filters.priority || filters.site_id || filters.from || filters.to);

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'open', label: 'Open', icon: ListChecks, tone: 'info', badge: tabCounts.open || undefined },
        { id: 'in_progress', label: 'In progress', icon: Activity, tone: 'primary', badge: tabCounts.in_progress || undefined },
        { id: 'awaiting_verification', label: 'Awaiting verification', icon: ShieldCheck, tone: 'warning', badge: tabCounts.awaiting_verification || undefined },
        { id: 'overdue', label: 'Overdue', icon: Clock, tone: 'critical', badge: tabCounts.overdue || undefined },
        { id: 'verified', label: 'Verified', icon: CheckCircle2, tone: 'success', badge: tabCounts.verified || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
    ];

    const activeRange = !filters.from
        ? 'all'
        : filters.from === daysAgoStr(7)
          ? 'week'
          : filters.from === daysAgoStr(30)
            ? '30d'
            : filters.from === daysAgoStr(90)
              ? 'quarter'
              : 'custom';

    const onRange = (key: string) => {
        if (key === 'all') {
            go({ from: null, to: null });
            return;
        }
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    const openRowCtx = (e: ReactMouseEvent, action: ActionRow) => {
        e.preventDefault();
        const priority = PRI[action.priority] ?? PRI.medium;
        const items: ShiftCtxItem[] = action.event
            ? [
                  {
                      icon: <Eye className="h-3.5 w-3.5" />,
                      label: 'View parent event',
                      sub: action.event.reference_number,
                      tone: 'primary',
                      onClick: () => openEvent(action.event!.id, { section: 'overview' }),
                  },
                  {
                      icon: <ListChecks className="h-3.5 w-3.5" />,
                      label: 'Open corrective actions',
                      sub: action.reference_number,
                      onClick: () => openEvent(action.event!.id, { section: 'actions' }),
                  },
                  ...(can.manage && action.event.status !== 'closed'
                      ? [
                            {
                                icon: <ListChecks className="h-3.5 w-3.5" />,
                                label: 'Add corrective action',
                                onClick: () => openEvent(action.event!.id, { action: 'add_action' }),
                            } satisfies ShiftCtxItem,
                        ]
                      : []),
                  { sep: true },
                  {
                      icon: <Link2 className="h-3.5 w-3.5" />,
                      label: 'Open event full page',
                      onClick: () => router.visit(`/health-safety/events/${action.event!.id}`),
                  },
              ]
            : [];

        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: priority.label.toUpperCase(),
            meta: `${action.reference_number} · ${action.title}`,
            items,
        });
    };

    const live = hero.live;
    const attention = hero.attention;

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Corrective actions', href: '/health-safety/corrective-actions' }]}>
            <Head title="Corrective actions" />

            <div className="flex flex-col gap-6 p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented
                                label="Due"
                                variant="pill"
                                ariaLabel="Due date range"
                                items={[
                                    { key: 'all', label: 'All' },
                                    { key: 'week', label: 'This week' },
                                    { key: '30d', label: '30 days' },
                                    { key: 'quarter', label: 'Quarter' },
                                ]}
                                value={activeRange}
                                onChange={onRange}
                            />
                            {sites?.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            <HeroSegmented
                                label="Priority"
                                variant="pill"
                                ariaLabel="Priority"
                                items={[
                                    { key: 'all', label: 'All' },
                                    { key: 'low', label: 'Low' },
                                    { key: 'medium', label: 'Medium' },
                                    { key: 'high', label: 'High' },
                                    { key: 'critical', label: 'Critical' },
                                ]}
                                value={filters.priority ?? 'all'}
                                onChange={(key) => go({ priority: key === 'all' ? null : key })}
                            />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search action or event..."
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-52 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                                >
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={Wrench} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Safety actions · verification register</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Corrective actions</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Track every corrective and preventive action raised from H&S events, then jump back into the parent event to complete, verify and close the governance loop.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Live action lifecycle" icon={ListChecks}>
                            <HeroClusterTile href="/health-safety/corrective-actions?tab=open" label="Open" value={fmt(live.open)} caption="ready to start" tone={live.open > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/corrective-actions?tab=in_progress" label="In progress" value={fmt(live.in_progress)} caption="being resolved" tone="neutral" />
                            <HeroClusterTile href="/health-safety/corrective-actions?tab=awaiting_verification" label="Awaiting verification" value={fmt(live.awaiting_verification)} caption="needs verifier" tone={live.awaiting_verification > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/corrective-actions?tab=verified" label="Verified" value={fmt(live.verified)} caption="effectiveness checked" tone="success" />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href="/health-safety/corrective-actions?tab=overdue" label="Overdue" value={fmt(attention.overdue)} caption={attention.overdue > 0 ? 'past due' : 'all on track'} tone={attention.overdue > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile label="High / critical open" value={fmt(attention.critical_open)} caption="priority actions" tone={attention.critical_open > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile label="Unassigned" value={fmt(attention.unassigned)} caption="needs owner" tone={attention.unassigned > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/events?tab=monitoring" label="Events monitoring" value={fmt(attention.monitoring_events)} caption="auto-advanced" tone="neutral" />
                        </HeroCluster>
                    </div>
                </HeroShell>

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Corrective action views" />

                <Card>
                    <CardContent className="p-0">
                        <ActionTable rows={actions.data} onOpen={openEvent} onRowCtx={openRowCtx} />
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        {actions.total > 0 ? `Showing ${actions.from}-${actions.to} of ${actions.total}` : 'No corrective actions found'}
                    </p>
                    {actions.last_page > 1 ? <LaravelPagination links={actions.links} /> : null}
                </div>
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? (
                <EventDetailDialog key={detail.id} detail={detail} open onClose={closeDetail} initialSection={pendingSection} initialAction={pendingAction} />
            ) : null}
        </AppLayout>
    );
}

function ActionTable({
    rows,
    onOpen,
    onRowCtx,
}: {
    rows: ActionRow[];
    onOpen: (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => void;
    onRowCtx: (e: ReactMouseEvent, action: ActionRow) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-status-success/50" />
                <p className="font-medium text-muted-foreground">No corrective actions here</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">Due</th>
                        <th className="px-4 py-2.5">Action</th>
                        <th className="px-4 py-2.5">Priority</th>
                        <th className="px-4 py-2.5">Action stage</th>
                        <th className="px-4 py-2.5">Owner</th>
                        <th className="px-4 py-2.5">Parent event</th>
                        <th className="px-4 py-2.5">Event stage</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((action) => {
                        const priority = PRI[action.priority] ?? PRI.medium;
                        const stage = ACTION_STAGE[action.status] ?? ACTION_STAGE.open;
                        const StageIcon = stage.icon;
                        const eventStage = action.event ? (EVENT_STAGE[action.event.status] ?? EVENT_STAGE.open) : null;
                        const EventIcon = eventStage?.icon ?? Shield;

                        const open = () => {
                            if (action.event) onOpen(action.event.id, { section: 'actions' });
                        };

                        return (
                            <tr
                                key={action.id}
                                onClick={open}
                                onContextMenu={(e) => onRowCtx(e, action)}
                                tabIndex={action.event ? 0 : -1}
                                aria-label={action.event ? `Open parent event for action ${action.reference_number}` : undefined}
                                onKeyDown={(e) => {
                                    if (action.event && (e.key === 'Enter' || e.key === ' ')) {
                                        e.preventDefault();
                                        open();
                                    }
                                }}
                                className={cn(
                                    action.event ? 'cursor-pointer transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring' : '',
                                    action.is_overdue ? 'bg-status-critical-bg/45' : '',
                                )}
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <span className={cn('inline-flex items-center gap-1 text-xs', action.is_overdue ? 'font-semibold text-status-critical' : 'text-muted-foreground')}>
                                        <Clock className="h-3.5 w-3.5" />
                                        {fmtDate(action.due_date)}
                                        {action.is_overdue ? ' · overdue' : ''}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span className={cn('mt-1 h-2 w-2 shrink-0 rounded-full', TONE_DOT[priority.tone])} />
                                        <span className="min-w-0">
                                            <span className="block font-medium">{action.reference_number}</span>
                                            <span className="block max-w-[26rem] truncate text-xs text-muted-foreground">{action.title}</span>
                                            <span className="mt-1 inline-flex rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                {titleCase(action.action_type)}
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium', TONE_BG[priority.tone])}>
                                        {priority.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', stage.cls)}>
                                        <StageIcon className="h-3 w-3" />
                                        {stage.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {action.assigned_to_name ? (
                                        <span className="inline-flex items-center gap-1.5 text-xs">
                                            <UserRound className="h-3.5 w-3.5 text-muted-foreground" />
                                            {action.assigned_to_name}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-status-warning">Unassigned</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {action.event ? (
                                        <span className="min-w-0">
                                            <span className="block font-medium">{action.event.reference_number}</span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {EVENT_CATEGORY_LABELS[action.event.event_category] ?? titleCase(action.event.event_category)}
                                                {action.event.site_name ? ` · ${action.event.site_name}` : ''}
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Link2 className="h-3.5 w-3.5" />
                                            No parent event
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {eventStage ? (
                                        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', eventStage.cls)}>
                                            <EventIcon className="h-3 w-3" />
                                            {eventStage.label}
                                            {action.event?.monitoring ? <span className="sr-only"> after all actions resolved</span> : null}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">-</span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
