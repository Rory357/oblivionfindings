import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, FileCheck, Plus, ShieldCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    frameworkIcon,
    ObligationWizardDialog,
    type ObligationFormOptions,
} from './_dialogs';
import {
    dueLabel,
    OBLIGATION_STATUS_FILTERS,
    obligationStatusLabel,
    obligationStatusVariant,
    onTimeSentence,
    plural,
} from './_shared';

interface Obligation {
    id: number;
    framework: string;
    framework_label: string;
    obligation_code: string | null;
    obligation_title: string;
    due_date: string | null;
    days_until_due: number | null;
    status: string;
    priority: string | null;
    evidence_provided: boolean;
    evidence_required: boolean;
    owner: { name: string } | null;
}

interface Counts {
    total: number;
    counted: number;
    complete: number;
    overdue: number;
    due_soon: number;
    not_due: number;
    cancelled: number;
    on_time: number;
}

interface Filters {
    framework?: string;
    status?: string;
    owner_id?: string;
    search?: string;
}

interface Props extends PageProps {
    obligations: {
        data: Obligation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total?: number;
        last_page?: number;
    };
    summary: {
        by_framework: Record<string, Counts>;
        totals: Counts;
        total_overdue: number;
        total_due_soon: number;
    };
    upcomingCount: number;
    frameworks: Array<{ value: string; label: string }>;
    filters?: Filters;
    canCreate?: boolean;
    formOptions?: ObligationFormOptions | null;
}

function cleanFilters(filters: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value != null && value !== '',
        ),
    ) as Record<string, string>;
}

