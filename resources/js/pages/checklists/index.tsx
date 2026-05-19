import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Edit,
    FileQuestion,
    Layers,
    PlayCircle,
    Plus,
    Search,
    Sparkles,
    TrendingUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { CreateTemplateDialog } from './_dialogs';

type SiteRef = { id: number; name: string; type?: string };
type TemplateRef = { id: number; name: string; frequency?: string };
type UserRef = { id: number; name: string };

type Stats = {
    templates_active: number;
    templates_inactive: number;
    assignments_active: number;
    runs_scheduled: number;
    runs_in_progress: number;
    runs_overdue: number;
    runs_completed_30d: number;
    sites_with_checklists: number;
};

type ActiveRun = {
    id: number;
    status: 'scheduled' | 'in_progress';
    scheduled_date: string | null;
    started_at: string | null;
    completion_percentage: number;
    is_overdue: boolean;
    site: SiteRef | null;
    template: TemplateRef | null;
};

type RecentRun = {
    id: number;
    completed_at: string | null;
    completion_percentage: number;
    items_passed: number;
    items_failed: number;
    site: SiteRef | null;
    template: { id: number; name: string } | null;
    completed_by: UserRef | null;
};

type Assignment = {
    id: number;
    frequency: string;
    start_date: string | null;
    end_date: string | null;
    site: SiteRef | null;
    template: TemplateRef | null;
    assigned_to: UserRef | null;
};

type Template = {
    id: number;
    key: string;
    name: string;
    description?: string | null;
    applicable_to_type: 'house' | 'head_office' | 'facility' | 'all';
    frequency: string;
    is_active: boolean;
    items_count: number;
    assignments_count: number;
};

type SiteOverview = {
    id: number;
    name: string;
    type: string;
    active_assignments: number;
    overdue_runs: number;
    scheduled_runs: number;
};

