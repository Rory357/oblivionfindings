import {
    GoalDialog,
    type GoalOption,
    type ParentGoalOption,
} from '@/components/hr/performance/goal-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Key,
    Layers,
    Plus,
    Target,
    TrendingUp,
    User as UserIcon,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Goal {
    id: number;
    title: string;
    goal_type: string;
    status: string;
    priority: string;
    progress_percentage: number;
    target_value: number | null;
    current_value: number | null;
    unit: string | null;
    start_date: string | null;
    due_date: string | null;
    user: { id: number; name: string } | null;
    parent_goal: { id: number; title: string } | null;
    key_results_count: number;
}

interface CascadeGoal {
    id: number;
    title: string;
    goal_type: string;
    status: string;
    priority: string;
    progress_percentage: number;
    due_date: string | null;
    user: { id: number; name: string } | null;
    key_results_count: number;
    children: CascadeGoal[];
}

interface Analytics {
    total: number;
    active: number;
    completed: number;
    draft: number;
    overdue: number;
    on_track: number;
    completion_rate: number;
    progress_by_type: Array<{
        type: string;
        avg_progress: number;
        count: number;
    }>;
    monthly_completions: Record<string, number>;
}

interface Props {
    goals: {
        data: Goal[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    users: Array<{ id: number; name: string }>;
    goalTypes: GoalOption[];
    priorities: GoalOption[];
    parentGoals: ParentGoalOption[];
    analytics: Analytics;
    cascadeTree: CascadeGoal[];
    filters: {
        status: string | null;
        goal_type: string | null;
        priority: string | null;
        user_id: string | null;
    };
    can: { manage: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Goals & OKRs', href: '/hr/goals' },
];

const NONE = '__none__';

const typeBadge: Record<string, string> = {
    company:
        'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    team: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    individual:
        'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
};

const statusBadge: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    active: 'bg-status-info-bg text-status-info',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-status-critical-bg text-status-critical',
};

const priorityBadge: Record<string, string> = {
    low: 'bg-muted text-muted-foreground',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-critical-bg text-status-critical',
};

function formatDate(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function progressColour(pct: number) {
    if (pct >= 70) return 'bg-status-success';
    if (pct >= 40) return 'bg-status-warning';
    return 'bg-status-critical';
}

/* ------------------------------------------------------------------ */
/*  Objective Row — the core reusable component                        */
/* ------------------------------------------------------------------ */

function ObjectiveRow({
    goal,
    showOwner = true,
}: {
    goal: Goal;
    showOwner?: boolean;
}) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="border-b last:border-b-0">
            {/* Main row */}
            <div
                className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/30"
                onClick={() => setExpanded(!expanded)}
            >
                {goal.key_results_count > 0 ? (
                    <button className="shrink-0 text-muted-foreground hover:text-foreground">
                        {expanded ? (
                            <ChevronDown className="h-4 w-4" />
                        ) : (
                            <ChevronRight className="h-4 w-4" />
                        )}
                    </button>
                ) : (
                    <div className="w-4 shrink-0" />
                )}

                <div className="min-w-0 flex-1">
                    <Link
                        href={`/hr/goals/${goal.id}`}
                        className="text-sm font-medium hover:text-primary hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {goal.title}
                    </Link>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <Badge
                            variant="outline"
                            className={`px-1.5 py-0 text-[10px] ${typeBadge[goal.goal_type] || ''}`}
                        >
                            {goal.goal_type}
                        </Badge>
                        <Badge
                            variant="outline"
                            className={`px-1.5 py-0 text-[10px] ${priorityBadge[goal.priority] || ''}`}
                        >
                            {goal.priority}
                        </Badge>
                        {showOwner && goal.user && (
                            <span className="text-[11px] text-muted-foreground">
                                {goal.user.name}
                            </span>
                        )}
                        {goal.due_date && (
                            <span className="text-[11px] text-muted-foreground">
                                {formatDate(goal.due_date)}
                            </span>
                        )}
                        {goal.parent_goal && (
                            <span className="max-w-[200px] truncate text-[11px] text-muted-foreground/60">
                                ↳ {goal.parent_goal.title}
                            </span>
                        )}
                    </div>
                </div>

                {goal.key_results_count > 0 && (
                    <span className="flex shrink-0 items-center gap-1 text-[11px] text-muted-foreground">
                        <Key className="h-3 w-3" /> {goal.key_results_count}
                    </span>
                )}

                <div className="flex w-32 shrink-0 items-center gap-2">
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full transition-all ${progressColour(goal.progress_percentage)}`}
                            style={{ width: `${goal.progress_percentage}%` }}
                        />
                    </div>
                    <span className="w-8 text-right text-xs font-medium tabular-nums">
                        {goal.progress_percentage}%
                    </span>
                </div>

                <Badge
                    variant="outline"
                    className={`shrink-0 px-1.5 py-0 text-[10px] ${statusBadge[goal.status] || ''}`}
                >
                    {goal.status}
                </Badge>
            </div>

            {/* Expanded: placeholder for KRs (loaded from show page) */}
            {expanded && goal.key_results_count > 0 && (
                <div className="border-t bg-muted/20 px-4 py-2">
                    <p className="py-2 pl-8 text-xs text-muted-foreground">
                        {goal.key_results_count} key result
                        {goal.key_results_count > 1 ? 's' : ''} —{' '}
                        <Link
                            href={`/hr/goals/${goal.id}`}
                            className="text-primary hover:underline"
                        >
                            View & manage key results →
                        </Link>
                    </p>
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Cascade Panel Item                                                 */
/* ------------------------------------------------------------------ */

function CascadeItem({
    goal,
    selected,
    onClick,
}: {
    goal: CascadeGoal;
    selected: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            onClick={onClick}
            className={`h-auto w-full justify-start px-3 py-2.5 text-left ${
                selected
                    ? 'border border-primary/20 bg-primary/10'
                    : 'border border-transparent hover:bg-muted/50'
            }`}
        >
            <div className="flex items-center justify-between gap-2">
                <span
                    className={`truncate text-sm ${selected ? 'font-medium text-primary' : ''}`}
                >
                    {goal.title}
                </span>
                <span className="shrink-0 text-xs font-medium tabular-nums">
                    {goal.progress_percentage}%
                </span>
            </div>
            <div className="mt-1 flex items-center gap-2">
                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                        className={`h-full rounded-full ${progressColour(goal.progress_percentage)}`}
                        style={{ width: `${goal.progress_percentage}%` }}
                    />
                </div>
                {goal.key_results_count > 0 && (
                    <span className="text-[10px] text-muted-foreground">
                        {goal.key_results_count} KRs
                    </span>
                )}
            </div>
            {goal.user && (
                <p className="mt-1 text-[10px] text-muted-foreground">
                    {goal.user.name}
                </p>
            )}
        </Button>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function GoalsIndex({
    goals,
    users,
    goalTypes,
    priorities,
    parentGoals,
    analytics,
    cascadeTree,
    filters,
    can,
}: Props) {
    const [goalDialogOpen, setGoalDialogOpen] = useState(false);
    const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(
        cascadeTree[0]?.id ?? null,
    );
    const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);

    const selectedCompany = cascadeTree.find((g) => g.id === selectedCompanyId);
    const teamGoals = selectedCompany?.children ?? [];
    const selectedTeam = teamGoals.find((g) => g.id === selectedTeamId);
    const individualGoals = selectedTeam?.children ?? [];

    function onFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get(
            '/hr/goals',
            {
                ...(merged.status ? { status: merged.status } : {}),
                ...(merged.goal_type ? { goal_type: merged.goal_type } : {}),
                ...(merged.priority ? { priority: merged.priority } : {}),
                ...(merged.user_id ? { user_id: merged.user_id } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    const chartData = analytics.progress_by_type.map((d) => ({
        type: d.type.charAt(0).toUpperCase() + d.type.slice(1),
        progress: d.avg_progress,
    }));

    const completionData = Object.entries(analytics.monthly_completions).map(
        ([month, count]) => ({
            month,
            completed: count,
        }),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goals & OKRs" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Target}
                        title="Goals & OKRs"
                        description="Track objectives, key results, and team alignment across the organisation."
                        stats={[
                            { label: 'Active', value: analytics.active },
                            { label: 'Completed', value: analytics.completed },
                            { label: 'Completion', value: `${analytics.completion_rate}%` },
                            { label: 'Overdue', value: analytics.overdue },
                        ]}
                        actions={
                            can.manage && (
                                <Button onClick={() => setGoalDialogOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" /> New
                                    Objective
                                </Button>
                            )
                        }
                    />
                }
            >
                {/* Tabs */}
                <Tabs defaultValue="all">
                    <TabsList className="flex h-auto w-full flex-wrap gap-1">
                        <TabsTrigger value="all">
                            <Target className="mr-1.5 h-3.5 w-3.5" /> All
                            Objectives
                        </TabsTrigger>
                        <TabsTrigger value="alignment">
                            <Layers className="mr-1.5 h-3.5 w-3.5" /> Alignment
                        </TabsTrigger>
                        <TabsTrigger value="analytics">
                            <BarChart3 className="mr-1.5 h-3.5 w-3.5" />{' '}
                            Analytics
                        </TabsTrigger>
                    </TabsList>

                    {/* ============================================================ */}
                    {/* Tab: All Objectives                                          */}
                    {/* ============================================================ */}
                    <TabsContent value="all" className="mt-4 space-y-4">
                        {/* Filters */}
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.status || NONE}
                                onValueChange={(v) =>
                                    onFilter({ status: v === NONE ? null : v })
                                }
                            >
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="completed">
                                        Completed
                                    </SelectItem>
                                    <SelectItem value="cancelled">
                                        Cancelled
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.goal_type || NONE}
                                onValueChange={(v) =>
                                    onFilter({
                                        goal_type: v === NONE ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Types
                                    </SelectItem>
                                    <SelectItem value="company">
                                        Company
                                    </SelectItem>
                                    <SelectItem value="team">Team</SelectItem>
                                    <SelectItem value="individual">
                                        Individual
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.priority || NONE}
                                onValueChange={(v) =>
                                    onFilter({
                                        priority: v === NONE ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue placeholder="All Priorities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Priorities
                                    </SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="medium">
                                        Medium
                                    </SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.user_id || NONE}
                                onValueChange={(v) =>
                                    onFilter({ user_id: v === NONE ? null : v })
                                }
                            >
                                <SelectTrigger className="h-8 w-40 text-xs">
                                    <SelectValue placeholder="All Employees" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Employees
                                    </SelectItem>
                                    {users.map((u) => (
                                        <SelectItem
                                            key={u.id}
                                            value={String(u.id)}
                                        >
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Objective list */}
                        <Card>
                            <CardContent className="p-0">
                                {goals.data.length === 0 ? (
                                    <div className="py-16 text-center">
                                        <Target className="mx-auto mb-3 h-10 w-10 text-muted-foreground/30" />
                                        <p className="font-medium text-muted-foreground">
                                            No objectives found
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Create your first objective to get
                                            started.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {goals.data.map((goal) => (
                                            <ObjectiveRow
                                                key={goal.id}
                                                goal={goal}
                                            />
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {goals.links?.length > 3 && (
                            <LaravelPagination links={goals.links} />
                        )}
                    </TabsContent>

                    {/* ============================================================ */}
                    {/* Tab: Alignment (3-panel cascade)                             */}
                    {/* ============================================================ */}
                    <TabsContent value="alignment" className="mt-4">
                        {cascadeTree.length === 0 ? (
                            <Card>
                                <CardContent className="py-16 text-center">
                                    <Building2 className="mx-auto mb-3 h-10 w-10 text-muted-foreground/30" />
                                    <p className="font-medium text-muted-foreground">
                                        No company objectives yet
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Create a company-level objective to
                                        start cascading goals.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                {/* Company */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-1.5 text-sm">
                                            <Building2 className="h-4 w-4 text-primary" />{' '}
                                            Company Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {cascadeTree.map((g) => (
                                            <CascadeItem
                                                key={g.id}
                                                goal={g}
                                                selected={
                                                    selectedCompanyId === g.id
                                                }
                                                onClick={() => {
                                                    setSelectedCompanyId(g.id);
                                                    setSelectedTeamId(null);
                                                }}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>

                                {/* Team */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-1.5 text-sm">
                                            <Users className="h-4 w-4 text-status-info" />{' '}
                                            Team Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {teamGoals.length === 0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">
                                                {selectedCompanyId
                                                    ? 'No team goals under this objective'
                                                    : 'Select a company objective'}
                                            </p>
                                        ) : (
                                            teamGoals.map((g) => (
                                                <CascadeItem
                                                    key={g.id}
                                                    goal={g}
                                                    selected={
                                                        selectedTeamId === g.id
                                                    }
                                                    onClick={() =>
                                                        setSelectedTeamId(g.id)
                                                    }
                                                />
                                            ))
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Individual */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-1.5 text-sm">
                                            <UserIcon className="h-4 w-4 text-status-success" />{' '}
                                            Individual Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {individualGoals.length === 0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">
                                                {selectedTeamId
                                                    ? 'No individual goals under this team'
                                                    : 'Select a team objective'}
                                            </p>
                                        ) : (
                                            individualGoals.map((g) => (
                                                <CascadeItem
                                                    key={g.id}
                                                    goal={g}
                                                    selected={false}
                                                    onClick={() =>
                                                        router.visit(
                                                            `/hr/goals/${g.id}`,
                                                        )
                                                    }
                                                />
                                            ))
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                    </TabsContent>

                    {/* ============================================================ */}
                    {/* Tab: Analytics                                               */}
                    {/* ============================================================ */}
                    <TabsContent value="analytics" className="mt-4 space-y-4">
                        {/* KPI Cards */}
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-primary/10 p-2">
                                            <Target className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <p className="text-2xl font-bold">
                                                {analytics.active}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Active
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-success p-2">
                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                        </div>
                                        <div>
                                            <p className="text-2xl font-bold">
                                                {analytics.completion_rate}%
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Completion
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-info p-2">
                                            <TrendingUp className="h-4 w-4 text-status-info" />
                                        </div>
                                        <div>
                                            <p className="text-2xl font-bold">
                                                {analytics.on_track}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                On Track
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-warning p-2">
                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                        </div>
                                        <div>
                                            <p className="text-2xl font-bold">
                                                {analytics.overdue}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Overdue
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Charts */}
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Progress by Type
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {chartData.length === 0 ? (
                                        <p className="py-8 text-center text-sm text-muted-foreground">
                                            No active goals yet
                                        </p>
                                    ) : (
                                        <ResponsiveContainer
                                            width="100%"
                                            height={200}
                                        >
                                            <BarChart
                                                data={chartData}
                                                layout="vertical"
                                                margin={{ left: 20, right: 20 }}
                                            >
                                                <XAxis
                                                    type="number"
                                                    domain={[0, 100]}
                                                    tickFormatter={(v) =>
                                                        `${v}%`
                                                    }
                                                    fontSize={11}
                                                />
                                                <YAxis
                                                    type="category"
                                                    dataKey="type"
                                                    fontSize={11}
                                                    width={80}
                                                />
                                                <Tooltip
                                                    formatter={(v?: number) =>
                                                        `${v ?? 0}%`
                                                    }
                                                />
                                                <Bar
                                                    dataKey="progress"
                                                    fill="hsl(var(--primary))"
                                                    radius={[0, 4, 4, 0]}
                                                    barSize={20}
                                                />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Completion Trend
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {completionData.length === 0 ? (
                                        <p className="py-8 text-center text-sm text-muted-foreground">
                                            No completed goals yet
                                        </p>
                                    ) : (
                                        <ResponsiveContainer
                                            width="100%"
                                            height={200}
                                        >
                                            <AreaChart
                                                data={completionData}
                                                margin={{ left: 0, right: 0 }}
                                            >
                                                <CartesianGrid
                                                    strokeDasharray="3 3"
                                                    className="stroke-muted"
                                                />
                                                <XAxis
                                                    dataKey="month"
                                                    fontSize={11}
                                                />
                                                <YAxis
                                                    fontSize={11}
                                                    allowDecimals={false}
                                                />
                                                <Tooltip />
                                                <Area
                                                    type="monotone"
                                                    dataKey="completed"
                                                    stroke="hsl(var(--primary))"
                                                    fill="hsl(var(--primary))"
                                                    fillOpacity={0.1}
                                                    strokeWidth={2}
                                                />
                                            </AreaChart>
                                        </ResponsiveContainer>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>

                {can.manage && (
                    <GoalDialog
                        open={goalDialogOpen}
                        onClose={() => setGoalDialogOpen(false)}
                        owners={users}
                        goalTypes={goalTypes}
                        priorities={priorities}
                        parentGoals={parentGoals}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
