/* Company-wide "All Tasks" dashboard — every open incident, corrective action,
 * alert, concern and follow-up across the app in one permission-filtered,
 * ticket-numbered queue. Read-only: each row deep-links back to the module
 * that owns the record. Hero chrome reuses the shared hs-hero-kit (the app's
 * gold-standard command-centre chrome); semantic tokens only. */
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/health-safety/components/hs-hero-kit';
import { TaskDetailDialog } from '@/pages/tasks/task-detail-dialog';
import {
    dueInfo,
    humanise,
    SEVERITY_VARIANT,
    taskNumericId,
    type TaskItem,
    type TaskSeverity,
} from '@/pages/tasks/types';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bookmark,
    Building2,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    Download,
    ExternalLink,
    Eye,
    LayoutList,
    ListChecks,
    Search,
    Siren,
    User as UserIcon,
    UserCheck,
    UserX,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState, type MouseEvent as ReactMouseEvent } from 'react';

interface Filters {
    sources: string[] | null;
    severity: string[] | null;
    bucket: string[] | null;
    assigned: 'me' | 'unassigned' | null;
    overdue: boolean;
    due: 'week' | null;
    q: string | null;
    include_done: boolean;
    following: boolean;
}

interface Stats {
    open: number;
    bucketOpen: number;
    inProgress: number;
    unassigned: number;
    dueWeek: number;
    overdue: number;
    critical: number;
    mine: number;
    myOverdue: number;
    watching: number;
}

interface Pagination {
    page: number;
    perPage: number;
    total: number;
}

