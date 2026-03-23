import { OpsStatCard } from '@/components/ops-stat-card';
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
import { CheckCircle2, ClipboardList, Clock, Eye, ListChecks, Pencil, Plus, Search, UserPlus } from 'lucide-react';

const ANY = '__ANY__';

type OnboardingWorkflow = {
    id: number;
    title: string;
    status: string;
    started_at: string | null;
    completed_at: string | null;
    steps_total: number;
    steps_completed: number;
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
        completed: number;
        pending_steps: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    completed: 'secondary',
    pending: 'outline',
    cancelled: 'destructive',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function OnboardingIndex({ workflows, filters, stats }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/onboarding', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Onboarding" />
            <PageHeader
                title="Onboarding"
                description="Manage client and staff onboarding workflows."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard label="Active Workflows" value={stats?.active ?? 0} icon={UserPlus} color="indigo" />
                    <OpsStatCard label="Completed" value={stats?.completed ?? 0} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Pending Steps" value={stats?.pending_steps ?? 0} icon={Clock} color="amber" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search onboarding workflows..."
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
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/onboarding/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Workflow
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {workflows.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <ClipboardList className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Onboarding Workflows</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first onboarding workflow to get started.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/onboarding/create">Create Workflow</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {workflows.data.map((wf) => {
                        const pct = wf.steps_total > 0 ? Math.round((wf.steps_completed / wf.steps_total) * 100) : 0;
                        return (
                            <Card key={wf.id} className="transition-all hover:border-border hover:shadow-sm">
                                <CardContent className="flex items-center gap-4 p-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        <ListChecks className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <Link href={`/operations/onboarding/${wf.id}`} className="text-sm font-semibold hover:underline">
                                                {wf.title}
                                            </Link>
                                            <Badge variant={STATUS_VARIANTS[wf.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                                {wf.status}
                                            </Badge>
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                            {wf.client && (
                                                <span>{wf.client.first_name} {wf.client.last_name}</span>
                                            )}
                                            {wf.assigned_to && <span>Assigned: {wf.assigned_to.name}</span>}
                                            {wf.started_at && <span>Started: {formatDate(wf.started_at)}</span>}
                                        </div>
                                        {/* Progress bar */}
                                        <div className="mt-2 flex items-center gap-2">
                                            <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                <div
                                                    className="h-1.5 rounded-full bg-indigo-500 transition-all"
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                            <span className="text-[10px] tabular-nums text-muted-foreground">
                                                {wf.steps_completed}/{wf.steps_total} steps ({pct}%)
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/onboarding/${wf.id}`}>
                                                <Eye className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/onboarding/${wf.id}/edit`}>
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {workflows.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {workflows.links.map((link: any, i: number) => (
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
