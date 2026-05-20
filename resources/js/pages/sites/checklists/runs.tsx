import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    ClipboardCheck,
    Eye,
    PlayCircle,
    RotateCw,
    User,
    XCircle,
} from 'lucide-react';

type Site = {
    id: number;
    name: string;
};

type Run = {
    id: number;
    template: {
        id: number;
        name: string;
    };
    status: 'scheduled' | 'in_progress' | 'completed' | 'overdue' | 'skipped';
    scheduled_date: string;
    completed_at?: string;
    completed_by?: { id: number; name: string } | null;
};

type Props = {
    site: Site;
    runs: {
        data: Run[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status?: string };
};

const statusLabels: Record<string, string> = {
    scheduled: 'Scheduled',
    in_progress: 'In Progress',
    completed: 'Completed',
    overdue: 'Overdue',
    skipped: 'Skipped',
};

function StatusBadge({ status }: { status: Run['status'] }) {
    const styles: Record<string, string> = {
        scheduled:
            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
        in_progress:
            'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-300',
        completed:
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        overdue:
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
        skipped: 'border-border text-muted-foreground',
    };
    return (
        <Badge variant="outline" className={cn('font-medium', styles[status])}>
            {statusLabels[status] ?? status}
        </Badge>
    );
}

export default function ChecklistRuns({ site, runs, filters }: Props) {
    const total = runs.data.length;
    const counts = {
        scheduled: runs.data.filter((r) => r.status === 'scheduled').length,
        in_progress: runs.data.filter((r) => r.status === 'in_progress').length,
        completed: runs.data.filter((r) => r.status === 'completed').length,
        overdue: runs.data.filter((r) => r.status === 'overdue').length,
    };

    const filterTo = (status?: string) => {
        router.get(
            `/sites/${site.id}/checklists/runs`,
            status ? { status } : {},
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Checklists', href: `/sites/${site.id}/checklists` },
                { title: 'Runs', href: '#' },
            ]}
        >
            <Head title={`${site.name} — Checklist Runs`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardCheck}
                        backHref={`/sites/${site.id}/checklists`}
                        backLabel="Back to checklists"
                        title="Checklist Runs"
                        description={site.name}
                        stats={[
                            { label: 'Total', value: total },
                            { label: 'Scheduled', value: counts.scheduled },
                            { label: 'Completed', value: counts.completed },
                            { label: 'Overdue', value: counts.overdue },
                        ]}
                        actions={
                            filters.status ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => filterTo(undefined)}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    Clear filter
                                </Button>
                            ) : null
                        }
                    />
                }
            >

                {/* Status filter chips */}
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant={!filters.status ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => filterTo(undefined)}
                    >
                        <ClipboardCheck className="mr-1 h-3.5 w-3.5" />
                        Total ({total})
                    </Button>
                    <Button
                        variant={filters.status === 'scheduled' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => filterTo('scheduled')}
                    >
                        <Calendar className="mr-1 h-3.5 w-3.5" />
                        Scheduled ({counts.scheduled})
                    </Button>
                    <Button
                        variant={filters.status === 'in_progress' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => filterTo('in_progress')}
                    >
                        <RotateCw className="mr-1 h-3.5 w-3.5" />
                        In Progress ({counts.in_progress})
                    </Button>
                    <Button
                        variant={filters.status === 'completed' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => filterTo('completed')}
                    >
                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                        Completed ({counts.completed})
                    </Button>
                    <Button
                        variant={filters.status === 'overdue' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => filterTo('overdue')}
                    >
                        <AlertTriangle className="mr-1 h-3.5 w-3.5" />
                        Overdue ({counts.overdue})
                    </Button>
                </div>

                {/* Runs list */}
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">
                                {filters.status
                                    ? `${statusLabels[filters.status]} runs`
                                    : 'All runs'}
                            </CardTitle>
                            <span className="text-xs text-muted-foreground">
                                {runs.data.length}{' '}
                                {runs.data.length === 1 ? 'run' : 'runs'}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="px-0 pb-0">
                        {runs.data.length === 0 ? (
                            <div className="px-6 py-12 text-center">
                                <ClipboardCheck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm font-medium">No runs to show</p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {filters.status
                                        ? `No ${statusLabels[filters.status].toLowerCase()} runs.`
                                        : 'Assign a checklist template to schedule runs.'}
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {runs.data.map((run) => (
                                    <div
                                        key={run.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-6 py-3 transition hover:bg-accent/30"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {run.template.name}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                <span className="inline-flex items-center gap-1">
                                                    <Calendar className="h-3.5 w-3.5" />
                                                    {new Date(
                                                        run.scheduled_date,
                                                    ).toLocaleDateString(undefined, {
                                                        weekday: 'short',
                                                        month: 'short',
                                                        day: 'numeric',
                                                        year: 'numeric',
                                                    })}
                                                </span>
                                                {run.completed_by && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <User className="h-3.5 w-3.5" />
                                                        {run.completed_by.name}
                                                    </span>
                                                )}
                                                {run.completed_at && (
                                                    <span>
                                                        Completed{' '}
                                                        {new Date(
                                                            run.completed_at,
                                                        ).toLocaleDateString()}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            <StatusBadge status={run.status} />
                                            {(run.status === 'scheduled' ||
                                                run.status === 'overdue') && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/checklists/runs/${run.id}/start`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <PlayCircle className="mr-1 h-4 w-4" />
                                                    Start
                                                </Button>
                                            )}
                                            {run.status === 'in_progress' && (
                                                <Button asChild variant="outline" size="sm">
                                                    <Link
                                                        href={`/checklists/runs/${run.id}`}
                                                    >
                                                        <RotateCw className="mr-1 h-4 w-4" />
                                                        Continue
                                                    </Link>
                                                </Button>
                                            )}
                                            {(run.status === 'completed' ||
                                                run.status === 'skipped') && (
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={`/checklists/runs/${run.id}`}
                                                    >
                                                        <Eye className="mr-1 h-4 w-4" />
                                                        View
                                                    </Link>
                                                </Button>
                                            )}
                                            {run.status === 'skipped' && (
                                                <XCircle className="h-4 w-4 text-muted-foreground" />
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