interface Props {
    items: TaskItem[];
    stats: Stats;
    sources: Array<{ key: string; label: string }>;
    filters: Filters;
    pagination: Pagination;
    usingDefaultView: boolean;
    assignableSources: string[];
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/** Register views — the TabStrip below the hero owns these dimensions. */
type TabKey = 'all' | 'mine' | 'following' | 'overdue' | 'unassigned' | 'week' | 'high' | 'done';

/** Filter override applied when a tab is clicked (search + modules persist).
 *  Each entry is a full reset of every dimension the tabs own — including
 *  `following` — so switching tabs never leaves a stray dimension behind. */
const TAB_FILTERS: Record<TabKey, Partial<Filters>> = {
    all: { assigned: null, overdue: false, due: null, severity: null, bucket: null, include_done: false, following: false },
    mine: { assigned: 'me', overdue: false, due: null, severity: null, bucket: null, include_done: false, following: false },
    following: { assigned: null, overdue: false, due: null, severity: null, bucket: null, include_done: false, following: true },
    overdue: { assigned: null, overdue: true, due: null, severity: null, bucket: null, include_done: false, following: false },
    unassigned: { assigned: 'unassigned', overdue: false, due: null, severity: null, bucket: null, include_done: false, following: false },
    week: { assigned: null, overdue: false, due: 'week', severity: null, bucket: null, include_done: false, following: false },
    high: { assigned: null, overdue: false, due: null, severity: ['critical', 'high'], bucket: null, include_done: false, following: false },
    done: { assigned: null, overdue: false, due: null, severity: null, bucket: ['done'], include_done: true, following: false },
};

function deriveTab(f: Filters): TabKey | 'none' {
    if (f.include_done && (f.bucket ?? []).join(',') === 'done') return 'done';
    // Following is its own dimension — check it before assignment/overdue so a
    // watched-items view isn't masked by whatever else is on the item.
    if (f.following) return 'following';
    // Combined views (e.g. the "My overdue" hero tile: assigned=me&overdue=1)
    // match no single tab — highlight none rather than lie.
    if (f.overdue && f.assigned) return 'none';
    if (f.overdue) return 'overdue';
    if (f.assigned === 'me') return 'mine';
    if (f.assigned === 'unassigned') return 'unassigned';
    if (f.due === 'week') return 'week';
    if ((f.severity ?? []).slice().sort().join(',') === 'critical,high') return 'high';
    return 'all';
}

const SEVERITIES: Array<{ key: TaskSeverity; label: string }> = [
    { key: 'critical', label: 'Critical' },
    { key: 'high', label: 'High' },
    { key: 'medium', label: 'Medium' },
    { key: 'low', label: 'Low' },
    { key: 'info', label: 'Info' },
];

/** Serialise the filter state into shareable query params, omitting empties.
 *  `page` rides along only when past the first page — every filter change
 *  omits it, which is exactly the "reset to page 1" behaviour we want. */
function buildParams(f: Filters, page?: number): Record<string, string> {
    const params: Record<string, string> = {};
    if (f.sources?.length) params.sources = f.sources.join(',');
    if (f.severity?.length) params.severity = f.severity.join(',');
    if (f.bucket?.length) params.bucket = f.bucket.join(',');
    if (f.assigned) params.assigned = f.assigned;
    if (f.overdue) params.overdue = '1';
    if (f.due) params.due = f.due;
    if (f.q) params.q = f.q;
    if (f.include_done) params.done = '1';
    if (f.following) params.following = '1';
    if (page && page > 1) params.page = String(page);
    return params;
}

function toggleKey(list: string[] | null, key: string): string[] {
    const current = list ?? [];
    return current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function TasksIndex({
    items,
    stats,
    sources,
    filters,
    pagination,
    usingDefaultView,
    assignableSources,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const currentUserId = auth.user.id;

    const [search, setSearch] = useState(filters.q ?? '');
    const [selected, setSelected] = useState<TaskItem | null>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    // The debounce timer must always read the LATEST committed filters —
    // a closure over the render-time `filters` re-applies stale filters
    // when the user clears or toggles them inside the 300ms window.
    const filtersRef = useRef(filters);
    filtersRef.current = filters;
    // Last q we asked the server for (not just the last echo) — comparing
    // against the echo alone either re-applies cleared filters or swallows
    // the first keystroke after "Clear filters".
    const lastSentQ = useRef(filters.q ?? '');

    useEffect(() => {
        lastSentQ.current = filters.q ?? '';
    }, [filters.q]);

    // Any filter change omits `page` (→ back to page 1); only the pager
    // passes an explicit page to stay within the current filter slice.
    const go = (next: Partial<Filters>, page?: number) =>
        router.get('/tasks', buildParams({ ...filtersRef.current, ...next }, page), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    // Debounced search → shareable ?q= param.
    useEffect(() => {
        const t = setTimeout(() => {
            if (search === lastSentQ.current) return;
            lastSentQ.current = search;
            go({ q: search || null });
        }, 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const tab = deriveTab(filters);
    const setTab = (id: string) => go(TAB_FILTERS[id as TabKey] ?? TAB_FILTERS.all);

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: stats.open || undefined },
        { id: 'mine', label: 'Mine', icon: UserCheck, tone: 'info', badge: stats.mine || undefined },
        { id: 'following', label: 'Following', icon: Eye, tone: 'info', badge: stats.watching || undefined },
        { id: 'overdue', label: 'Overdue', icon: Clock, tone: 'critical', badge: stats.overdue || undefined },
        { id: 'unassigned', label: 'Unassigned', icon: UserX, tone: 'warning', badge: stats.unassigned || undefined },
        { id: 'week', label: 'Due this week', icon: CalendarClock, tone: 'info', badge: stats.dueWeek || undefined },
        { id: 'high', label: 'High priority', icon: AlertTriangle, tone: 'warning', badge: stats.critical || undefined },
        { id: 'done', label: 'Done', icon: CheckCircle2, tone: 'success' },
    ];

    const hasFilters = !!(
        filters.sources?.length ||
        filters.severity?.length ||
        filters.bucket?.length ||
        filters.assigned ||
        filters.overdue ||
        filters.due ||
        filters.q ||
        filters.include_done
    );

    const exportHref = `/tasks?${new URLSearchParams({ ...buildParams(filters), format: 'csv' }).toString()}`;
    const clearFilters = () => {
        lastSentQ.current = '';
        // The bare visit IS the cleared state — reflect it locally so a
        // debounce firing before the server echo can't resurrect old filters.
        filtersRef.current = {
            sources: null,
            severity: null,
            bucket: null,
            assigned: null,
            overdue: false,
            due: null,
            q: null,
            include_done: false,
            following: false,
        };
        setSearch('');
        router.get('/tasks', {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    /* ── Default view (bookmarked filter set) ── */
    const saveView = () =>
        router.post('/tasks/default-view', { view: buildParams(filters) }, { preserveScroll: true });
    const clearDefaultView = () =>
        router.post('/tasks/default-view', { view: [] }, { preserveScroll: true });

    /* ── Assignment (the queue's one write action) ── */
    const assign = (item: TaskItem, assigneeId: number | null) =>
        router.post(
            `/tasks/${item.source}/${taskNumericId(item)}/assign`,
            { assignee_id: assigneeId },
            { preserveState: true, preserveScroll: true },
        );

    /* ── Row right-click context menu ── */
    const openRowCtx = (e: ReactMouseEvent, item: TaskItem) => {
        e.preventDefault();

        const menu: ShiftCtxItem[] = [];
        if (item.link) {
            menu.push(
                {
                    icon: <Eye className="h-3.5 w-3.5" />,
                    label: 'Open record',
                    sub: item.sourceLabel,
                    tone: 'primary',
                    onClick: () => router.visit(item.link!),
                },
                {
                    icon: <ExternalLink className="h-3.5 w-3.5" />,
                    label: 'Open in new tab',
                    onClick: () => window.open(item.link!, '_blank'),
                },
            );
        }
        if (item.ref) {
            menu.push({
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy ticket #',
                sub: item.ref,
                onClick: () => void navigator.clipboard.writeText(item.ref!),
            });
        }

        const actions: ShiftCtxItem[] = [];
        if (assignableSources.includes(item.source)) {
            if (item.assignee?.id !== currentUserId) {
                actions.push({
                    icon: <UserCheck className="h-3.5 w-3.5" />,
                    label: 'Assign to me',
                    onClick: () => assign(item, currentUserId),
                });
            }
            if (item.assignee) {
                actions.push({
                    icon: <UserX className="h-3.5 w-3.5" />,
                    label: 'Unassign',
                    sub: item.assignee.name,
                    onClick: () => assign(item, null),
                });
            }
        }
        if (item.client) {
            actions.push({
                icon: <UserIcon className="h-3.5 w-3.5" />,
                label: 'View client',
                sub: item.client.name,
                onClick: () => router.visit(`/clients/${item.client!.id}`),
            });
        }
        if (item.site) {
            actions.push({
                icon: <Building2 className="h-3.5 w-3.5" />,
                label: 'View site',
                sub: item.site.name,
                onClick: () => router.visit(`/sites/${item.site!.id}`),
            });
        }
        if (actions.length) menu.push({ sep: true }, ...actions);

        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: item.severity.toUpperCase(),
            meta: `${item.ref ?? humanise(item.status)} · ${item.sourceLabel}`,
            items: menu,
        });
    };

    /* ── Pagination (backend slices at pagination.perPage) ── */
    const { page, perPage, total } = pagination;
    const pageStart = total === 0 ? 0 : (page - 1) * perPage + 1;
    const pageEnd = Math.min(page * perPage, total);
    const hasPrev = page > 1;
    const hasNext = pageEnd < total;

    const selectedSources = filters.sources ?? [];
    const moduleLabel =
        selectedSources.length === 0
            ? 'All modules'
            : selectedSources.length === 1
              ? (sources.find((s) => s.key === selectedSources[0])?.label ?? '1 module')
              : `${selectedSources.length} modules`;

    return (
        <AppLayout breadcrumbs={[{ title: 'All Tasks', href: '/tasks' }]}>
            <Head title="All Tasks" />

            <div className="flex flex-col gap-4 p-6">
                {/* ── Hero ── */}
                <HeroShell>
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ListChecks} />
                            <div className="min-w-0 flex-1">
                                <HeroStatusPill>Work queue · live across every module</HeroStatusPill>
                                <h1 className="mt-2 text-2xl font-bold tracking-tight md:text-[28px]">All Tasks</h1>
                                <p className="mt-1 max-w-2xl text-sm text-primary-foreground/80">
                                    One queue across every module — incidents, corrective actions, alerts,
                                    concerns and follow-ups you're permitted to see, each deep-linking back
                                    to the record that owns it.
                                </p>
                            </div>
                        </div>
                        {/* On-dark hero affordance (hero-footer idiom, tokens only). */}
                        <a
                            href={exportHref}
                            className="inline-flex h-9 shrink-0 items-center gap-2 self-start rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                        >
                            <Download className="h-4 w-4" />
                            Export CSV
                        </a>
                    </div>

                    <div className="mt-4 grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="The queue" icon={ListChecks}>
                            <HeroClusterTile
                                href="/tasks?bucket=open"
                                label="Open"
                                value={String(stats.bucketOpen)}
                                caption="ready to start"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/tasks?bucket=in_progress"
                                label="In progress"
                                value={String(stats.inProgress)}
                                caption="being worked"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/tasks?assigned=unassigned"
                                label="Unassigned"
                                value={String(stats.unassigned)}
                                caption="need an owner"
                                tone={stats.unassigned > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                href="/tasks?due=week"
                                label="Due this week"
                                value={String(stats.dueWeek)}
                                caption="next 7 days"
                                tone="neutral"
                            />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Siren}>
                            <HeroClusterTile
                                href="/tasks?overdue=1"
                                label="Overdue"
                                value={String(stats.overdue)}
                                caption="past their due date"
                                tone={stats.overdue > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                href="/tasks?severity=critical,high"
                                label="High priority"
                                value={String(stats.critical)}
                                caption="critical + high"
                                tone={stats.critical > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                href="/tasks?assigned=me"
                                label="Assigned to me"
                                value={String(stats.mine)}
                                caption="my open items"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/tasks?assigned=me&overdue=1"
                                label="My overdue"
                                value={String(stats.myOverdue)}
                                caption="chase these first"
                                tone={stats.myOverdue > 0 ? 'critical' : 'success'}
                            />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ── Register views ── */}
                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Task views" />

                {/* ── Filter bar ── */}
                <div className="flex flex-wrap items-center gap-2.5 rounded-xl border border-border bg-card p-2.5">
                    <div className="relative min-w-[240px] flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search title or ticket # (e.g. INC-2026-0042)…"
                            aria-label="Search tasks"
                            className="h-9 w-full rounded-lg border border-border bg-background pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>

                    {usingDefaultView ? (
                        <span className="inline-flex h-7 items-center gap-1.5 rounded-full border border-border bg-muted px-2.5 text-xs font-medium text-muted-foreground">
                            <Bookmark className="h-3 w-3" />
                            Default view
                            <button
                                type="button"
                                aria-label="Clear default view"
                                title="Clear default view"
                                onClick={clearDefaultView}
                                className="-mr-1 rounded-full p-0.5 transition-colors hover:bg-background hover:text-foreground"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    ) : null}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" className="h-9" aria-label="Filter by module">
                                {moduleLabel}
                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuLabel>Modules</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {sources.map((s) => (
                                <DropdownMenuCheckboxItem
                                    key={s.key}
                                    checked={selectedSources.includes(s.key)}
                                    onCheckedChange={() => go({ sources: toggleKey(filters.sources, s.key) })}
                                    onSelect={(e) => e.preventDefault()}
                                >
                                    {s.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <div role="group" aria-label="Severity filter" className="flex items-center gap-1 rounded-lg bg-muted p-1">
                        {SEVERITIES.map((s) => {
                            const active = (filters.severity ?? []).includes(s.key);
                            return (
                                <button
                                    key={s.key}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => go({ severity: toggleKey(filters.severity, s.key) })}
                                    className={cn(
                                        'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors',
                                        active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {s.label}
                                </button>
                            );
                        })}
                    </div>

                    {hasFilters ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-9"
                            onClick={saveView}
                            title="Save the current filters as your default /tasks view"
                        >
                            <Bookmark className="h-4 w-4" />
                            Save view
                        </Button>
                    ) : null}

                    {hasFilters ? (
                        <Button variant="ghost" size="sm" className="h-9" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    ) : null}

                    <span className="ml-auto pr-1 text-xs text-muted-foreground tabular-nums">
                        {total} in view
                    </span>
                </div>

                {/* ── Queue ── */}
                <Card>
                    <CardContent className="p-0">
                        {items.length === 0 ? (
                            hasFilters ? (
                                <EmptyState
                                    icon={Search}
                                    title="No tasks match your filters"
                                    description="Try widening the module, severity or status filters — or clear them to see the whole queue."
                                    action={
                                        <Button variant="outline" size="sm" onClick={clearFilters}>
                                            Clear filters
                                        </Button>
                                    }
                                    className="m-4"
                                />
                            ) : (
                                <EmptyState
                                    icon={CheckCircle2}
                                    title="All clear"
                                    description="There are no open tasks across your modules right now."
                                    className="m-4"
                                />
                            )
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-[13px]">
                                    <thead>
                                        <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                            <th className="px-3 py-3 font-semibold">Ticket</th>
                                            <th className="px-3 py-3 font-semibold">Title</th>
                                            <th className="px-3 py-3 font-semibold">Severity</th>
                                            <th className="px-3 py-3 font-semibold">Status</th>
                                            <th className="px-3 py-3 font-semibold">Assignee</th>
                                            <th className="px-3 py-3 font-semibold">Client / Site</th>
                                            <th className="px-3 py-3 font-semibold">Due</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((item) => {
                                            const due = dueInfo(item);
                                            // Row click previews in the drawer; the deep link
                                            // lives on the drawer button + context menu now.
                                            const open = () => setSelected(item);
                                            return (
                                                <tr
                                                    key={item.id}
                                                    role="button"
                                                    tabIndex={0}
                                                    data-test="tasks-row"
                                                    onClick={open}
                                                    onKeyDown={(e) => e.key === 'Enter' && open()}
                                                    onContextMenu={(e) => openRowCtx(e, item)}
                                                    className="cursor-pointer border-b border-border transition-colors last:border-0 hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                                                >
                                                    <td className="px-3 py-2.5 whitespace-nowrap">
                                                        {item.ref ? (
                                                            <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground">
                                                                {item.ref}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>
                                                    <td className="max-w-[420px] px-3 py-2.5">
                                                        <div className="truncate font-semibold">{item.title}</div>
                                                        <div className="truncate text-xs text-muted-foreground">
                                                            {item.type ? `${item.type} · ` : ''}
                                                            {item.sourceLabel}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <StatusBadge variant={SEVERITY_VARIANT[item.severity]} size="sm">
                                                            {humanise(item.severity)}
                                                        </StatusBadge>
                                                    </td>
                                                    <td className="px-3 py-2.5 whitespace-nowrap">{humanise(item.status)}</td>
                                                    <td className="px-3 py-2.5 whitespace-nowrap">
                                                        {item.assignee ? (
                                                            item.assignee.name
                                                        ) : (
                                                            <span className="text-muted-foreground">Unassigned</span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 whitespace-nowrap text-muted-foreground">
                                                        {item.client?.name ?? item.site?.name ?? '—'}
                                                    </td>
                                                    <td className={cn('px-3 py-2.5 whitespace-nowrap', due.className)}>{due.label}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Pager ── */}
                {total > 0 ? (
                    <div
                        data-test="tasks-pager"
                        className="flex items-center justify-between gap-2 rounded-xl border border-border bg-card px-3 py-2"
                    >
                        <span className="text-xs text-muted-foreground tabular-nums">
                            Showing {pageStart}–{pageEnd} of {total}
                        </span>
                        <div className="flex items-center gap-1.5">
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                disabled={!hasPrev}
                                onClick={() => go({}, page - 1)}
                            >
                                <ChevronLeft className="h-4 w-4" />
                                Prev
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                disabled={!hasNext}
                                onClick={() => go({}, page + 1)}
                            >
                                Next
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            <TaskDetailDialog item={selected} currentUserId={currentUserId} onClose={() => setSelected(null)} />
        </AppLayout>
    );
}
