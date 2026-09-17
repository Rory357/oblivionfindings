import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Layers,
    ListChecks,
    UserPlus,
    Users,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

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
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string | null;
        status?: string | null;
    };
    stats: {
        active: number;
        completed_this_month: number;
        overdue_steps: number;
        avg_days: number;
    };
    status_counts?: Record<string, number>;
};

type ViewKey = 'all' | 'in_progress' | 'completed' | 'cancelled';

const STATUS_BADGE: Record<string, { label: string; variant: StatusVariant }> =
    {
        in_progress: { label: 'In progress', variant: 'info' },
        completed: { label: 'Completed', variant: 'success' },
        cancelled: { label: 'Cancelled', variant: 'neutral' },
    };

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function OnboardingDashboard({
    workflows = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    stats = {} as any,
    status_counts = {},
}: Props) {
    const { labels } = usePage().props as any;
    const clientLabel: string = labels?.['client.singular'] ?? 'Client';
    const clientsLabel: string = labels?.['client.plural'] ?? 'Clients';

    const s = {
        active: stats?.active ?? 0,
        completed_this_month: stats?.completed_this_month ?? 0,
        overdue_steps: stats?.overdue_steps ?? 0,
        avg_days: stats?.avg_days ?? 0,
    };
    const countOf = (status: string) => status_counts?.[status] ?? 0;
    const total =
        countOf('in_progress') + countOf('completed') + countOf('cancelled');

    const view: ViewKey = (
        ['in_progress', 'completed', 'cancelled'] as const
    ).includes(filters.status as any)
        ? (filters.status as ViewKey)
        : 'all';

    const [q, setQ] = useState(filters.q ?? '');
    const [overdueOnly, setOverdueOnly] = useState(false);

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.status = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            router.get('/operations/onboarding', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const shown = useMemo(() => {
        const rows = workflows?.data ?? [];
        return overdueOnly
            ? rows.filter((wf) => (wf.overdue_steps ?? 0) > 0)
            : rows;
    }, [workflows?.data, overdueOnly]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All workflows', icon: Layers, count: total },
        {
            key: 'in_progress',
            label: 'In progress',
            icon: UserPlus,
            count: countOf('in_progress'),
        },
        {
            key: 'completed',
            label: 'Completed',
            icon: CheckCircle2,
            count: countOf('completed'),
        },
        {
            key: 'cancelled',
            label: 'Cancelled',
            icon: XCircle,
            count: countOf('cancelled'),
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All workflows';
    const viewTotal = view === 'all' ? total : countOf(view);

    const header = (
        <PageHeader
            icon={UserPlus}
            title="Onboarding"
            titleChip={
                s.overdue_steps > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {s.overdue_steps} overdue steps
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        {s.active} active
                    </PageHeaderStatusChip>
                )
            }
            subline={`${clientsLabel} being onboarded · ${s.active} active workflows · avg ${s.avg_days} days to complete`}
            actions={
                <PageHeaderSearch
                    value={q}
                    onChange={setQ}
                    placeholder={`Search ${clientsLabel.toLowerCase()}…`}
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Active"
                        ariaLabel="View in-progress workflows"
                        onClick={() => applyFilters({ view: 'in_progress' })}
                    >
                        <PageHeaderMeterBig>{s.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            workflows in progress
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue steps"
                        tone={s.overdue_steps > 0 ? 'critical' : 'success'}
                        ariaLabel="View in-progress workflows with overdue steps"
                        onClick={() => {
                            setOverdueOnly(true);
                            applyFilters({ view: 'in_progress' });
                        }}
                    >
                        <PageHeaderMeterBig>
                            {s.overdue_steps}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="This month"
                        ariaLabel="View completed workflows"
                        onClick={() => applyFilters({ view: 'completed' })}
                    >
                        <PageHeaderMeterBig>
                            {s.completed_this_month}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            completed · avg {s.avg_days} days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Completed"
                            ariaLabel="View all completed workflows"
                            onClick={() => applyFilters({ view: 'completed' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(countOf('completed') / total) * 100}
                                caption={
                                    <>
                                        {countOf('completed')} of {total}
                                        <br />
                                        all time
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <PageHeaderFilterCheck
                    label="Overdue only"
                    checked={overdueOnly}
                    onChange={setOverdueOnly}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Workflow views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Onboarding', href: '/operations/onboarding' },
            ]}
        >
            <Head title="Onboarding" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${viewTotal} shown${
                            overdueOnly ? ' · overdue only' : ''
                        }`}
                    />

                    <div className="space-y-2">
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title="No onboarding workflows"
                                description={
                                    overdueOnly ||
                                    view !== 'all' ||
                                    (filters.q ?? '') !== ''
                                        ? 'Nothing matches this view or your filters.'
                                        : `Onboarding workflows are created automatically when a new ${clientLabel.toLowerCase()} is added.`
                                }
                            />
                        ) : (
                            shown.map((wf) => {
                                const stepsTotal =
                                    wf.steps_total || wf.steps_count || 0;
                                const stepsCompleted =
                                    wf.steps_completed ||
                                    wf.completed_steps_count ||
                                    0;
                                const pct =
                                    stepsTotal > 0
                                        ? Math.round(
                                              (stepsCompleted / stepsTotal) *
                                                  100,
                                          )
                                        : 0;
                                const hasOverdue = (wf.overdue_steps ?? 0) > 0;
                                const badge = STATUS_BADGE[wf.status] ?? {
                                    label: (wf.status ?? '').replace('_', ' '),
                                    variant: 'neutral' as StatusVariant,
                                };
                                return (
                                    <Card
                                        key={wf.id}
                                        className={`transition-all hover:border-border hover:shadow-sm ${hasOverdue ? 'border-status-critical/30' : ''}`}
                                    >
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div
                                                className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${hasOverdue ? 'bg-status-critical-bg text-status-critical' : 'bg-primary/10 text-primary'}`}
                                            >
                                                {hasOverdue ? (
                                                    <AlertTriangle className="h-5 w-5" />
                                                ) : (
                                                    <ListChecks className="h-5 w-5" />
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-semibold">
                                                        {wf.client
                                                            ? `${wf.client.first_name} ${wf.client.last_name}`
                                                            : `Workflow #${wf.id}`}
                                                    </span>
                                                    <StatusBadge
                                                        variant={badge.variant}
                                                    >
                                                        {badge.label}
                                                    </StatusBadge>
                                                    {hasOverdue && (
                                                        <StatusBadge variant="critical">
                                                            {wf.overdue_steps}{' '}
                                                            overdue
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {wf.assigned_to && (
                                                        <span>
                                                            Coordinator:{' '}
                                                            {
                                                                wf.assigned_to
                                                                    .name
                                                            }
                                                        </span>
                                                    )}
                                                    {wf.started_at && (
                                                        <span>
                                                            Started:{' '}
                                                            {formatDate(
                                                                wf.started_at,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                                {/* Progress bar */}
                                                <div className="mt-2 flex items-center gap-2">
                                                    <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                        <div
                                                            className={`h-1.5 rounded-full transition-all ${hasOverdue ? 'bg-status-critical' : 'bg-primary'}`}
                                                            style={{
                                                                width: `${pct}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] text-muted-foreground tabular-nums">
                                                        {stepsCompleted}/
                                                        {stepsTotal} ({pct}%)
                                                    </span>
                                                </div>
                                            </div>
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                                className="h-8 gap-1.5 text-xs"
                                            >
                                                <Link
                                                    href={
                                                        wf.client
                                                            ? `/operations/clients/${wf.client.id}?tab=onboarding`
                                                            : '#'
                                                    }
                                                >
                                                    <ExternalLink className="h-3 w-3" />
                                                    View {clientLabel}
                                                </Link>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </div>

                    {(workflows?.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(workflows?.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
