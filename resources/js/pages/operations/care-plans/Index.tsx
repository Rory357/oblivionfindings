import { CarePlanSummaryCard } from '@/components/care-plan-summary-card';
import { DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
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
    FileWarning,
    Plus,
    Search,
    Target,
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

export default function CarePlansIndex({
    carePlans = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    clients = [],
    filters = {} as any,
    stats = {} as any,
    plans_by_status = {} as any,
}: Props) {
    const { labels } = usePage().props as any;
    const clientLabel = labels?.['client.singular'] ?? 'Client';

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/care-plans', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const safeStats = {
        total: stats?.total ?? 0,
        active: stats?.active ?? 0,
        review_due: stats?.review_due ?? 0,
        draft: stats?.draft ?? 0,
        in_review: stats?.in_review ?? 0,
        plans_without_goals: stats?.plans_without_goals ?? 0,
        overdue_goals: stats?.overdue_goals ?? 0,
    };

    const donutSegments = Object.entries(plans_by_status ?? {}).map(([key, value]) => ({
        label: key.charAt(0).toUpperCase() + key.slice(1),
        value: (value as number) ?? 0,
        color: STATUS_DONUT_COLORS[key] ?? OPS_COLORS.muted,
    }));

    const showComplianceBanner = safeStats.review_due > 0 || safeStats.plans_without_goals > 0;

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
                {/* Stats Row */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <OpsStatCard label="Total Plans" value={safeStats.total} icon={ClipboardCheck} color="indigo" />
                    <OpsStatCard label="Active" value={safeStats.active} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Review Due" value={safeStats.review_due} icon={AlertTriangle} color="amber" />
                    <OpsStatCard label="Draft" value={safeStats.draft} icon={ClipboardCheck} color="slate" />
                    <OpsStatCard label="Without Goals" value={safeStats.plans_without_goals} icon={Target} color="red" />
                    <OpsStatCard label="Overdue Goals" value={safeStats.overdue_goals} icon={FileWarning} color="red" />
                </div>

                {/* Compliance Alert Banner */}
                {showComplianceBanner && (
                    <div className="mt-4 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-amber-800 dark:text-amber-200">Compliance Attention Required</p>
                            <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-300">
                                {safeStats.review_due > 0 && (
                                    <span>{safeStats.review_due} plan{safeStats.review_due !== 1 ? 's' : ''} due for review. </span>
                                )}
                                {safeStats.plans_without_goals > 0 && (
                                    <span>{safeStats.plans_without_goals} plan{safeStats.plans_without_goals !== 1 ? 's' : ''} have no goals defined. </span>
                                )}
                            </p>
                            <div className="mt-2 flex gap-2">
                                {safeStats.review_due > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 border-amber-400 text-xs text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-300"
                                        onClick={() => updateFilters('review_due', '1')}
                                    >
                                        View Due Reviews
                                    </Button>
                                )}
                                {safeStats.plans_without_goals > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 border-amber-400 text-xs text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-300"
                                        onClick={() => updateFilters('status', 'active')}
                                    >
                                        View Plans Without Goals
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Charts Row */}
                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Plans by Status</CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center pb-4">
                            <DonutChart
                                segments={donutSegments}
                                centerLabel="Total"
                                centerValue={safeStats.total}
                                size={140}
                            />
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Analytics</CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-center py-8">
                            <p className="text-xs text-muted-foreground">Additional analytics coming soon</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Bar */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
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
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="review">Review</SelectItem>
                            <SelectItem value="archived">Archived</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters?.plan_type ?? ANY} onValueChange={(v) => updateFilters('plan_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[150px] text-xs">
                            <SelectValue placeholder="Plan Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(PLAN_TYPES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters?.client_id ?? ANY} onValueChange={(v) => updateFilters('client_id', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[160px] text-xs">
                            <SelectValue placeholder={`All ${clientLabel}s`} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All {clientLabel}s</SelectItem>
                            {(clients ?? []).map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.first_name} {c.last_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        size="sm"
                        variant={filters?.review_due ? 'default' : 'outline'}
                        className="h-9 gap-1 text-xs"
                        onClick={() => updateFilters('review_due', filters?.review_due ? null : '1')}
                    >
                        <AlertTriangle className="h-3.5 w-3.5" />
                        Review Due
                    </Button>
                </div>

                {/* Card List */}
                <div className="mt-4 space-y-2">
                    {(carePlans?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <ClipboardCheck className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Care Plans Found</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first care plan to get started.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/care-plans/create">Create Care Plan</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(carePlans?.data ?? []).map((plan) => (
                        <CarePlanSummaryCard key={plan.id} plan={plan} showClient />
                    ))}
                </div>

                {/* Pagination */}
                {(carePlans?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex flex-col items-center gap-2">
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
