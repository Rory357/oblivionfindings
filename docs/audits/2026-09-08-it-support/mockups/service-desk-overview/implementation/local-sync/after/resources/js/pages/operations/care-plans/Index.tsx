/* Org-wide Care Plans overview — a READ-ONLY organisation-wide view of plan
 * health, reviews due and goal progress. Day-to-day care planning still lives
 * inside each client's profile (Care & Support Plan tab): rows and menus
 * deep-link there (?tab=care_plans). The standalone Show page stays reachable
 * as a read-only fallback; Create/Edit remain unlinked legacy deep-links. */
import {
    EmptyValue,
    EntityChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    ProgressValue,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
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
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    ArrowRightLeft,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Eye,
    FileText,
    Heart,
    ShieldAlert,
    Target,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type CarePlan = {
    id: number;
    title: string;
    plan_type: string | null;
    status: string;
    next_review_at: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    creator?: { id: number; name: string } | null;
    goals_count: number;
    goals_achieved_count: number;
};

type Stats = {
    total: number;
    active: number;
    review_due: number;
    draft: number;
    in_review: number;
    plans_without_goals: number;
    overdue_goals: number;
    plans_with_overdue_goals: number;
};

type Props = {
    carePlans: {
        data: CarePlan[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    clients?: { id: number; first_name: string; last_name: string }[];
    filters?: {
        q?: string | null;
        status?: string | null;
        plan_type?: string | null;
        client_id?: number | string | null;
        review_due?: boolean | string | null;
        no_goals?: boolean | string | null;
        overdue_goals?: boolean | string | null;
    };
    stats?: Stats;
    plan_types?: string[];
};

type ViewKey =
    | 'all'
    | 'active'
    | 'review_due'
    | 'draft'
    | 'no_goals'
    | 'overdue_goals';

const VIEW_PARAMS: Record<ViewKey, Record<string, string>> = {
    all: {},
    active: { status: 'active' },
    review_due: { review_due: '1' },
    draft: { status: 'draft' },
    no_goals: { no_goals: '1' },
    overdue_goals: { overdue_goals: '1' },
};

const STATUS_META: Record<string, { label: string; variant: StatusVariant }> = {
    active: { label: 'Active', variant: 'success' },
    draft: { label: 'Draft', variant: 'neutral' },
    review: { label: 'In review', variant: 'info' },
    archived: { label: 'Archived', variant: 'neutral' },
};

const PLAN_TYPE_META: Record<string, { label: string; icon: LucideIcon }> = {
    support_plan: { label: 'Support plan', icon: ClipboardList },
    behaviour_plan: { label: 'Behaviour plan', icon: ShieldAlert },
    health_plan: { label: 'Health plan', icon: Heart },
    transition_plan: { label: 'Transition plan', icon: ArrowRightLeft },
};

function planTypeLabel(type: string): string {
    if (PLAN_TYPE_META[type]) return PLAN_TYPE_META[type].label;
    const words = type.replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function CarePlansIndex({
    carePlans,
    clients = [],
    filters = {},
    stats,
    plan_types = [],
}: Props) {
    const { labels } = usePage().props as any;
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';
    const clientSingular: string = labels?.['client.singular'] ?? 'Client';

    const s: Stats = stats ?? {
        total: 0,
        active: 0,
        review_due: 0,
        draft: 0,
        in_review: 0,
        plans_without_goals: 0,
        overdue_goals: 0,
        plans_with_overdue_goals: 0,
    };

    const view: ViewKey = filters.no_goals
        ? 'no_goals'
        : filters.overdue_goals
          ? 'overdue_goals'
          : filters.review_due
            ? 'review_due'
            : filters.status === 'active'
              ? 'active'
              : filters.status === 'draft'
                ? 'draft'
                : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: {
            view?: ViewKey;
            q?: string;
            plan_type?: string;
            client_id?: string;
        }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextType = overrides.plan_type ?? filters.plan_type ?? 'all';
            const nextClient =
                overrides.client_id ??
                (filters.client_id != null ? String(filters.client_id) : 'all');
            const params: Record<string, string> = {
                ...VIEW_PARAMS[nextView],
            };
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextType !== 'all') params.plan_type = nextType;
            if (nextClient !== 'all') params.client_id = nextClient;
            router.get('/operations/care-plans', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.plan_type, filters.client_id],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All plans', icon: ClipboardList, count: s.total },
        {
            key: 'active',
            label: 'Active',
            icon: CheckCircle2,
            count: s.active,
        },
        {
            key: 'review_due',
            label: 'Review due',
            icon: CalendarClock,
            count: s.review_due,
        },
        { key: 'draft', label: 'Drafts', icon: FileText, count: s.draft },
        {
            key: 'no_goals',
            label: 'No goals',
            icon: Target,
            count: s.plans_without_goals,
        },
        {
            key: 'overdue_goals',
            label: 'Overdue goals',
            icon: AlertTriangle,
            count: s.plans_with_overdue_goals,
            alert: true,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All plans';

    const hasNarrowing =
        (filters.q ?? '') !== '' ||
        (filters.plan_type ?? 'all') !== 'all' ||
        filters.client_id != null;

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...plan_types.map((t) => ({ value: t, label: planTypeLabel(t) })),
    ];
    const clientOptions = [
        { value: 'all', label: `All ${clientPlural.toLowerCase()}` },
        ...clients.map((c) => ({
            value: String(c.id),
            label: `${c.first_name} ${c.last_name}`,
        })),
    ];

    const openPlan = (plan: CarePlan) => {
        if (plan.client?.id) {
            router.visit(
                `/operations/clients/${plan.client.id}?tab=care_plans`,
            );
        } else {
            router.visit(`/operations/care-plans/${plan.id}`);
        }
    };

    const actionsFor = (plan: CarePlan): MenuItem[] => [
        ...(plan.client?.id
            ? [
                  {
                      label: `Open ${clientSingular.toLowerCase()} profile`,
                      icon: Users,
                      onClick: () =>
                          router.visit(
                              `/operations/clients/${plan.client!.id}?tab=care_plans`,
                          ),
                  },
              ]
            : []),
        {
            label: 'View plan (read-only)',
            icon: Eye,
            onClick: () => router.visit(`/operations/care-plans/${plan.id}`),
        },
    ];

    const tableColumns: EntityTableColumn<CarePlan>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1fr',
            cell: (plan) => {
                if (!plan.plan_type) return <EmptyValue />;
                const TypeIcon =
                    PLAN_TYPE_META[plan.plan_type]?.icon ?? ClipboardList;
                return (
                    <EntityChip icon={TypeIcon}>
                        {planTypeLabel(plan.plan_type)}
                    </EntityChip>
                );
            },
        },
        {
            key: 'goals',
            label: 'Goal progress',
            width: '1.1fr',
            cell: (plan) =>
                plan.goals_count > 0 ? (
                    <ProgressValue
                        percent={Math.round(
                            (plan.goals_achieved_count / plan.goals_count) *
                                100,
                        )}
                    >
                        {plan.goals_achieved_count}/{plan.goals_count} goals
                    </ProgressValue>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'review',
            label: 'Next review',
            width: '0.9fr',
            cell: (plan) => {
                if (!plan.next_review_at) {
                    return plan.status === 'active' ? (
                        <span className="text-xs font-medium text-status-warning">
                            Not scheduled
                        </span>
                    ) : (
                        <EmptyValue />
                    );
                }
                const overdue =
                    plan.status === 'active' &&
                    new Date(plan.next_review_at).getTime() <= Date.now();
                return (
                    <span
                        className={
                            overdue
                                ? 'text-xs font-medium text-status-warning'
                                : 'text-xs text-muted-foreground'
                        }
                    >
                        {formatDay(plan.next_review_at)}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.7fr',
            cell: (plan) => {
                const meta = STATUS_META[plan.status];
                return (
                    <StatusBadge
                        variant={meta?.variant ?? 'neutral'}
                        className="rounded-[8px]"
                        label={meta?.label ?? plan.status}
                    />
                );
            },
        },
    ];

    const header = (
        <PageHeader
            icon={ClipboardCheck}
            title="Care plans"
            titleChip={
                <PageHeaderStatusChip
                    variant={s.active > 0 ? 'success' : 'neutral'}
                >
                    {s.active} active
                </PageHeaderStatusChip>
            }
            subline={`Organisation-wide plan health · ${s.total} ${
                s.total === 1 ? 'plan' : 'plans'
            } · managed on ${clientSingular.toLowerCase()} profiles`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder={`Search plans and ${clientPlural.toLowerCase()}…`}
                    />
                    <PageHeaderGlassButton
                        icon={Users}
                        onClick={() => router.visit('/operations/clients')}
                    >
                        Browse {clientPlural.toLowerCase()}
                    </PageHeaderGlassButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total plans"
                        ariaLabel="View all plans"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            managed on {clientSingular.toLowerCase()} profiles
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Active"
                            ariaLabel="View active plans"
                            onClick={() => applyFilters({ view: 'active' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.active / s.total) * 100}
                                caption={
                                    <>
                                        {s.active} of {s.total}
                                        <br />
                                        active
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Review due"
                        tone={s.review_due > 0 ? 'warning' : 'success'}
                        ariaLabel="View plans with reviews due"
                        onClick={() => applyFilters({ view: 'review_due' })}
                    >
                        <PageHeaderMeterBig>{s.review_due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active plans past review
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Without goals"
                        tone={s.plans_without_goals > 0 ? 'warning' : 'success'}
                        ariaLabel="View plans without goals"
                        onClick={() => applyFilters({ view: 'no_goals' })}
                    >
                        <PageHeaderMeterBig>
                            {s.plans_without_goals}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            unarchived plans with no goals
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue goals"
                        tone={s.overdue_goals > 0 ? 'critical' : 'success'}
                        ariaLabel="View plans with overdue goals"
                        onClick={() => applyFilters({ view: 'overdue_goals' })}
                    >
                        <PageHeaderMeterBig>
                            {s.overdue_goals}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {s.plans_with_overdue_goals} active{' '}
                            {s.plans_with_overdue_goals === 1
                                ? 'plan'
                                : 'plans'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={ClipboardList}
                        label="All types"
                        value={filters.plan_type ?? 'all'}
                        options={typeOptions}
                        onChange={(v) => applyFilters({ plan_type: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label={`All ${clientPlural.toLowerCase()}`}
                        value={
                            filters.client_id != null
                                ? String(filters.client_id)
                                : 'all'
                        }
                        options={clientOptions}
                        onChange={(v) => applyFilters({ client_id: v })}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Plan views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Care plans', href: '/operations/care-plans' },
            ]}
        >
            <Head title="Care plans" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${carePlans.data.length} of ${
                            carePlans.total
                        } ${carePlans.total === 1 ? 'plan' : 'plans'} shown`}
                    />

                    {carePlans.data.length === 0 ? (
                        <EmptyState
                            icon={ClipboardCheck}
                            title="No plans match this view"
                            description={
                                hasNarrowing
                                    ? 'Try a different view or clear your filters.'
                                    : `Plans are created on each ${clientSingular.toLowerCase()}'s profile — open a ${clientSingular.toLowerCase()} and go to their Care & Support Plan tab.`
                            }
                            action={
                                hasNarrowing ? undefined : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.visit('/operations/clients')
                                        }
                                    >
                                        <Users className="h-3.5 w-3.5" />
                                        Browse {clientPlural.toLowerCase()}
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={carePlans.data}
                            rowKey={(plan) => plan.id}
                            identityLabel="Plan"
                            identity={(plan) => ({
                                icon:
                                    (plan.plan_type
                                        ? PLAN_TYPE_META[plan.plan_type]?.icon
                                        : undefined) ?? ClipboardCheck,
                                name: plan.title,
                                subline: plan.client
                                    ? `${plan.client.first_name} ${plan.client.last_name}`
                                    : `No ${clientSingular.toLowerCase()}`,
                            })}
                            columns={tableColumns}
                            actionsFor={actionsFor}
                            onOpen={openPlan}
                            mutedFor={(plan) => plan.status === 'archived'}
                            minWidth={760}
                        />
                    )}

                    {(carePlans.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(carePlans.links ?? []).map((link, i) => (
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
