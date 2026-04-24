import { CarePlanSummaryCard } from '@/components/care-plan-summary-card';
import { BarChart, DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
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
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileWarning,
    Heart,
    Plus,
    Search,
    Sparkles,
    Target,
    TrendingUp,
} from 'lucide-react';

const ANY = '__ANY__';

type Plan = {
    id: number;
    title: string;
    status: string;
    plan_type: string;
    starts_at: string | null;
    ends_at: string | null;
    next_review_at: string | null;
    version: number;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
    goals_count: number;
    goals_achieved_count: number;
};

type Props = {
    carePlans: {
        data: Plan[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    clients: { id: number; first_name: string; last_name: string }[];
    filters: {
        q?: string;
        status?: string;
        plan_type?: string;
        client_id?: string;
        review_due?: string;
    };
    stats: {
        total: number;
        active: number;
        review_due: number;
        draft: number;
        in_review: number;
        plans_without_goals: number;
        overdue_goals: number;
    };
    plans_by_status: Record<string, number>;
};

const PLAN_TYPES: Record<string, string> = {
    support_plan: 'Support Plan',
    behaviour_plan: 'Behaviour Plan',
    health_plan: 'Health Plan',
    transition_plan: 'Transition Plan',
};

const STATUS_DONUT_COLORS: Record<string, string> = {
    active: OPS_COLORS.success,
    draft: OPS_COLORS.neutral,
    review: OPS_COLORS.warning,
    archived: OPS_COLORS.muted,
};

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    draft: 'Draft',
    review: 'In Review',
    archived: 'Archived',
};

export default function CarePlansIndex({
    carePlans = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    clients = [],
    filters = {} as any,
    stats = {} as any,
    plans_by_status = {} as any,
}: Props) {
    const { labels } = usePage().props as any;
    const clientLabel = labels?.['client.singular'] ?? 'Client';
    const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/care-plans', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const s = {
        total: stats?.total ?? 0,
        active: stats?.active ?? 0,
        review_due: stats?.review_due ?? 0,
        draft: stats?.draft ?? 0,
        in_review: stats?.in_review ?? 0,
        plans_without_goals: stats?.plans_without_goals ?? 0,
        overdue_goals: stats?.overdue_goals ?? 0,
    };

    const donutSegments = Object.entries(plans_by_status ?? {}).map(([key, value]) => ({
        label: STATUS_LABELS[key] ?? key,
        value: (value as number) ?? 0,
        color: STATUS_DONUT_COLORS[key] ?? OPS_COLORS.muted,
    }));

    // Quick insight bar chart — plans by type
    const plansByType = (carePlans?.data ?? []).reduce(
        (acc: Record<string, number>, p) => {
            const t = PLAN_TYPES[p.plan_type] ?? p.plan_type;
            acc[t] = (acc[t] ?? 0) + 1;
            return acc;
        },
        {},
    );
    const typeBarData = Object.entries(plansByType).map(([label, value]) => ({ label, value }));

    const showComplianceBanner = s.review_due > 0 || s.plans_without_goals > 0;
    const completionRate = s.total > 0 ? Math.round((s.active / s.total) * 100) : 0;

    return (
        <AppLayout>
            <Head title="Care Plans" />
            <PageHeader
                title="Care Plans"
                description="Manage support plans, behaviour plans, health plans, and transition plans."
                backHref="/operations"
                actions={
                    <Button asChild size="sm">
                        <Link href="/operations/care-plans/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Plan
                        </Link>
                    </Button>
                }
            />
            <PageShell>
                {/* ─── Stats Row ─── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <OpsStatCard label="Total Plans" value={s.total} icon={ClipboardList} color="indigo" />
                    <OpsStatCard label="Active" value={s.active} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Review Due" value={s.review_due} icon={AlertTriangle} color="amber" />
                    <OpsStatCard label="Draft" value={s.draft} icon={ClipboardCheck} color="slate" />
                    <OpsStatCard label="Without Goals" value={s.plans_without_goals} icon={Target} color="red" />
                    <OpsStatCard label="Overdue Goals" value={s.overdue_goals} icon={FileWarning} color="red" />
                </div>

                {/* ─── Compliance Alert Banner ─── */}
                {showComplianceBanner && (
                    <div className="flex items-start gap-3 rounded-xl border border-status-warning/30 bg-status-warning-bg p-4 shadow-sm dark:border-status-warning/30 dark:from-amber-950/30 dark:to-orange-950/30">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-status-warning dark:text-status-warning">Compliance Attention Required</p>
                            <p className="mt-0.5 text-xs text-status-warning dark:text-status-warning">
                                {s.review_due > 0 && <span>{s.review_due} plan{s.review_due !== 1 ? 's' : ''} overdue for review. </span>}
                                {s.plans_without_goals > 0 && <span>{s.plans_without_goals} plan{s.plans_without_goals !== 1 ? 's' : ''} have no goals defined.</span>}
                            </p>
                            <div className="mt-2.5 flex gap-2">
                                {s.review_due > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 border-status-warning/30 text-xs font-medium text-status-warning hover:bg-status-warning-bg"
                                        onClick={() => updateFilters('review_due', '1')}
                                    >
                                        View Due Reviews
                                    </Button>
                                )}
                                {s.plans_without_goals > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 border-status-warning/30 text-xs font-medium text-status-warning hover:bg-status-warning-bg"
                                        onClick={() => updateFilters('status', 'active')}
                                    >
                                        View Plans Without Goals
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* ─── Charts + Insights Row ─── */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Donut Chart */}
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <ClipboardList className="h-3.5 w-3.5" />
                                </div>
                                Plans by Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center pb-4">
                            {s.total > 0 ? (
                                <DonutChart segments={donutSegments} centerLabel="Total" centerValue={s.total} size={150} />
                            ) : (
                                <div className="flex h-[150px] items-center justify-center text-xs text-muted-foreground">
                                    No data yet
                                </div>
                            )}
                        </CardContent>
                        {/* Legend */}
                        {s.total > 0 && (
                            <div className="border-t bg-muted/50 px-4 py-2.5">
                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                    {donutSegments.filter(seg => seg.value > 0).map((seg) => (
                                        <div key={seg.label} className="flex items-center gap-1.5 text-[11px]">
                                            <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: seg.color }} />
                                            <span className="text-muted-foreground">{seg.label}</span>
                                            <span className="font-semibold">{seg.value}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Card>

                    {/* Quick Insights */}
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-status-success-bg text-status-success">
                                    <TrendingUp className="h-3.5 w-3.5" />
                                </div>
                                Quick Insights
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 pb-4">
                            {/* Active rate */}
                            <div>
                                <div className="flex items-center justify-between text-xs">
                                    <span className="text-muted-foreground">Active Plan Rate</span>
                                    <span className="font-bold text-status-success">{completionRate}%</span>
                                </div>
                                <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-status-success transition-all" style={{ width: `${completionRate}%` }} />
                                </div>
                            </div>
                            {/* Key metrics */}
                            <div className="grid grid-cols-2 gap-2">
                                <div className="rounded-lg bg-muted p-2.5 text-center">
                                    <div className="text-lg font-bold text-primary">{s.active}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Active</div>
                                </div>
                                <div className="rounded-lg bg-muted p-2.5 text-center">
                                    <div className="text-lg font-bold text-status-warning">{s.in_review}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">In Review</div>
                                </div>
                                <div className="rounded-lg bg-muted p-2.5 text-center">
                                    <div className="text-lg font-bold text-muted-foreground">{s.draft}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Drafts</div>
                                </div>
                                <div className="rounded-lg bg-muted p-2.5 text-center">
                                    <div className={`text-lg font-bold ${s.overdue_goals > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}>{s.overdue_goals}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Overdue Goals</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Plans by Type */}
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <Sparkles className="h-3.5 w-3.5" />
                                </div>
                                Plans by Type
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pb-4">
                            {typeBarData.length > 0 ? (
                                <BarChart data={typeBarData} height={130} barColor={OPS_COLORS.primary} />
                            ) : (
                                <div className="flex h-[130px] items-center justify-center text-xs text-muted-foreground">
                                    Create plans to see analytics
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ─── Filter Bar ─── */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-white/50 p-3 shadow-sm dark:bg-muted/50">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search care plans..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="review">In Review</SelectItem>
                            <SelectItem value="archived">Archived</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters?.plan_type ?? ANY} onValueChange={(v) => updateFilters('plan_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[150px] text-xs"><SelectValue placeholder="Plan Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(PLAN_TYPES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters?.client_id ?? ANY} onValueChange={(v) => updateFilters('client_id', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[160px] text-xs"><SelectValue placeholder={`All ${clientLabelPlural}`} /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All {clientLabelPlural}</SelectItem>
                            {(clients ?? []).map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        size="sm"
                        variant={filters?.review_due ? 'default' : 'outline'}
                        className={`h-9 gap-1 text-xs ${filters?.review_due ? '' : 'text-status-warning border-status-warning/30 hover:bg-status-warning-bg'}`}
                        onClick={() => updateFilters('review_due', filters?.review_due ? null : '1')}
                    >
                        <AlertTriangle className="h-3.5 w-3.5" />
                        Review Due
                    </Button>
                </div>

                {/* ─── Card List ─── */}
                <div className="space-y-2">
                    {(carePlans?.data ?? []).length === 0 && (
                        <Card className="border-dashed">
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                    <Heart className="h-8 w-8 text-primary" />
                                </div>
                                <h2 className="text-lg font-semibold">No Care Plans Found</h2>
                                <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">
                                    {filters?.q || filters?.status || filters?.plan_type || filters?.client_id
                                        ? 'No plans match your current filters. Try adjusting your search criteria.'
                                        : `Create your first care plan to start tracking goals, support needs, and progress for your ${clientLabelPlural.toLowerCase()}.`}
                                </p>
                                {!filters?.q && !filters?.status && (
                                    <Button asChild size="sm" className="mt-4">
                                        <Link href="/operations/care-plans/create">
                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                            Create Care Plan
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    {(carePlans?.data ?? []).map((plan) => (
                        <CarePlanSummaryCard key={plan.id} plan={plan} showClient />
                    ))}
                </div>

                {/* ─── Pagination ─── */}
                {(carePlans?.last_page ?? 1) > 1 && (
                    <div className="flex flex-col items-center gap-2">
                        <div className="flex items-center justify-center gap-1">
                            {(carePlans?.links ?? []).map((link: any, i: number) => (
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
                        <p className="text-xs text-muted-foreground">
                            Showing page {carePlans?.current_page ?? 1} of {carePlans?.last_page ?? 1} ({carePlans?.total ?? 0} plans)
                        </p>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
