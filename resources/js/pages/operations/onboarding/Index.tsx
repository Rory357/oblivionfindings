import { DonutChart, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, ExternalLink, ListChecks, Search, Timer, UserPlus, Users } from 'lucide-react';

const ANY = '__ANY__';

type OnboardingWorkflow = {
    id: number;
    status: string;
    started_at: string | null;
    completed_at: string | null;
    steps_total: number;
    steps_completed: number;
    steps_count: number;
    completed_steps_count: number;
    overdue_steps: number;
    client: { id: number; first_name: string; last_name: string } | null;
    assigned_to: { id: number; name: string } | null;
};

type Props = {
    workflows: {
        data: OnboardingWorkflow[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        active: number;
        completed_this_month: number;
        overdue_steps: number;
        avg_days: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    in_progress: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function OnboardingDashboard({ workflows = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any }: Props) {
    const { labels } = usePage().props as any;
    const clientLabel = labels?.['client.singular'] ?? 'Client';
    const clientsLabel = labels?.['client.plural'] ?? 'Clients';

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/onboarding', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    // Pipeline funnel data for donut chart
    const activeCount = stats?.active ?? 0;
    const completedCount = stats?.completed_this_month ?? 0;
    const overdueCount = stats?.overdue_steps ?? 0;

    return (
        <AppLayout>
            <Head title="Onboarding Pipeline" />
            <PageHeader
                title="Onboarding Pipeline"
                description={`Overview of all ${clientsLabel.toLowerCase()} currently being onboarded.`}
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Active Workflows" value={stats?.active ?? 0} icon={UserPlus} color="indigo" />
                    <OpsStatCard label="Completed This Month" value={stats?.completed_this_month ?? 0} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Overdue Steps" value={stats?.overdue_steps ?? 0} icon={AlertTriangle} color="red" />
                    <OpsStatCard label="Avg Days to Complete" value={stats?.avg_days ?? 0} icon={Timer} color="blue" />
                </div>

                {/* Pipeline Chart + Filters Row */}
                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Pipeline Donut */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Pipeline Overview</CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-center pb-4">
                            <DonutChart
                                segments={[
                                    { label: 'In Progress', value: activeCount, color: '#6366f1' },
                                    { label: 'Completed', value: completedCount, color: '#10b981' },
                                    { label: 'Overdue Steps', value: overdueCount, color: '#ef4444' },
                                ]}
                                centerLabel="Total"
                                centerValue={activeCount + completedCount}
                                size={140}
                            />
                        </CardContent>
                    </Card>

                    {/* Workflow List */}
                    <div className="lg:col-span-2">
                        {/* Filters */}
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                <Input
                                    placeholder={`Search ${clientsLabel.toLowerCase()}...`}
                                    className="h-9 pl-8 text-sm"
                                    defaultValue={filters?.q ?? ''}
                                    onChange={(e) => updateFilters('q', e.target.value || null)}
                                />
                            </div>
                            <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                                <SelectTrigger className="h-9 w-[130px] text-xs">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>All Status</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* List */}
                        <div className="mt-3 space-y-2">
                            {(workflows?.data ?? []).length === 0 && (
                                <Card>
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <Users className="mb-3 h-10 w-10 text-muted-foreground/30" />
                                        <h2 className="text-base font-semibold text-muted-foreground">No Onboarding Workflows</h2>
                                        <p className="mt-1 text-sm text-muted-foreground/80">
                                            Onboarding workflows are created automatically when a new {clientLabel.toLowerCase()} is added.
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                            {(workflows?.data ?? []).map((wf) => {
                                const stepsTotal = wf.steps_total || wf.steps_count || 0;
                                const stepsCompleted = wf.steps_completed || wf.completed_steps_count || 0;
                                const pct = stepsTotal > 0 ? Math.round((stepsCompleted / stepsTotal) * 100) : 0;
                                const hasOverdue = (wf.overdue_steps ?? 0) > 0;
                                return (
                                    <Card key={wf.id} className={`transition-all hover:border-border hover:shadow-sm ${hasOverdue ? 'border-red-200 dark:border-red-900/40' : ''}`}>
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${hasOverdue ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70'}`}>
                                                {hasOverdue ? <AlertTriangle className="h-5 w-5" /> : <ListChecks className="h-5 w-5" />}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-semibold">
                                                        {wf.client ? `${wf.client.first_name} ${wf.client.last_name}` : `Workflow #${wf.id}`}
                                                    </span>
                                                    <Badge variant={STATUS_VARIANTS[wf.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                                        {wf.status?.replace('_', ' ')}
                                                    </Badge>
                                                    {hasOverdue && (
                                                        <Badge variant="destructive" className="h-4 px-1.5 text-[9px]">
                                                            {wf.overdue_steps} overdue
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {wf.assigned_to && <span>Coordinator: {wf.assigned_to.name}</span>}
                                                    {wf.started_at && <span>Started: {formatDate(wf.started_at)}</span>}
                                                </div>
                                                {/* Progress bar */}
                                                <div className="mt-2 flex items-center gap-2">
                                                    <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                        <div
                                                            className={`h-1.5 rounded-full transition-all ${hasOverdue ? 'bg-red-500' : 'bg-primary'}`}
                                                            style={{ width: `${pct}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] tabular-nums text-muted-foreground">
                                                        {stepsCompleted}/{stepsTotal} ({pct}%)
                                                    </span>
                                                </div>
                                            </div>
                                            <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs">
                                                <Link href={wf.client ? `/operations/clients/${wf.client.id}?tab=onboarding` : '#'}>
                                                    <ExternalLink className="h-3 w-3" />
                                                    View {clientLabel}
                                                </Link>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* Pagination */}
                        {(workflows?.last_page ?? 1) > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {(workflows?.links ?? []).map((link: any, i: number) => (
                                    <Button
                                        key={i}
                                        size="sm"
                                        variant={link.active ? 'default' : 'outline'}
                                        className="h-7 min-w-[28px] px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
