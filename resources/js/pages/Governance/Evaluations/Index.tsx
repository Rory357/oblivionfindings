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
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    EVALUATION_TYPES,
    EvaluationWizardDialog,
    evaluationSubject,
    type CommitteeOption,
} from './_dialogs';

interface Evaluation {
    id: number;
    title: string;
    evaluation_type: string;
    committee_name?: string | null;
    status: string;
    period_start: string;
    period_end: string;
    due_date: string;
    responses_count: number;
    /** Only for people who answer evaluations; null for drafts. */
    my_response: 'responded' | 'not_yet' | null;
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
    is_board_member?: boolean;
    /** NZ calendar date from the server. */
    today?: string;
    committees?: CommitteeOption[];
}

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
    is_board_member: isBoardMember = false,
    today: serverToday,
    committees = [],
}: Props) {
    const canManage = Boolean(auth.can?.governance?.evaluations?.manage);
    const [wizardOpen, setWizardOpen] = useDialogDeepLink('create', canManage);
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<Evaluation>();
    const today = serverToday ?? toDateInput(new Date());

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

    const isOpenEvaluation = (e: Evaluation) =>
        e.status === 'active' || e.status === 'open';
    const isPastDue = (e: Evaluation) => isOpenEvaluation(e) && e.due_date < today;

    const actionsFor = (e: Evaluation): MenuItem[] =>
        compactMenu([
            {
                label:
                    isOpenEvaluation(e) && e.my_response === 'not_yet' && !isPastDue(e)
                        ? 'Open and respond'
                        : 'Open evaluation',
                icon: Eye,
                onClick: () => router.visit(`/governance/evaluations/${e.id}`),
            },
            e.status !== 'draft'
                ? {
                      label: 'View results',
                      icon: BarChart3,
                      onClick: () =>
                          router.visit(`/governance/evaluations/${e.id}/results`),
                  }
                : null,
        ]);

    const columns: EntityTableColumn<Evaluation>[] = [
        {
            key: 'type',
            label: 'Who is evaluated',
            width: '0.9fr',
            cell: (e) => (
                <EntityChip>{evaluationSubject(e.evaluation_type, e.committee_name)}</EntityChip>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (e) => {
                const chip = governanceStatus('evaluation_status', e.status);
                return (
                    <EntityStatusChip variant={chip.variant}>{chip.label}</EntityStatusChip>
                );
            },
        },
        {
            key: 'due',
            label: 'Responses due',
            width: '0.9fr',
            cell: (e) =>
                isPastDue(e) ? (
                    <span className="flex flex-wrap items-center gap-1.5">
                        <span>{formatDateOnly(e.due_date)}</span>
                        <EntityStatusChip variant="critical">Overdue</EntityStatusChip>
                    </span>
                ) : (
                    formatDateOnly(e.due_date)
                ),
        },
        {
            key: 'responses',
            label: 'Responses',
            width: '0.7fr',
            align: 'center',
            cell: (e) => (
                <CounterPill tone={e.responses_count > 0 ? 'success' : 'neutral'}>
                    {e.responses_count}
                </CounterPill>
            ),
        },
        ...(isBoardMember
            ? [
                  {
                      key: 'you',
                      label: 'You',
                      width: '0.7fr',
                      cell: (e: Evaluation) =>
                          e.my_response === 'responded' ? (
                              <EntityStatusChip variant="success">Responded</EntityStatusChip>
                          ) : e.my_response === 'not_yet' ? (
                              <EntityStatusChip variant={isOpenEvaluation(e) && !isPastDue(e) ? 'warning' : 'neutral'}>
                                  Not yet
                              </EntityStatusChip>
                          ) : (
                              <span className="text-caption">Not open yet</span>
                          ),
                  } satisfies EntityTableColumn<Evaluation>,
              ]
            : []),
    ];

    const header = (
        <PageHeader
            icon={Star}
            title="Evaluations"
            subline={`How the board, its committees and the chair are doing, in members' own words · ${summary.active_board_members} current board ${summary.active_board_members === 1 ? 'member' : 'members'}`}
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
                        label="Open for responses"
                        tone={summary.active > 0 ? 'warning' : 'brand'}
                        ariaLabel="View evaluations open for responses"
                        href="/governance/evaluations?status=active"
                    >
                        <PageHeaderMeterBig>{summary.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            members can answer now
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft evaluations"
                        href="/governance/evaluations?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>not open yet</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Closed"
                        ariaLabel="View closed evaluations"
                        href="/governance/evaluations?status=closed"
                    >
                        <PageHeaderMeterBig>{summary.closed}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>results ready to read</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="All evaluations"
                        ariaLabel="View all evaluations"
                        href="/governance/evaluations"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>in the register</PageHeaderMeterCaption>
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
                            {
                                value: 'active',
                                label: governanceStatus('evaluation_status', 'active').label,
                            },
                            { value: 'draft', label: 'Draft' },
                            { value: 'closed', label: 'Closed' },
                        ]}
                        onChange={(v) => go({ status: v })}
                    />
                    <PageHeaderFilterSelect
                        label="Anyone evaluated"
                        value={filters.type ?? 'all'}
                        options={[
                            { value: 'all', label: 'Anyone evaluated' },
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
            <Head title="Evaluations" />
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
                                      ? 'Set up an evaluation for board members to answer, such as a yearly board effectiveness review.'
                                      : 'Evaluations appear here when the board secretary sets one up.'
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
                                subline: `Covers ${formatDateOnly(e.period_start)} – ${formatDateOnly(e.period_end)}`,
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
                <EvaluationWizardDialog
                    open={wizardOpen}
                    onClose={closeWizard}
                    committees={committees}
                />
            ) : null}
        </AppLayout>
    );
}
