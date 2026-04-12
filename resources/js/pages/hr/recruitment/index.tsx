import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Users,
    Search,
    Plus,
    Clock,
    ArrowRight,
    BarChart3,
    LayoutGrid,
    List,
    Briefcase,
    CalendarDays,
    FileText,
    AlertTriangle,
    Send,
    TrendingUp,
    UserPlus,
    Eye,
} from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, statusConfig, stageLabels } from '@/components/recruitment/status-badge';
import { KpiCard } from '@/components/recruitment/kpi-card';
import { ActivityItem } from '@/components/recruitment/activity-item';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';

type Candidate = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    personal_email: string;
    personal_phone?: string | null;
    source: string;
    source_detail?: string | null;
    status: string;
    created_at: string;
};

type PaginatedCandidates = {
    data: Candidate[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Pipeline = Record<string, number>;

type Activity = {
    type: 'status_change' | 'interview' | 'offer' | 'note' | 'application';
    description: string;
    timestamp: string;
    candidate_id?: number;
};

type UrgentItem = {
    type: string;
    severity: 'warning' | 'danger';
    description: string;
};

type Props = {
    candidates: PaginatedCandidates;
    pipeline: Pipeline;
    sourceBreakdown: Record<string, number>;
    todayStats: {
        total_active: number;
        new_this_week: number;
        interviews_today: number;
        offers_pending: number;
        avg_days_in_stage: number;
    };
    recentActivity: Activity[];
    urgentItems: UrgentItem[];
    filters: { search: string; source: string; status: string };
    can: Record<string, boolean>;
};

const CHART_COLORS = ['#3b82f6', '#6366f1', '#f59e0b', '#f97316', '#a855f7', '#06b6d4', '#14b8a6', '#10b981', '#84cc16', '#22c55e', '#64748b', '#ef4444'];

function getInitials(first: string, last: string) {
    return ((first?.[0] ?? '') + (last?.[0] ?? '')).toUpperCase();
}

export default function RecruitmentIndex({ candidates, pipeline, sourceBreakdown, todayStats, recentActivity, urgentItems, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [source, setSource] = useState(filters.source ?? '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/hr/recruitment', { search: search || undefined, source: source || undefined }, { preserveState: true, replace: true });
    };

    const filterByStage = (stage: string) => {
        router.get('/hr/recruitment', { status: stage }, { preserveState: true, replace: true });
    };

    const pipelineEntries = Object.entries(pipeline).filter(([status]) => !['withdrawn', 'rejected', 'hired'].includes(status));
    const totalActive = pipelineEntries.reduce((sum, [, count]) => sum + count, 0);
    const maxPipeline = Math.max(...pipelineEntries.map(([, count]) => count), 1);

    const sourceData = Object.entries(sourceBreakdown).map(([key, val], i) => ({
        name: key.replace(/_/g, ' '),
        value: val,
        fill: CHART_COLORS[i % CHART_COLORS.length],
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
            ]}
        >
            <Head title="Recruitment Pipeline" />
            <PageShell>
                <PageHeader
                    title="Recruitment Pipeline"
                    description="Track candidates through the hiring process."
                    actions={
                        can.manage ? (
                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/hr/recruitment/kanban">
                                        <LayoutGrid className="mr-2 h-4 w-4" />
                                        Kanban
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/hr/recruitment/analytics">
                                        <BarChart3 className="mr-2 h-4 w-4" />
                                        Analytics
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/hr/recruitment/jobs">
                                        <Briefcase className="mr-2 h-4 w-4" />
                                        Jobs
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/hr/recruitment/kits">
                                        <FileText className="mr-2 h-4 w-4" />
                                        Interview Kits
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href="/hr/recruitment/candidates/create">
                                        <Plus className="w-4 h-4 mr-2" />
                                        Add Candidate
                                    </Link>
                                </Button>
                            </div>
                        ) : undefined
                    }
                />

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <KpiCard label="Active Candidates" value={todayStats.total_active} icon={Users} color="bg-blue-500/10 text-blue-500" />
                    <KpiCard label="New This Week" value={todayStats.new_this_week} icon={UserPlus} color="bg-indigo-500/10 text-indigo-500" />
                    <KpiCard label="Interviews Today" value={todayStats.interviews_today} icon={CalendarDays} color="bg-amber-500/10 text-amber-500" />
                    <KpiCard label="Offers Pending" value={todayStats.offers_pending} icon={Send} color="bg-emerald-500/10 text-emerald-500" />
                    <KpiCard label="Avg Days in Stage" value={todayStats.avg_days_in_stage} icon={Clock} decimals={1} color="bg-purple-500/10 text-purple-500" />
                </div>

                {/* Main Content Grid */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Left: Pipeline + Table */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Pipeline Funnel */}
                        {pipelineEntries.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <TrendingUp className="h-4 w-4" />
                                        Pipeline Overview
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {pipelineEntries.map(([status, count]) => {
                                        const config = statusConfig[status];
                                        const pct = totalActive > 0 ? Math.round((count / totalActive) * 100) : 0;
                                        return (
                                            <button
                                                key={status}
                                                type="button"
                                                onClick={() => filterByStage(status)}
                                                className="flex items-center gap-3 w-full group hover:bg-muted/50 rounded-lg px-2 py-1.5 transition-colors"
                                            >
                                                <span className="text-xs w-24 text-left truncate font-medium">{stageLabels[status] ?? status}</span>
                                                <div className="flex-1 bg-muted/30 rounded-full h-7 overflow-hidden relative">
                                                    <div
                                                        className={`h-full rounded-full transition-all duration-500 flex items-center px-3 ${config?.bgClass ?? 'bg-slate-500'}/20`}
                                                        style={{ width: `${Math.max((count / maxPipeline) * 100, 8)}%` }}
                                                    >
                                                        <span className="text-xs font-bold">{count}</span>
                                                    </div>
                                                </div>
                                                <span className="text-xs text-muted-foreground w-10 text-right">{pct}%</span>
                                            </button>
                                        );
                                    })}
                                </CardContent>
                            </Card>
                        )}

                        {/* Search & Filter */}
                        <form onSubmit={handleSearch} className="flex flex-wrap items-center gap-2">
                            <div className="relative flex-1 max-w-sm">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input placeholder="Search candidates..." value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
                            </div>
                            <Select value={source || 'all'} onValueChange={(value) => setSource(value === 'all' ? '' : value)}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All sources" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All sources</SelectItem>
                                    {Object.entries(sourceBreakdown).map(([sourceKey, count]) => (
                                        <SelectItem key={sourceKey} value={sourceKey}>
                                            {sourceKey.replace(/_/g, ' ')} ({count})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button type="submit" variant="secondary" size="sm">Search</Button>
                            {(filters.search || filters.source || filters.status) && (
                                <Button type="button" variant="ghost" size="sm" onClick={() => {
                                    setSearch(''); setSource('');
                                    router.get('/hr/recruitment', {}, { preserveState: true, replace: true });
                                }}>
                                    Clear
                                </Button>
                            )}
                            {filters.status && (
                                <Badge variant="secondary" className="gap-1">
                                    Filtered: {stageLabels[filters.status] ?? filters.status}
                                    <button onClick={() => router.get('/hr/recruitment', {}, { preserveState: true, replace: true })} className="ml-1 hover:text-foreground">&times;</button>
                                </Badge>
                            )}
                        </form>

                        {/* Candidates Table */}
                        {candidates.total === 0 && !filters.search && !filters.source && !filters.status ? (
                            <Card>
                                <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                                    <Users className="mb-4 h-12 w-12 text-muted-foreground/50" />
                                    <h3 className="mb-2 text-lg font-semibold">No candidates in the pipeline yet</h3>
                                    <p className="mb-6 max-w-sm text-sm text-muted-foreground">
                                        Create a job posting to start recruiting. Candidates will appear here as they apply or are added manually.
                                    </p>
                                    {can.manage && (
                                        <div className="flex items-center gap-2">
                                            <Button variant="outline" asChild>
                                                <Link href="/hr/recruitment/jobs"><Briefcase className="mr-2 h-4 w-4" />View Jobs</Link>
                                            </Button>
                                            <Button asChild>
                                                <Link href="/hr/recruitment/candidates/create"><Plus className="mr-2 h-4 w-4" />Add Candidate</Link>
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ) : (
                            <>
                                <div className="overflow-hidden rounded-xl border">
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                <th className="px-4 py-3 text-left font-medium">Candidate</th>
                                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                                <th className="px-4 py-3 text-left font-medium">Source</th>
                                                <th className="px-4 py-3 text-left font-medium">Applied</th>
                                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {candidates.data.length === 0 ? (
                                                <tr>
                                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                                        <Search className="mx-auto mb-3 h-10 w-10 opacity-50" />
                                                        <p>No candidates match your filters.</p>
                                                    </td>
                                                </tr>
                                            ) : (
                                                candidates.data.map((candidate) => (
                                                    <tr key={candidate.id} className="border-b last:border-b-0 hover:bg-muted/50 group">
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-3">
                                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                                                    {getInitials(candidate.first_name, candidate.last_name)}
                                                                </div>
                                                                <div>
                                                                    <Link href={`/hr/recruitment/candidates/${candidate.id}`} className="font-medium hover:underline group-hover:text-primary transition-colors">
                                                                        {candidate.first_name} {candidate.last_name}
                                                                    </Link>
                                                                    <p className="text-xs text-muted-foreground">{candidate.personal_email}</p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3"><StatusBadge status={candidate.status} /></td>
                                                        <td className="px-4 py-3 text-muted-foreground capitalize text-xs">
                                                            {candidate.source?.replace(/_/g, ' ') || '-'}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground text-xs">
                                                            {new Date(candidate.created_at).toLocaleDateString('en-NZ')}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Button variant="ghost" size="sm" asChild>
                                                                <Link href={`/hr/recruitment/candidates/${candidate.id}`}>
                                                                    <Eye className="mr-1 h-3.5 w-3.5" />
                                                                    View
                                                                </Link>
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="flex items-center justify-between">
                                    <div className="text-sm text-muted-foreground">
                                        {candidates.total > 0
                                            ? `Showing ${(candidates.current_page - 1) * candidates.per_page + 1}-${Math.min(candidates.current_page * candidates.per_page, candidates.total)} of ${candidates.total}`
                                            : `${candidates.total} candidates`}
                                    </div>
                                    {candidates.last_page > 1 && <LaravelPagination links={candidates.links} />}
                                </div>
                            </>
                        )}
                    </div>

                    {/* Right Sidebar */}
                    <div className="space-y-6">
                        {/* Source Distribution */}
                        {sourceData.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">Source Distribution</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="h-48">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie data={sourceData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={45} outerRadius={70} paddingAngle={2}>
                                                    {sourceData.map((entry, i) => (
                                                        <Cell key={i} fill={entry.fill} />
                                                    ))}
                                                </Pie>
                                                <Tooltip formatter={(val?: number) => val ?? 0} />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="space-y-1.5 mt-2">
                                        {sourceData.map((entry, i) => (
                                            <div key={i} className="flex items-center justify-between text-xs">
                                                <span className="flex items-center gap-2">
                                                    <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ backgroundColor: entry.fill }} />
                                                    <span className="capitalize">{entry.name}</span>
                                                </span>
                                                <span className="font-medium">{entry.value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Urgent Items */}
                        {urgentItems.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                                        Needs Attention
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {urgentItems.map((item, i) => (
                                        <div key={i} className={`rounded-lg p-2.5 text-xs ${
                                            item.severity === 'danger'
                                                ? 'bg-red-500/10 text-red-400 border border-red-500/20'
                                                : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                                        }`}>
                                            {item.description}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {/* Recent Activity */}
                        {recentActivity.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">Recent Activity</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-0">
                                        {recentActivity.slice(0, 8).map((activity, i) => (
                                            <ActivityItem
                                                key={i}
                                                type={activity.type}
                                                description={activity.description}
                                                timestamp={activity.timestamp}
                                            />
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
