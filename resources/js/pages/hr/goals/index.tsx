import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Target, Plus, ChevronDown, ChevronRight, Building2, Users,
    User as UserIcon, Calendar, Key, BarChart3, Layers,
    CheckCircle2, TrendingUp, AlertTriangle,
} from 'lucide-react';
import { useState } from 'react';
import {
    ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip,
    AreaChart, Area, CartesianGrid,
} from 'recharts';
import { type BreadcrumbItem } from '@/types';

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
    progress_by_type: Array<{ type: string; avg_progress: number; count: number }>;
    monthly_completions: Record<string, number>;
}

interface Props {
    goals: { data: Goal[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    users: Array<{ id: number; name: string }>;
    analytics: Analytics;
    cascadeTree: CascadeGoal[];
    filters: { status: string | null; goal_type: string | null; priority: string | null; user_id: string | null };
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
    company: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    team: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    individual: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
};

const statusBadge: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    active: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-red-100 text-red-700',
};

const priorityBadge: Record<string, string> = {
    low: 'bg-muted text-muted-foreground',
    medium: 'bg-amber-100 text-amber-700',
    high: 'bg-red-100 text-red-700',
};

function formatDate(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function progressColour(pct: number) {
    if (pct >= 70) return 'bg-emerald-500';
    if (pct >= 40) return 'bg-amber-500';
    return 'bg-red-400';
}

/* ------------------------------------------------------------------ */
/*  Objective Row — the core reusable component                        */
/* ------------------------------------------------------------------ */

function ObjectiveRow({ goal, showOwner = true }: { goal: Goal; showOwner?: boolean }) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="border-b last:border-b-0">
            {/* Main row */}
            <div
                className="flex items-center gap-3 px-4 py-3 hover:bg-muted/30 cursor-pointer transition-colors"
                onClick={() => setExpanded(!expanded)}
            >
                {goal.key_results_count > 0 ? (
                    <button className="shrink-0 text-muted-foreground hover:text-foreground">
                        {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                    </button>
                ) : (
                    <div className="w-4 shrink-0" />
                )}

                <div className="flex-1 min-w-0">
                    <Link
                        href={`/hr/goals/${goal.id}`}
                        className="font-medium text-sm hover:text-primary hover:underline"
                        onClick={e => e.stopPropagation()}
                    >
                        {goal.title}
                    </Link>
                    <div className="flex flex-wrap items-center gap-1.5 mt-1">
                        <Badge variant="outline" className={`text-[10px] px-1.5 py-0 ${typeBadge[goal.goal_type] || ''}`}>
                            {goal.goal_type}
                        </Badge>
                        <Badge variant="outline" className={`text-[10px] px-1.5 py-0 ${priorityBadge[goal.priority] || ''}`}>
                            {goal.priority}
                        </Badge>
                        {showOwner && goal.user && (
                            <span className="text-[11px] text-muted-foreground">{goal.user.name}</span>
                        )}
                        {goal.due_date && (
                            <span className="text-[11px] text-muted-foreground">{formatDate(goal.due_date)}</span>
                        )}
                        {goal.parent_goal && (
                            <span className="text-[11px] text-muted-foreground/60 truncate max-w-[200px]">
                                ↳ {goal.parent_goal.title}
                            </span>
                        )}
                    </div>
                </div>

                {goal.key_results_count > 0 && (
                    <span className="text-[11px] text-muted-foreground shrink-0 flex items-center gap-1">
                        <Key className="h-3 w-3" /> {goal.key_results_count}
                    </span>
                )}

                <div className="w-32 shrink-0 flex items-center gap-2">
                    <div className="flex-1 h-2 rounded-full bg-muted overflow-hidden">
                        <div
                            className={`h-full rounded-full transition-all ${progressColour(goal.progress_percentage)}`}
                            style={{ width: `${goal.progress_percentage}%` }}
                        />
                    </div>
                    <span className="text-xs font-medium tabular-nums w-8 text-right">{goal.progress_percentage}%</span>
                </div>

                <Badge variant="outline" className={`text-[10px] px-1.5 py-0 shrink-0 ${statusBadge[goal.status] || ''}`}>
                    {goal.status}
                </Badge>
            </div>

            {/* Expanded: placeholder for KRs (loaded from show page) */}
            {expanded && goal.key_results_count > 0 && (
                <div className="bg-muted/20 border-t px-4 py-2">
                    <p className="text-xs text-muted-foreground py-2 pl-8">
                        {goal.key_results_count} key result{goal.key_results_count > 1 ? 's' : ''} —{' '}
                        <Link href={`/hr/goals/${goal.id}`} className="text-primary hover:underline">
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

function CascadeItem({ goal, selected, onClick }: { goal: CascadeGoal; selected: boolean; onClick: () => void }) {
    return (
        <button
            onClick={onClick}
            className={`w-full text-left px-3 py-2.5 rounded-lg transition-colors ${
                selected ? 'bg-primary/10 border border-primary/20' : 'hover:bg-muted/50 border border-transparent'
            }`}
        >
            <div className="flex items-center justify-between gap-2">
                <span className={`text-sm truncate ${selected ? 'font-medium text-primary' : ''}`}>{goal.title}</span>
                <span className="text-xs font-medium tabular-nums shrink-0">{goal.progress_percentage}%</span>
            </div>
            <div className="flex items-center gap-2 mt-1">
                <div className="flex-1 h-1.5 rounded-full bg-muted overflow-hidden">
                    <div className={`h-full rounded-full ${progressColour(goal.progress_percentage)}`} style={{ width: `${goal.progress_percentage}%` }} />
                </div>
                {goal.key_results_count > 0 && (
                    <span className="text-[10px] text-muted-foreground">{goal.key_results_count} KRs</span>
                )}
            </div>
            {goal.user && <p className="text-[10px] text-muted-foreground mt-1">{goal.user.name}</p>}
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function GoalsIndex({ goals, users, analytics, cascadeTree, filters, can }: Props) {
    const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(cascadeTree[0]?.id ?? null);
    const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);

    const selectedCompany = cascadeTree.find(g => g.id === selectedCompanyId);
    const teamGoals = selectedCompany?.children ?? [];
    const selectedTeam = teamGoals.find(g => g.id === selectedTeamId);
    const individualGoals = selectedTeam?.children ?? [];

    function onFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get('/hr/goals', {
            ...(merged.status ? { status: merged.status } : {}),
            ...(merged.goal_type ? { goal_type: merged.goal_type } : {}),
            ...(merged.priority ? { priority: merged.priority } : {}),
            ...(merged.user_id ? { user_id: merged.user_id } : {}),
        }, { preserveState: true, preserveScroll: true });
    }

    const chartData = analytics.progress_by_type.map(d => ({
        type: d.type.charAt(0).toUpperCase() + d.type.slice(1),
        progress: d.avg_progress,
    }));

    const completionData = Object.entries(analytics.monthly_completions).map(([month, count]) => ({
        month,
        completed: count,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goals & OKRs" />
            <div className="flex flex-col gap-6 p-6">
                {/* Header — clean and simple */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Goals & OKRs</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            {analytics.active} active · {analytics.completed} completed · {analytics.completion_rate}% completion rate
                        </p>
                    </div>
                    {can.manage && (
                        <Button asChild>
                            <Link href="/hr/goals/create">
                                <Plus className="mr-1.5 h-4 w-4" /> New Objective
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Tabs */}
                <Tabs defaultValue="all">
                    <TabsList className="flex flex-wrap h-auto gap-1 w-full">
                        <TabsTrigger value="all"><Target className="mr-1.5 h-3.5 w-3.5" /> All Objectives</TabsTrigger>
                        <TabsTrigger value="alignment"><Layers className="mr-1.5 h-3.5 w-3.5" /> Alignment</TabsTrigger>
                        <TabsTrigger value="analytics"><BarChart3 className="mr-1.5 h-3.5 w-3.5" /> Analytics</TabsTrigger>
                    </TabsList>

                    {/* ============================================================ */}
                    {/* Tab: All Objectives                                          */}
                    {/* ============================================================ */}
                    <TabsContent value="all" className="space-y-4 mt-4">
                        {/* Filters */}
                        <div className="flex flex-wrap items-center gap-2">
                            <Select value={filters.status || NONE} onValueChange={v => onFilter({ status: v === NONE ? null : v })}>
                                <SelectTrigger className="w-36 h-8 text-xs"><SelectValue placeholder="All Statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={filters.goal_type || NONE} onValueChange={v => onFilter({ goal_type: v === NONE ? null : v })}>
                                <SelectTrigger className="w-36 h-8 text-xs"><SelectValue placeholder="All Types" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Types</SelectItem>
                                    <SelectItem value="company">Company</SelectItem>
                                    <SelectItem value="team">Team</SelectItem>
                                    <SelectItem value="individual">Individual</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={filters.priority || NONE} onValueChange={v => onFilter({ priority: v === NONE ? null : v })}>
                                <SelectTrigger className="w-36 h-8 text-xs"><SelectValue placeholder="All Priorities" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Priorities</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={filters.user_id || NONE} onValueChange={v => onFilter({ user_id: v === NONE ? null : v })}>
                                <SelectTrigger className="w-40 h-8 text-xs"><SelectValue placeholder="All Employees" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Employees</SelectItem>
                                    {users.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Objective list */}
                        <Card>
                            <CardContent className="p-0">
                                {goals.data.length === 0 ? (
                                    <div className="py-16 text-center">
                                        <Target className="mx-auto h-10 w-10 text-muted-foreground/30 mb-3" />
                                        <p className="text-muted-foreground font-medium">No objectives found</p>
                                        <p className="text-sm text-muted-foreground mt-1">Create your first objective to get started.</p>
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {goals.data.map(goal => (
                                            <ObjectiveRow key={goal.id} goal={goal} />
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {goals.links?.length > 3 && <LaravelPagination links={goals.links} />}
                    </TabsContent>

                    {/* ============================================================ */}
                    {/* Tab: Alignment (3-panel cascade)                             */}
                    {/* ============================================================ */}
                    <TabsContent value="alignment" className="mt-4">
                        {cascadeTree.length === 0 ? (
                            <Card>
                                <CardContent className="py-16 text-center">
                                    <Building2 className="mx-auto h-10 w-10 text-muted-foreground/30 mb-3" />
                                    <p className="text-muted-foreground font-medium">No company objectives yet</p>
                                    <p className="text-sm text-muted-foreground mt-1">Create a company-level objective to start cascading goals.</p>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                                {/* Company */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm flex items-center gap-1.5">
                                            <Building2 className="h-4 w-4 text-primary" /> Company Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {cascadeTree.map(g => (
                                            <CascadeItem
                                                key={g.id}
                                                goal={g}
                                                selected={selectedCompanyId === g.id}
                                                onClick={() => { setSelectedCompanyId(g.id); setSelectedTeamId(null); }}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>

                                {/* Team */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm flex items-center gap-1.5">
                                            <Users className="h-4 w-4 text-blue-500" /> Team Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {teamGoals.length === 0 ? (
                                            <p className="text-xs text-muted-foreground py-4 text-center">
                                                {selectedCompanyId ? 'No team goals under this objective' : 'Select a company objective'}
                                            </p>
                                        ) : teamGoals.map(g => (
                                            <CascadeItem
                                                key={g.id}
                                                goal={g}
                                                selected={selectedTeamId === g.id}
                                                onClick={() => setSelectedTeamId(g.id)}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>

                                {/* Individual */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm flex items-center gap-1.5">
                                            <UserIcon className="h-4 w-4 text-emerald-500" /> Individual Objectives
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {individualGoals.length === 0 ? (
                                            <p className="text-xs text-muted-foreground py-4 text-center">
                                                {selectedTeamId ? 'No individual goals under this team' : 'Select a team objective'}
                                            </p>
                                        ) : individualGoals.map(g => (
                                            <CascadeItem key={g.id} goal={g} selected={false} onClick={() => router.visit(`/hr/goals/${g.id}`)} />
                                        ))}
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                    </TabsContent>

                    {/* ============================================================ */}
                    {/* Tab: Analytics                                               */}
                    {/* ============================================================ */}
                    <TabsContent value="analytics" className="space-y-4 mt-4">
                        {/* KPI Cards */}
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-primary/10 p-2"><Target className="h-4 w-4 text-primary" /></div>
                                        <div><p className="text-2xl font-bold">{analytics.active}</p><p className="text-xs text-muted-foreground">Active</p></div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-emerald-500/10 p-2"><CheckCircle2 className="h-4 w-4 text-emerald-500" /></div>
                                        <div><p className="text-2xl font-bold">{analytics.completion_rate}%</p><p className="text-xs text-muted-foreground">Completion</p></div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-blue-500/10 p-2"><TrendingUp className="h-4 w-4 text-blue-500" /></div>
                                        <div><p className="text-2xl font-bold">{analytics.on_track}</p><p className="text-xs text-muted-foreground">On Track</p></div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-amber-500/10 p-2"><AlertTriangle className="h-4 w-4 text-amber-500" /></div>
                                        <div><p className="text-2xl font-bold">{analytics.overdue}</p><p className="text-xs text-muted-foreground">Overdue</p></div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Charts */}
                        <div className="grid lg:grid-cols-2 gap-4">
                            <Card>
                                <CardHeader><CardTitle className="text-base">Progress by Type</CardTitle></CardHeader>
                                <CardContent>
                                    {chartData.length === 0 ? (
                                        <p className="text-sm text-muted-foreground text-center py-8">No active goals yet</p>
                                    ) : (
                                        <ResponsiveContainer width="100%" height={200}>
                                            <BarChart data={chartData} layout="vertical" margin={{ left: 20, right: 20 }}>
                                                <XAxis type="number" domain={[0, 100]} tickFormatter={v => `${v}%`} fontSize={11} />
                                                <YAxis type="category" dataKey="type" fontSize={11} width={80} />
                                                <Tooltip formatter={(v?: number) => `${v ?? 0}%`} />
                                                <Bar dataKey="progress" fill="hsl(var(--primary))" radius={[0, 4, 4, 0]} barSize={20} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader><CardTitle className="text-base">Completion Trend</CardTitle></CardHeader>
                                <CardContent>
                                    {completionData.length === 0 ? (
                                        <p className="text-sm text-muted-foreground text-center py-8">No completed goals yet</p>
                                    ) : (
                                        <ResponsiveContainer width="100%" height={200}>
                                            <AreaChart data={completionData} margin={{ left: 0, right: 0 }}>
                                                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                                <XAxis dataKey="month" fontSize={11} />
                                                <YAxis fontSize={11} allowDecimals={false} />
                                                <Tooltip />
                                                <Area type="monotone" dataKey="completed" stroke="hsl(var(--primary))" fill="hsl(var(--primary))" fillOpacity={0.1} strokeWidth={2} />
                                            </AreaChart>
                                        </ResponsiveContainer>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