type Props = {
    stats: Stats;
    activeRuns: ActiveRun[];
    recentRuns: RecentRun[];
    assignments: Assignment[];
    templates: Template[];
    sitesOverview: SiteOverview[];
    can: {
        manageTemplates: boolean;
        schedule: boolean;
        run: boolean;
    };
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

const typeLabels: Record<string, string> = {
    house: 'House',
    head_office: 'Head Office',
    facility: 'Facility',
    all: 'All Site Types',
};

const typeBadgeColors: Record<string, string> = {
    house: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-900',
    head_office: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-900',
    facility: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900',
    all: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-900/60 dark:text-slate-300 dark:border-slate-700',
};

function formatDate(value: string | null) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatRelative(value: string | null) {
    if (!value) return '—';
    const diffMs = Date.now() - new Date(value).getTime();
    const day = 1000 * 60 * 60 * 24;
    const days = Math.floor(diffMs / day);
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days}d ago`;
    if (days < 30) return `${Math.floor(days / 7)}w ago`;
    return formatDate(value);
}

function StatCard({
    label,
    value,
    accent,
    icon: Icon,
    sublabel,
}: {
    label: string;
    value: number | string;
    accent: 'primary' | 'amber' | 'red' | 'green' | 'slate';
    icon: React.ComponentType<{ className?: string }>;
    sublabel?: string;
}) {
    const accentClasses = {
        primary: 'from-primary/10 to-primary/5 text-primary',
        amber: 'from-amber-500/10 to-amber-500/5 text-amber-600 dark:text-amber-400',
        red: 'from-rose-500/10 to-rose-500/5 text-rose-600 dark:text-rose-400',
        green: 'from-emerald-500/10 to-emerald-500/5 text-emerald-600 dark:text-emerald-400',
        slate: 'from-slate-500/10 to-slate-500/5 text-slate-600 dark:text-slate-400',
    };
    return (
        <Card className="overflow-hidden">
            <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {label}
                        </p>
                        <p className="mt-1.5 text-2xl font-semibold tracking-tight">
                            {value}
                        </p>
                        {sublabel && (
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {sublabel}
                            </p>
                        )}
                    </div>
                    <div
                        className={cn(
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br',
                            accentClasses[accent],
                        )}
                    >
                        <Icon className="h-5 w-5" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function RunStatusBadge({ status, isOverdue }: { status: string; isOverdue?: boolean }) {
    if (isOverdue) {
        return (
            <Badge className="border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                <AlertTriangle className="mr-1 h-3 w-3" />
                Overdue
            </Badge>
        );
    }
    if (status === 'scheduled') {
        return (
            <Badge variant="outline" className="text-muted-foreground">
                <CalendarClock className="mr-1 h-3 w-3" />
                Scheduled
            </Badge>
        );
    }
    if (status === 'in_progress') {
        return (
            <Badge className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                <PlayCircle className="mr-1 h-3 w-3" />
                In Progress
            </Badge>
        );
    }
    if (status === 'completed') {
        return (
            <Badge className="border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
                <CheckCircle2 className="mr-1 h-3 w-3" />
                Completed
            </Badge>
        );
    }
    return <Badge variant="outline">{status}</Badge>;
}

function TypeBadge({ type }: { type: string }) {
    return (
        <Badge
            variant="outline"
            className={cn('text-xs font-normal', typeBadgeColors[type] ?? typeBadgeColors.all)}
        >
            {typeLabels[type] ?? type}
        </Badge>
    );
}

export default function ChecklistsDashboard({
    stats,
    activeRuns,
    recentRuns,
    assignments,
    templates,
    sitesOverview,
    can,
}: Props) {
    const [tab, setTab] = useState('overview');
    const [createTemplateOpen, setCreateTemplateOpen] = useState(false);

    // Runs tab filters
    const [runSearch, setRunSearch] = useState('');
    const [runStatus, setRunStatus] = useState<'all' | 'overdue' | 'scheduled' | 'in_progress'>(
        'all',
    );

    // Assignments tab filters
    const [assignSearch, setAssignSearch] = useState('');
    const [assignSiteFilter, setAssignSiteFilter] = useState<string>('all');

    // Templates tab filters
    const [templateSearch, setTemplateSearch] = useState('');
    const [templateType, setTemplateType] = useState<string>('all');
    const [templateStatus, setTemplateStatus] = useState<'all' | 'active' | 'inactive'>('all');

    const filteredRuns = useMemo(() => {
        return activeRuns.filter((run) => {
            const matchesSearch =
                !runSearch ||
                run.site?.name.toLowerCase().includes(runSearch.toLowerCase()) ||
                run.template?.name.toLowerCase().includes(runSearch.toLowerCase());
            const matchesStatus =
                runStatus === 'all' ||
                (runStatus === 'overdue' && run.is_overdue) ||
                (runStatus !== 'overdue' && run.status === runStatus && !run.is_overdue);
            return matchesSearch && matchesStatus;
        });
    }, [activeRuns, runSearch, runStatus]);

    const sitesForFilter = useMemo(() => {
        const map = new Map<number, string>();
        assignments.forEach((a) => {
            if (a.site) map.set(a.site.id, a.site.name);
        });
        return Array.from(map.entries())
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [assignments]);

    const filteredAssignments = useMemo(() => {
        return assignments.filter((a) => {
            const matchesSearch =
                !assignSearch ||
                a.site?.name.toLowerCase().includes(assignSearch.toLowerCase()) ||
                a.template?.name.toLowerCase().includes(assignSearch.toLowerCase());
            const matchesSite =
                assignSiteFilter === 'all' || String(a.site?.id) === assignSiteFilter;
            return matchesSearch && matchesSite;
        });
    }, [assignments, assignSearch, assignSiteFilter]);

    const groupedAssignments = useMemo(() => {
        const groups = new Map<number, { site: SiteRef; items: Assignment[] }>();
        filteredAssignments.forEach((a) => {
            if (!a.site) return;
            const existing = groups.get(a.site.id);
            if (existing) {
                existing.items.push(a);
            } else {
                groups.set(a.site.id, { site: a.site, items: [a] });
            }
        });
        return Array.from(groups.values()).sort((a, b) =>
            a.site.name.localeCompare(b.site.name),
        );
    }, [filteredAssignments]);

    const filteredTemplates = useMemo(() => {
        return templates.filter((t) => {
            const matchesSearch =
                !templateSearch ||
                t.name.toLowerCase().includes(templateSearch.toLowerCase()) ||
                t.key.toLowerCase().includes(templateSearch.toLowerCase());
            const matchesType =
                templateType === 'all' || t.applicable_to_type === templateType;
            const matchesStatus =
                templateStatus === 'all' ||
                (templateStatus === 'active' && t.is_active) ||
                (templateStatus === 'inactive' && !t.is_active);
            return matchesSearch && matchesType && matchesStatus;
        });
    }, [templates, templateSearch, templateType, templateStatus]);

    const sitesNeedingAttention = useMemo(
        () =>
            sitesOverview
                .filter((s) => s.overdue_runs > 0)
                .sort((a, b) => b.overdue_runs - a.overdue_runs)
                .slice(0, 6),
        [sitesOverview],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites & Locations', href: '/sites' },
                { title: 'Checklists', href: '/checklists' },
            ]}
        >
            <Head title="Checklists" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Hero Header */}
                <PageHero
                    title="Checklists"
                    description="Operational checklists, walkthroughs and inspections across every site."
                    icon={<ClipboardCheck className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Templates', value: stats.templates_active },
                        { label: 'Assignments', value: stats.assignments_active },
                        { label: 'Overdue', value: stats.runs_overdue },
                        { label: 'Completed 30d', value: stats.runs_completed_30d },
                    ]}
                    actions={
                        can.manageTemplates ? (
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link href="/sites/checklists/templates">
                                        <Layers className="mr-1.5 h-4 w-4" />
                                        Manage Templates
                                    </Link>
                                </Button>
                                <Button onClick={() => setCreateTemplateOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Template
                                </Button>
                            </div>
                        ) : undefined
                    }
                />

                {/* Stat row */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Active Templates"
                        value={stats.templates_active}
                        sublabel={`${stats.templates_inactive} inactive`}
                        accent="primary"
                        icon={ClipboardList}
                    />
                    <StatCard
                        label="Active Assignments"
                        value={stats.assignments_active}
                        sublabel={`Across ${stats.sites_with_checklists} sites`}
                        accent="slate"
                        icon={Building2}
                    />
                    <StatCard
                        label="Overdue Runs"
                        value={stats.runs_overdue}
                        sublabel={`${stats.runs_scheduled} scheduled, ${stats.runs_in_progress} in progress`}
                        accent={stats.runs_overdue > 0 ? 'red' : 'amber'}
                        icon={AlertTriangle}
                    />
                    <StatCard
                        label="Completed (30d)"
                        value={stats.runs_completed_30d}
                        sublabel="Last 30 days"
                        accent="green"
                        icon={TrendingUp}
                    />
                </div>

                <TabsRoot value={tab} onValueChange={setTab} className="flex flex-col gap-4">
                    <TabsList className="scrollbar-pretty flex h-auto w-full justify-start gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-1">
                        <TabsTrigger
                            value="overview"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <ClipboardCheck className="h-4 w-4" />
                            Overview
                        </TabsTrigger>
                        <TabsTrigger
                            value="runs"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <PlayCircle className="h-4 w-4" />
                            Runs
                            {(stats.runs_overdue > 0 || stats.runs_in_progress > 0) && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 h-5 px-1.5 text-[10px] tabular-nums"
                                >
                                    {stats.runs_overdue + stats.runs_in_progress + stats.runs_scheduled}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger
                            value="assignments"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <CalendarClock className="h-4 w-4" />
                            Assignments
                            <Badge
                                variant="outline"
                                className="ml-1 h-5 px-1.5 text-[10px] tabular-nums"
                            >
                                {assignments.length}
                            </Badge>
                        </TabsTrigger>
                        <TabsTrigger
                            value="templates"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Layers className="h-4 w-4" />
                            Templates
                            <Badge
                                variant="outline"
                                className="ml-1 h-5 px-1.5 text-[10px] tabular-nums"
                            >
                                {templates.length}
                            </Badge>
                        </TabsTrigger>
                    </TabsList>

                    {/* OVERVIEW */}
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Sites needing attention */}
                            <Card className="lg:col-span-2">
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            Needs attention
                                        </CardTitle>
                                        <CardDescription>
                                            Sites with overdue checklist runs
                                        </CardDescription>
                                    </div>
                                    {sitesNeedingAttention.length > 0 && (
                                        <Badge className="border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                                            {sitesNeedingAttention.length}
                                        </Badge>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {sitesNeedingAttention.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center py-8 text-center">
                                            <CheckCircle2 className="mb-2 h-10 w-10 text-emerald-500" />
                                            <p className="text-sm font-medium">All caught up</p>
                                            <p className="text-xs text-muted-foreground">
                                                No sites have overdue runs.
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {sitesNeedingAttention.map((site) => (
                                                <Link
                                                    key={site.id}
                                                    href={`/sites/${site.id}/checklists`}
                                                    className="flex items-center justify-between rounded-md border bg-card px-3 py-2.5 transition hover:border-primary/40 hover:bg-accent/50"
                                                >
                                                    <div className="flex items-center gap-3 min-w-0">
                                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400">
                                                            <AlertTriangle className="h-4 w-4" />
                                                        </span>
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm font-medium">
                                                                {site.name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {typeLabels[site.type] ?? site.type}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        <Badge className="border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                                                            {site.overdue_runs} overdue
                                                        </Badge>
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Recent activity */}
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Recent runs</CardTitle>
                                    <CardDescription>Last completed checklists</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {recentRuns.length === 0 ? (
                                        <div className="py-6 text-center text-sm text-muted-foreground">
                                            <ClipboardCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                            <p>No completed runs yet</p>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {recentRuns.slice(0, 6).map((run) => (
                                                <div
                                                    key={run.id}
                                                    className="flex items-start gap-3 border-b pb-3 last:border-0 last:pb-0"
                                                >
                                                    <span
                                                        className={cn(
                                                            'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                                                            run.items_failed > 0
                                                                ? 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400'
                                                                : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400',
                                                        )}
                                                    >
                                                        {run.items_failed > 0 ? (
                                                            <AlertTriangle className="h-3.5 w-3.5" />
                                                        ) : (
                                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                                        )}
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-medium">
                                                            {run.template?.name ?? 'Untitled'}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {run.site?.name}
                                                            {run.completed_by && (
                                                                <> &middot; {run.completed_by.name}</>
                                                            )}
                                                        </p>
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {formatRelative(run.completed_at)}
                                                            {run.items_failed > 0 && (
                                                                <>
                                                                    {' '}
                                                                    &middot;{' '}
                                                                    <span className="text-amber-600 dark:text-amber-400">
                                                                        {run.items_failed} failed
                                                                    </span>
                                                                </>
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Sites grid */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                                <div>
                                    <CardTitle className="text-base">All sites</CardTitle>
                                    <CardDescription>
                                        Checklist coverage across every active site
                                    </CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {sitesOverview.length === 0 ? (
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        No active sites.
                                    </div>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                        {sitesOverview.map((site) => (
                                            <Link
                                                key={site.id}
                                                href={`/sites/${site.id}/checklists`}
                                                className="group rounded-lg border bg-card p-3 transition hover:border-primary/40 hover:shadow-sm"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium group-hover:text-primary">
                                                            {site.name}
                                                        </p>
                                                        <TypeBadge type={site.type} />
                                                    </div>
                                                    <Building2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                </div>
                                                <div className="mt-3 flex items-center gap-3 text-xs text-muted-foreground">
                                                    <span>
                                                        <span className="font-medium text-foreground">
                                                            {site.active_assignments}
                                                        </span>{' '}
                                                        assigned
                                                    </span>
                                                    {site.overdue_runs > 0 ? (
                                                        <span className="text-rose-600 dark:text-rose-400">
                                                            <span className="font-medium">
                                                                {site.overdue_runs}
                                                            </span>{' '}
                                                            overdue
                                                        </span>
                                                    ) : site.scheduled_runs > 0 ? (
                                                        <span>
                                                            <span className="font-medium text-foreground">
                                                                {site.scheduled_runs}
                                                            </span>{' '}
                                                            scheduled
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* RUNS */}
                    <TabsContent value="runs" className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            Active & upcoming runs
                                        </CardTitle>
                                        <CardDescription>
                                            Scheduled, in-progress and overdue across all sites
                                        </CardDescription>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="relative">
                                            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                value={runSearch}
                                                onChange={(e) => setRunSearch(e.target.value)}
                                                placeholder="Search site or template..."
                                                className="h-9 w-56 pl-8"
                                            />
                                        </div>
                                        <Select
                                            value={runStatus}
                                            onValueChange={(v) =>
                                                setRunStatus(v as typeof runStatus)
                                            }
                                        >
                                            <SelectTrigger className="h-9 w-40">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All statuses</SelectItem>
                                                <SelectItem value="overdue">Overdue</SelectItem>
                                                <SelectItem value="scheduled">Scheduled</SelectItem>
                                                <SelectItem value="in_progress">
                                                    In progress
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="px-0 pb-0">
                                {filteredRuns.length === 0 ? (
                                    <div className="py-12 text-center text-sm text-muted-foreground">
                                        <ClipboardCheck className="mx-auto mb-2 h-10 w-10 opacity-40" />
                                        <p>No runs match your filters.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Site</TableHead>
                                                    <TableHead>Checklist</TableHead>
                                                    <TableHead>Status</TableHead>
                                                    <TableHead>Scheduled</TableHead>
                                                    <TableHead>Progress</TableHead>
                                                    <TableHead className="text-right">
                                                        Actions
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {filteredRuns.map((run) => (
                                                    <TableRow key={run.id}>
                                                        <TableCell>
                                                            {run.site ? (
                                                                <div>
                                                                    <Link
                                                                        href={`/sites/${run.site.id}`}
                                                                        className="font-medium hover:text-primary hover:underline"
                                                                    >
                                                                        {run.site.name}
                                                                    </Link>
                                                                    <div className="mt-0.5">
                                                                        <TypeBadge
                                                                            type={run.site.type ?? 'all'}
                                                                        />
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="font-medium">
                                                                {run.template?.name ?? '—'}
                                                            </div>
                                                            {run.template?.frequency && (
                                                                <div className="text-xs text-muted-foreground">
                                                                    {frequencyLabels[
                                                                        run.template.frequency
                                                                    ] ?? run.template.frequency}
                                                                </div>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <RunStatusBadge
                                                                status={run.status}
                                                                isOverdue={run.is_overdue}
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {formatDate(run.scheduled_date)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex items-center gap-2">
                                                                <div className="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                                                    <div
                                                                        className={cn(
                                                                            'h-full transition-all',
                                                                            run.completion_percentage ===
                                                                                100
                                                                                ? 'bg-emerald-500'
                                                                                : 'bg-primary',
                                                                        )}
                                                                        style={{
                                                                            width: `${Math.min(run.completion_percentage, 100)}%`,
                                                                        }}
                                                                    />
                                                                </div>
                                                                <span className="text-xs tabular-nums text-muted-foreground">
                                                                    {Math.round(
                                                                        run.completion_percentage,
                                                                    )}
                                                                    %
                                                                </span>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <Button
                                                                asChild
                                                                size="sm"
                                                                variant="outline"
                                                            >
                                                                <Link
                                                                    href={`/checklists/runs/${run.id}`}
                                                                >
                                                                    Open
                                                                </Link>
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* ASSIGNMENTS */}
                    <TabsContent value="assignments" className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            Active assignments
                                        </CardTitle>
                                        <CardDescription>
                                            Templates assigned to sites, grouped by site
                                        </CardDescription>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="relative">
                                            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                value={assignSearch}
                                                onChange={(e) =>
                                                    setAssignSearch(e.target.value)
                                                }
                                                placeholder="Search..."
                                                className="h-9 w-56 pl-8"
                                            />
                                        </div>
                                        <Select
                                            value={assignSiteFilter}
                                            onValueChange={setAssignSiteFilter}
                                        >
                                            <SelectTrigger className="h-9 w-48">
                                                <SelectValue placeholder="All sites" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All sites</SelectItem>
                                                {sitesForFilter.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>
                                                        {s.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {groupedAssignments.length === 0 ? (
                                    <div className="py-12 text-center text-sm text-muted-foreground">
                                        <ClipboardList className="mx-auto mb-2 h-10 w-10 opacity-40" />
                                        <p>No assignments match your filters.</p>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        {groupedAssignments.map((group) => (
                                            <div
                                                key={group.site.id}
                                                className="rounded-lg border bg-card"
                                            >
                                                <div className="flex items-center justify-between border-b px-4 py-2.5">
                                                    <div className="flex items-center gap-2">
                                                        <Building2 className="h-4 w-4 text-muted-foreground" />
                                                        <Link
                                                            href={`/sites/${group.site.id}`}
                                                            className="text-sm font-medium hover:text-primary hover:underline"
                                                        >
                                                            {group.site.name}
                                                        </Link>
                                                        <TypeBadge
                                                            type={group.site.type ?? 'all'}
                                                        />
                                                        <Badge
                                                            variant="outline"
                                                            className="text-muted-foreground"
                                                        >
                                                            {group.items.length}{' '}
                                                            {group.items.length === 1
                                                                ? 'checklist'
                                                                : 'checklists'}
                                                        </Badge>
                                                    </div>
                                                    <Button asChild size="sm" variant="ghost">
                                                        <Link
                                                            href={`/sites/${group.site.id}/checklists`}
                                                        >
                                                            Manage
                                                        </Link>
                                                    </Button>
                                                </div>
                                                <div className="divide-y">
                                                    {group.items.map((a) => (
                                                        <div
                                                            key={a.id}
                                                            className="flex items-center justify-between gap-3 px-4 py-2.5"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <p className="truncate text-sm font-medium">
                                                                    {a.template?.name ?? '—'}
                                                                </p>
                                                                <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                                    <span>
                                                                        {frequencyLabels[
                                                                            a.frequency
                                                                        ] ?? a.frequency}
                                                                    </span>
                                                                    {a.assigned_to && (
                                                                        <>
                                                                            <span>&middot;</span>
                                                                            <span>
                                                                                Assigned to{' '}
                                                                                {a.assigned_to.name}
                                                                            </span>
                                                                        </>
                                                                    )}
                                                                    {a.start_date && (
                                                                        <>
                                                                            <span>&middot;</span>
                                                                            <span>
                                                                                from{' '}
                                                                                {formatDate(
                                                                                    a.start_date,
                                                                                )}
                                                                            </span>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* TEMPLATES */}
                    <TabsContent value="templates" className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            Checklist templates
                                        </CardTitle>
                                        <CardDescription>
                                            Reusable templates that can be assigned to any site
                                        </CardDescription>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="relative">
                                            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                value={templateSearch}
                                                onChange={(e) =>
                                                    setTemplateSearch(e.target.value)
                                                }
                                                placeholder="Search..."
                                                className="h-9 w-48 pl-8"
                                            />
                                        </div>
                                        <Select
                                            value={templateType}
                                            onValueChange={setTemplateType}
                                        >
                                            <SelectTrigger className="h-9 w-40">
                                                <SelectValue placeholder="Site type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All types</SelectItem>
                                                <SelectItem value="house">House</SelectItem>
                                                <SelectItem value="head_office">
                                                    Head Office
                                                </SelectItem>
                                                <SelectItem value="facility">Facility</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Select
                                            value={templateStatus}
                                            onValueChange={(v) =>
                                                setTemplateStatus(v as typeof templateStatus)
                                            }
                                        >
                                            <SelectTrigger className="h-9 w-32">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All</SelectItem>
                                                <SelectItem value="active">Active</SelectItem>
                                                <SelectItem value="inactive">Inactive</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {filteredTemplates.length === 0 ? (
                                    <div className="py-12 text-center text-sm text-muted-foreground">
                                        <Sparkles className="mx-auto mb-2 h-10 w-10 opacity-40" />
                                        <p>No templates match your filters.</p>
                                        {can.manageTemplates && (
                                            <Button
                                                variant="outline"
                                                className="mt-4"
                                                onClick={() =>
                                                    setCreateTemplateOpen(true)
                                                }
                                            >
                                                <Plus className="mr-1 h-4 w-4" />
                                                Create your first template
                                            </Button>
                                        )}
                                    </div>
                                ) : (
                                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                                        {filteredTemplates.map((t) => (
                                            <div
                                                key={t.id}
                                                className={cn(
                                                    'group relative flex flex-col rounded-lg border bg-card p-4 transition hover:border-primary/40 hover:shadow-sm',
                                                    !t.is_active && 'opacity-60',
                                                )}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <h3 className="truncate text-sm font-semibold">
                                                            {t.name}
                                                        </h3>
                                                        <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
                                                            {t.key}
                                                        </p>
                                                    </div>
                                                    {!t.is_active && (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </div>
                                                {t.description && (
                                                    <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                                        {t.description}
                                                    </p>
                                                )}
                                                <div className="mt-3 flex flex-wrap gap-1.5">
                                                    <TypeBadge type={t.applicable_to_type} />
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px] text-muted-foreground"
                                                    >
                                                        {frequencyLabels[t.frequency] ?? t.frequency}
                                                    </Badge>
                                                </div>
                                                <div className="mt-4 flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                                                    <div className="flex items-center gap-3">
                                                        <span className="flex items-center gap-1">
                                                            <FileQuestion className="h-3 w-3" />
                                                            {t.items_count} items
                                                        </span>
                                                        <span className="flex items-center gap-1">
                                                            <Building2 className="h-3 w-3" />
                                                            {t.assignments_count} sites
                                                        </span>
                                                    </div>
                                                    {can.manageTemplates && (
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 px-2"
                                                        >
                                                            <Link
                                                                href={`/sites/checklists/templates/${t.id}/edit`}
                                                            >
                                                                <Edit className="mr-1 h-3 w-3" />
                                                                Edit
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </TabsRoot>
            </div>

            <CreateTemplateDialog
                isOpen={createTemplateOpen}
                onClose={() => setCreateTemplateOpen(false)}
            />
        </AppLayout>
    );
}
