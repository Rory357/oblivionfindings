import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
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

function StatCard({
    label,
    value,
    accent,
    icon: Icon,
    active,
    onClick,
}: {
    label: string;
    value: number;
    accent: 'slate' | 'amber' | 'violet' | 'emerald' | 'rose';
    icon: React.ComponentType<{ className?: string }>;
    active?: boolean;
    onClick?: () => void;
}) {
    const accentMap = {
        slate: {
            text: 'text-foreground',
            badge: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            ring: 'ring-slate-300 dark:ring-slate-700',
        },
        amber: {
            text: 'text-amber-600 dark:text-amber-400',
            badge: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
            ring: 'ring-amber-400 dark:ring-amber-700',
        },
        violet: {
            text: 'text-violet-600 dark:text-violet-400',
            badge: 'bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400',
            ring: 'ring-violet-400 dark:ring-violet-700',
        },
        emerald: {
            text: 'text-emerald-600 dark:text-emerald-400',
            badge: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400',
            ring: 'ring-emerald-400 dark:ring-emerald-700',
        },
        rose: {
            text: 'text-rose-600 dark:text-rose-400',
            badge: 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400',
            ring: 'ring-rose-400 dark:ring-rose-700',
        },
    };
    const a = accentMap[accent];
    const Wrapper: any = onClick ? 'button' : 'div';
    return (
        <Wrapper
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'group block w-full rounded-xl border bg-card p-4 text-left transition',
                onClick && 'hover:border-primary/30 hover:shadow-sm',
                active && `ring-2 ${a.ring}`,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        {label}
                    </p>
                    <p className={cn('mt-1.5 text-3xl font-semibold tabular-nums', a.text)}>
                        {value}
                    </p>
                </div>
                <span
                    className={cn(
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                        a.badge,
                    )}
                >
                    <Icon className="h-5 w-5" />
                </span>
            </div>
        </Wrapper>
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

            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="-ml-2 mb-2 text-muted-foreground hover:text-foreground"
                        >
                            <Link href={`/sites/${site.id}/checklists`}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to checklists
                            </Link>
                        </Button>
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <ClipboardCheck className="h-4 w-4" />
                            </span>
                            Checklist Runs
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">{site.name}</p>
                    </div>
                    {filters.status && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => filterTo(undefined)}
                        >
                            Clear filter
                        </Button>
                    )}
                </div>

                {/* Stats — clickable to filter */}
                <div className="grid gap-3 grid-cols-2 lg:grid-cols-5">
                    <StatCard
                        label="Total"
                        value={total}
                        accent="slate"
                        icon={ClipboardCheck}
                        active={!filters.status}
                        onClick={() => filterTo(undefined)}
                    />
                    <StatCard
                        label="Scheduled"
                        value={counts.scheduled}
                        accent="amber"
                        icon={Calendar}
                        active={filters.status === 'scheduled'}
                        onClick={() => filterTo('scheduled')}
                    />
                    <StatCard
                        label="In Progress"
                        value={counts.in_progress}
                        accent="violet"
                        icon={RotateCw}
                        active={filters.status === 'in_progress'}
                        onClick={() => filterTo('in_progress')}
                    />
                    <StatCard
                        label="Completed"
                        value={counts.completed}
                        accent="emerald"
                        icon={CheckCircle2}
                        active={filters.status === 'completed'}
                        onClick={() => filterTo('completed')}
                    />
                    <StatCard
                        label="Overdue"
                        value={counts.overdue}
                        accent="rose"
                        icon={AlertTriangle}
                        active={filters.status === 'overdue'}
                        onClick={() => filterTo('overdue')}
                    />
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
            </div>
        </AppLayout>
    );
}