export default function ComplianceIndex({
    auth,
    obligations,
    summary,
    upcomingCount,
    frameworks,
    filters = {},
    canCreate = false,
    formOptions = null,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    // Retired /compliance/create deep links arrive as ?create=1.
    const [wizardOpen, setWizardOpen] = useDialogDeepLink(
        'create',
        canCreate && formOptions != null,
    );
    const ctxMenu = useEntityContextMenu<Obligation>();
    const userId = auth?.user?.id != null ? String(auth.user.id) : null;

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Filters) => {
        router.get(
            '/governance/compliance',
            cleanFilters({ ...filters, ...patch }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if ((filters.search ?? '') !== search.trim()) {
                go({ search: search.trim() || undefined });
            }
        }, 350);
        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const totals = summary.totals;
    const onTimePercent =
        totals.counted > 0 ? (totals.on_time / totals.counted) * 100 : 0;
    const frameworksInUse = Object.values(summary.by_framework).filter(
        (f) => f.counted > 0,
    ).length;

    // Per-framework progress lives in the Framework filter options.
    const frameworkOptions = frameworks.map((framework) => {
        const counts = summary.by_framework[framework.value];
        if (!counts || counts.counted === 0) return framework;
        return {
            value: framework.value,
            label: `${framework.label} · ${counts.on_time} of ${counts.counted} on time`,
        };
    });

    const hasFilters = Object.keys(cleanFilters(filters)).length > 0;
    const shown = obligations.data.length;
    const total = obligations.total ?? shown;

    const actionsFor = (obligation: Obligation): MenuItem[] =>
        compactMenu([
            {
                label: 'Open requirement',
                icon: ExternalLink,
                onClick: () =>
                    router.visit(`/governance/compliance/${obligation.id}`),
            },
        ]);

    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Compliance"
            titleChip={
                totals.counted === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        No requirements yet
                    </PageHeaderStatusChip>
                ) : totals.overdue > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {totals.overdue} overdue
                    </PageHeaderStatusChip>
                ) : totals.due_soon > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {totals.due_soon} due in 30 days
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Nothing overdue
                    </PageHeaderStatusChip>
                )
            }
            subline={`Legal, standards and funding requirements the organisation must meet · ${plural(totals.counted, 'requirement')}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search requirements or references…"
                    />
                    {canCreate && formOptions ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            Add requirement
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Requirements"
                        ariaLabel="View all requirements"
                        href="/governance/compliance"
                    >
                        <PageHeaderMeterBig>{totals.counted}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {frameworksInUse === 1
                                ? 'from 1 law, standard or contract'
                                : `from ${frameworksInUse} laws, standards or contracts`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        ariaLabel="View overdue requirements"
                        href="/governance/compliance?status=overdue"
                        tone={totals.overdue > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{totals.overdue}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due in 30 days"
                        ariaLabel="View requirements due in the next 30 days"
                        href="/governance/compliance?status=due_soon"
                        tone={totals.due_soon > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{totals.due_soon}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not overdue yet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="On time"
                        value={`${totals.on_time}/${totals.counted}`}
                        ariaLabel="View requirements that are on time"
                        href="/governance/compliance?status=on_time"
                        tone={
                            totals.counted > 0 && totals.overdue === 0
                                ? 'success'
                                : 'brand'
                        }
                    >
                        <PageHeaderMeterDonut
                            percent={onTimePercent}
                            caption={
                                totals.counted > 0
                                    ? onTimeSentence(totals.on_time, totals.counted)
                                    : 'No requirements yet'
                            }
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Calendar"
                        ariaLabel="Open the compliance calendar"
                        href="/governance/compliance/calendar"
                    >
                        <PageHeaderMeterBig>{upcomingCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            due in the next 90 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="All laws and contracts"
                        value={filters.framework ?? 'all'}
                        options={[
                            { value: 'all', label: 'All laws and contracts' },
                            ...frameworkOptions,
                        ]}
                        onChange={(v) =>
                            go({ framework: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="All statuses"
                        value={filters.status ?? 'all'}
                        options={OBLIGATION_STATUS_FILTERS}
                        onChange={(v) =>
                            go({ status: v === 'all' ? undefined : v })
                        }
                    />
                    {userId ? (
                        <PageHeaderFilterCheck
                            label="Owned by me"
                            checked={filters.owner_id === userId}
                            onChange={(checked) =>
                                go({ owner_id: checked ? userId : undefined })
                            }
                        />
                    ) : null}
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Compliance', href: '/governance/compliance' },
            ]}
        >
            <Head title="Compliance" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={
                            hasFilters
                                ? 'Matching requirements'
                                : 'All requirements'
                        }
                        caption={`${shown} of ${total} shown · soonest due first`}
                    />

                    {obligations.data.length === 0 ? (
                        <EmptyState
                            icon={ShieldCheck}
                            title={
                                hasFilters
                                    ? 'No requirements match your filters'
                                    : 'No requirements yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canCreate
                                      ? 'Add the first legal or funding requirement to track when it is due and the evidence that it is met.'
                                      : 'Requirements added by the compliance lead appear here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.get(
                                                '/governance/compliance',
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable<Obligation>
                            rows={obligations.data}
                            rowKey={(o) => o.id}
                            identityLabel="Requirement"
                            identity={(o) => ({
                                icon: frameworkIcon(o.framework),
                                name: o.obligation_title,
                                linkLabel: `Open ${o.obligation_title}`,
                                subline: [
                                    o.framework_label,
                                    o.obligation_code
                                        ? `Ref ${o.obligation_code}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(o) => `/governance/compliance/${o.id}`}
                            onOpen={(o) =>
                                router.visit(`/governance/compliance/${o.id}`)
                            }
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (o) => (
                                        <EntityStatusChip
                                            variant={obligationStatusVariant(
                                                o.status,
                                            )}
                                        >
                                            {obligationStatusLabel(o.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'due',
                                    label: 'Due',
                                    width: '1.1fr',
                                    cell: (o) =>
                                        o.due_date ? (
                                            <span className="flex min-w-0 flex-col">
                                                <span className="truncate">
                                                    {formatDateOnly(
                                                        o.due_date.slice(0, 10),
                                                    )}
                                                </span>
                                                {o.status !== 'complete' &&
                                                o.status !== 'cancelled' ? (
                                                    <span className="text-caption truncate">
                                                        {dueLabel(o.days_until_due)}
                                                    </span>
                                                ) : null}
                                            </span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'priority',
                                    label: 'Priority',
                                    width: '0.8fr',
                                    cell: (o) => {
                                        if (!o.priority) return <EmptyValue />;
                                        const chip = governanceStatus(
                                            'priority',
                                            o.priority,
                                        );
                                        return (
                                            <EntityStatusChip variant={chip.variant}>
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1.2fr',
                                    cell: (o) => (
                                        <PersonCell name={o.owner?.name} />
                                    ),
                                },
                                {
                                    key: 'evidence',
                                    label: 'Evidence',
                                    width: '0.9fr',
                                    cell: (o) =>
                                        o.evidence_provided ? (
                                            <EntityStatusChip
                                                variant="success"
                                                icon={FileCheck}
                                            >
                                                Provided
                                            </EntityStatusChip>
                                        ) : o.evidence_required ? (
                                            <EntityStatusChip variant="warning">
                                                Needed
                                            </EntityStatusChip>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                Not needed
                                            </span>
                                        ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, o) => ctxMenu.open(e, o)}
                        />
                    )}

                    <LaravelPagination
                        links={obligations.links}
                        lastPage={obligations.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={frameworkIcon(ctxMenu.ctx.record.framework)}
                    title={ctxMenu.ctx.record.obligation_title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canCreate && formOptions ? (
                <ObligationWizardDialog
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    options={formOptions}
                />
            ) : null}
        </AppLayout>
    );
}
