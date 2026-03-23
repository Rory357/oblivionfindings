import { OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, CheckCircle2, ClipboardCheck, Eye, Pencil, Plus, Search } from 'lucide-react';

const ANY = '__ANY__';

type CarePlan = {
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
        data: CarePlan[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
        plan_type?: string;
        review_due?: string;
    };
    stats: {
        total: number;
        active: number;
        review_due: number;
        draft: number;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    draft: 'outline',
    review: 'secondary',
    archived: 'secondary',
};

const PLAN_TYPES: Record<string, string> = {
    support_plan: 'Support Plan',
    behaviour_plan: 'Behaviour Plan',
    health_plan: 'Health Plan',
    transition_plan: 'Transition Plan',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function CarePlansIndex({ carePlans, filters, stats, clients }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/care-plans', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Care Plans" />
            <PageHeader
                title="Care Plans"
                description="Manage support plans, behaviour plans, health plans, and transition plans."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Total Plans" value={stats?.total ?? 0} icon={ClipboardCheck} color="indigo" />
                    <OpsStatCard label="Active" value={stats?.active ?? 0} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Review Due" value={stats?.review_due ?? 0} icon={AlertTriangle} color={stats?.review_due > 0 ? 'amber' : 'slate'} />
                    <OpsStatCard label="Draft" value={stats?.draft ?? 0} icon={ClipboardCheck} color="slate" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search care plans..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
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
                    <Select value={filters.plan_type ?? ANY} onValueChange={(v) => updateFilters('plan_type', v === ANY ? null : v)}>
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
                    <Button asChild size="sm">
                        <Link href="/operations/care-plans/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Plan
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {carePlans.data.length === 0 && (
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
                    {carePlans.data.map((plan) => (
                        <Card key={plan.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <ClipboardCheck className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/care-plans/${plan.id}`} className="text-sm font-semibold hover:underline">
                                            {plan.title}
                                        </Link>
                                        <Badge variant={STATUS_VARIANTS[plan.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                            {plan.status}
                                        </Badge>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                            {PLAN_TYPES[plan.plan_type] ?? plan.plan_type}
                                        </Badge>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {plan.client && (
                                            <span>{plan.client.first_name} {plan.client.last_name}</span>
                                        )}
                                        {plan.starts_at && (
                                            <span className="flex items-center gap-1">
                                                <CalendarDays className="h-3 w-3" />
                                                {formatDate(plan.starts_at)} - {formatDate(plan.ends_at)}
                                            </span>
                                        )}
                                        <span>{plan.goals_count} goals ({plan.goals_achieved_count} achieved)</span>
                                        {plan.next_review_at && (
                                            <span className={new Date(plan.next_review_at) <= new Date() ? 'font-medium text-amber-600' : ''}>
                                                Review: {formatDate(plan.next_review_at)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/care-plans/${plan.id}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/care-plans/${plan.id}/edit`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {carePlans.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {carePlans.links.map((link: any, i: number) => (
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
            </PageShell>
        </AppLayout>
    );
}
