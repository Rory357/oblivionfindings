import { Head, router } from '@inertiajs/react';
import { BarChart3, Eye, Plus, Star, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    CounterPill,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import type { StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import {
    EVALUATION_TYPES,
    EvaluationWizardDialog,
    evaluationTypeLabel,
} from './_dialogs';

interface Evaluation {
    id: number;
    title: string;
    evaluation_type: string;
    status: string;
    period_start: string;
    period_end: string;
    due_date: string;
    responses_count: number;
}

interface Filters {
    search: string | null;
    status: string | null;
    type: string | null;
}

interface Props extends PageProps {
    evaluations: {
        data: Evaluation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
        last_page: number;
    };
    filters: Filters;
    summary: {
        total: number;
        active: number;
        draft: number;
        closed: number;
        active_board_members: number;
    };
}

const STATUS_VARIANT: Record<string, StatusVariant> = {
    active: 'info',
    draft: 'neutral',
    closed: 'success',
};

const STATUS_LABEL: Record<string, string> = {
    active: 'Open',
    draft: 'Draft',
    closed: 'Closed',
};

function cleanParams(values: Partial<Filters>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values).filter(
            ([, v]) => v !== null && v !== undefined && v !== '' && v !== 'all',
        ),
    ) as Record<string, string>;
}

export default function EvaluationsIndex({
    auth,
    evaluations,
    filters,
    summary,
}: Props) {
    const canManage = Boolean(auth.can?.governance?.evaluations?.manage);
    const [wizardOpen, setWizardOpen] = useDialogDeepLink('create', canManage);
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<Evaluation>();
    const today = new Date().toISOString().split('T')[0];

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Partial<Filters>) =>
        router.get(
            '/governance/evaluations',
            cleanParams({ ...filters, ...patch }),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(filters.search || filters.status || filters.type);
    const clearFilters = () =>
        router.get('/governance/evaluations', {}, { preserveScroll: true });

    const closeWizard = () => setWizardOpen(false);

    const actionsFor = (e: Evaluation): MenuItem[] =>
        compactMenu([
            {
                label: e.status === 'active' ? 'Open & respond' : 'Open evaluation',
                icon: Eye,
                onClick: () => router.visit(`/governance/evaluations/${e.id}`),
            },
            {
                label: 'View results',
                icon: BarChart3,
                onClick: () =>
                    router.visit(`/governance/evaluations/${e.id}/results`),
            },
        ]);

    const closedShare =
        summary.total > 0 ? (summary.closed / summary.total) * 100 : 0;

    const columns: EntityTableColumn<Evaluation>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '0.8fr',
            cell: (e) => <EntityChip>{evaluationTypeLabel(e.evaluation_type)}</EntityChip>,
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.6fr',
            cell: (e) => (
                <EntityStatusChip variant={STATUS_VARIANT[e.status] ?? 'neutral'}>
                    {STATUS_LABEL[e.status] ?? e.status}
                </EntityStatusChip>
            ),
        },
        {
            key: 'period',
            label: 'Period',
            width: '1.2fr',
            cell: (e) =>
                `${formatDateOnly(e.period_start)} – ${formatDateOnly(e.period_end)}`,
        },
        {
            key: 'due',
            label: 'Due',
            width: '0.7fr',
            cell: (e) => (
                <span
                    className={
                        e.status === 'active' && e.due_date < today
                            ? 'font-semibold text-status-critical'
                            : undefined
                    }
                >
                    {formatDateOnly(e.due_date)}
                </span>
            ),
        },
        {
            key: 'responses',
            label: 'Responses',
            width: '0.6fr',
            align: 'center',
            cell: (e) => (
                <CounterPill tone={e.responses_count > 0 ? 'success' : 'neutral'}>
                    {e.responses_count}
                </CounterPill>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={Star}
            title="Board evaluations"
            subline={`Board, committee and chair performance evaluations · ${summary.active_board_members} active board members`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search evaluations…"
                    />
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            New evaluation
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Evaluations"
                        ariaLabel="View all evaluations"
                        href="/governance/evaluations"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            conducted or planned
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open"
                        tone={summary.active > 0 ? 'warning' : 'brand'}
                        ariaLabel="View evaluations open for responses"
                        href="/governance/evaluations?status=active"
                    >
                        <PageHeaderMeterBig>{summary.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            accepting member responses
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft evaluations"
                        href="/governance/evaluations?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet launched
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Closed"
                        value={summary.closed}
                        ariaLabel="View closed evaluations"
                        href="/governance/evaluations?status=closed"
                    >
                        <PageHeaderMeterDonut
                            percent={closedShare}
                            caption={
                                <>
                                    {summary.closed} of {summary.total}
                                    <br />
                                    finalised
                                </>
                            }
                        />
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Any status"
                        value={filters.status ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any status' },
                            { value: 'active', label: 'Open' },
                            { value: 'draft', label: 'Draft' },
                            { value: 'closed', label: 'Closed' },
                        ]}
                        onChange={(v) => go({ status: v })}
                    />
                    <PageHeaderFilterSelect
                        label="All types"
                        value={filters.type ?? 'all'}
                        options={[
                            { value: 'all', label: 'All types' },
                            ...EVALUATION_TYPES.map((t) => ({
                                value: t.key,
                                label: t.label,
                            })),
                        ]}
                        onChange={(v) => go({ type: v })}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Evaluations', href: '/governance/evaluations' },
            ]}
        >
            <Head title="Board evaluations" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Evaluations"
                        caption={`${evaluations.data.length} of ${evaluations.total} shown`}
                        right={
                            hasFilters ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="text-xs text-muted-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />

                    {evaluations.data.length === 0 ? (
                        <EmptyState
                            icon={Star}
                            title={
                                hasFilters
                                    ? 'No evaluations match your filters'
                                    : 'No evaluations yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canManage
                                      ? 'Start a board effectiveness evaluation for members to complete.'
                                      : 'Evaluations the board runs will appear here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canManage ? (
                                    <Button size="sm" onClick={() => setWizardOpen(true)}>
                                        <Plus className="h-3.5 w-3.5" />
                                        New evaluation
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={evaluations.data}
                            rowKey={(e) => e.id}
                            identityLabel="Evaluation"
                            identity={(e) => ({
                                icon: Star,
                                name: e.title,
                                subline: `${evaluationTypeLabel(e.evaluation_type)} evaluation`,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            hrefFor={(e) => `/governance/evaluations/${e.id}`}
                            onOpen={(e) =>
                                router.visit(`/governance/evaluations/${e.id}`)
                            }
                            onRowContextMenu={(ev, e) => ctxMenu.open(ev, e)}
                        />
                    )}

                    <LaravelPagination
                        links={evaluations.links}
                        lastPage={evaluations.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Star}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage ? (
                <EvaluationWizardDialog open={wizardOpen} onClose={closeWizard} />
            ) : null}
        </AppLayout>
    );
}
