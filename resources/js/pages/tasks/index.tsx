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
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from '@/pages/health-safety/components/hs-hero-kit';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, ListChecks, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirror app/Services/Tasks/TaskItem::toArray()               */
/* ------------------------------------------------------------------ */

type NamedRef = { id: number; name: string };

type TaskBucket = 'open' | 'in_progress' | 'done';

type TaskSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

interface TaskItem {
    id: string;
    source: string;
    sourceLabel: string;
    ref: string | null;
    title: string;
    status: string;
    bucket: TaskBucket;
    severity: TaskSeverity;
    assignee: NamedRef | null;
    client: NamedRef | null;
    site: NamedRef | null;
    dueAt: string | null;
    createdAt: string | null;
    link: string | null;
    type: string | null;
    description: string | null;
    overdue: boolean;
}

interface Filters {
    sources: string[] | null;
    severity: string[] | null;
    bucket: string[] | null;
    assigned: 'me' | null;
    overdue: boolean;
    q: string | null;
    include_done: boolean;
}

interface Props {
    items: TaskItem[];
    stats: { open: number; overdue: number; critical: number; mine: number };
    sources: Array<{ key: string; label: string }>;
    filters: Filters;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const QUICK_VIEWS = [
    { key: 'all', label: 'All' },
    { key: 'mine', label: 'Mine' },
    { key: 'overdue', label: 'Overdue' },
] as const;

const SEVERITIES: Array<{ key: TaskSeverity; label: string }> = [
    { key: 'critical', label: 'Critical' },
    { key: 'high', label: 'High' },
    { key: 'medium', label: 'Medium' },
    { key: 'low', label: 'Low' },
    { key: 'info', label: 'Info' },
];

const BUCKETS: Array<{ key: TaskBucket; label: string }> = [
    { key: 'open', label: 'Open' },
    { key: 'in_progress', label: 'In progress' },
];

const SEVERITY_VARIANT: Record<TaskSeverity, StatusVariant> = {
    critical: 'critical',
    high: 'warning',
    medium: 'warning',
    low: 'info',
    info: 'neutral',
};

function humanise(raw: string): string {
    const label = raw.replace(/[_-]/g, ' ');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

/** Relative due label + tone class. Overdue rows read critical. */
function dueInfo(item: TaskItem): { label: string; className: string } {
    if (!item.dueAt) return { label: '—', className: 'text-muted-foreground' };
    const days = Math.ceil((new Date(item.dueAt).getTime() - Date.now()) / 86_400_000);
    if (item.overdue) {
        return {
            label: days >= 0 ? 'Overdue' : `${Math.abs(days)}d overdue`,
            className: 'font-semibold text-status-critical',
        };
    }
    if (days <= 0) return { label: 'Due today', className: 'font-semibold text-status-warning' };
    if (days <= 7) return { label: `Due in ${days}d`, className: 'text-status-warning' };
    return {
        label: new Date(item.dueAt).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }),
        className: 'text-muted-foreground',
    };
}

/** Serialise the filter state into shareable query params, omitting empties. */
function buildParams(f: Filters): Record<string, string> {
    const params: Record<string, string> = {};
    if (f.sources?.length) params.sources = f.sources.join(',');
    if (f.severity?.length) params.severity = f.severity.join(',');
    if (f.bucket?.length) params.bucket = f.bucket.join(',');
    if (f.assigned === 'me') params.assigned = 'me';
    if (f.overdue) params.overdue = '1';
    if (f.q) params.q = f.q;
    if (f.include_done) params.done = '1';
    return params;
}

function toggleKey(list: string[] | null, key: string): string[] {
    const current = list ?? [];
    return current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function TasksIndex({ items, stats, sources, filters }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');

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

    const go = (next: Partial<Filters>) =>
        router.get('/tasks', buildParams({ ...filtersRef.current, ...next }), {
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

    const quickView = filters.overdue ? 'overdue' : filters.assigned === 'me' ? 'mine' : 'all';
    const setQuickView = (key: string) =>
        go({ assigned: key === 'mine' ? 'me' : null, overdue: key === 'overdue' });

    const hasFilters = !!(
        filters.sources?.length ||
        filters.severity?.length ||
        filters.bucket?.length ||
        filters.assigned ||
        filters.overdue ||
        filters.q ||
        filters.include_done
    );
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
            q: null,
            include_done: false,
        };
        setSearch('');
        router.get('/tasks', {}, { preserveState: true, preserveScroll: true, replace: true });
    };

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
                <HeroShell
                    footer={
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <HeroSegmented
                                label="View"
                                ariaLabel="Quick view"
                                variant="pill"
                                value={quickView}
                                onChange={setQuickView}
                                items={QUICK_VIEWS}
                            />
                            <span className="text-xs text-primary-foreground/70 tabular-nums">
                                {items.length} task{items.length === 1 ? '' : 's'} in view
                            </span>
                        </div>
                    }
                >
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

                    <HeroSummaryStrip label="At a glance">
                        <HeroSummaryMetric tone="neutral">{stats.open} open</HeroSummaryMetric>
                        <HeroSummaryMetric tone={stats.overdue > 0 ? 'critical' : 'success'}>
                            {stats.overdue} overdue
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone={stats.critical > 0 ? 'warning' : 'success'}>
                            {stats.critical} high priority
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone="neutral">{stats.mine} assigned to me</HeroSummaryMetric>
                    </HeroSummaryStrip>
                </HeroShell>

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

                    <div role="group" aria-label="Status filter" className="flex items-center gap-1 rounded-lg bg-muted p-1">
                        {BUCKETS.map((b) => {
                            const active = (filters.bucket ?? []).includes(b.key);
                            return (
                                <button
                                    key={b.key}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => go({ bucket: toggleKey(filters.bucket, b.key) })}
                                    className={cn(
                                        'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors',
                                        active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {b.label}
                                </button>
                            );
                        })}
                        <button
                            type="button"
                            aria-pressed={filters.include_done}
                            onClick={() => go({ include_done: !filters.include_done })}
                            className={cn(
                                'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors',
                                filters.include_done
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            Include done
                        </button>
                    </div>

                    {hasFilters ? (
                        <Button variant="ghost" size="sm" className="h-9" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    ) : null}
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
                                            const open = () => item.link && router.visit(item.link);
                                            return (
                                                <tr
                                                    key={item.id}
                                                    role="link"
                                                    tabIndex={0}
                                                    onClick={open}
                                                    onKeyDown={(e) => e.key === 'Enter' && open()}
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
            </div>
        </AppLayout>
    );
}
