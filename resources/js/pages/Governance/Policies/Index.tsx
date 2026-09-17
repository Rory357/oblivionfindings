import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    CalendarClock,
    Eye,
    Plus,
    Tag,
    UsersRound,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
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
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    POLICY_STATUS_OPTIONS,
    PolicyWizardDialog,
    policyCategoryLabel,
} from './_dialogs';
import { PolicyViewToggle, confirmedOf, plural } from './_shared';

interface Policy {
    id: number;
    title: string;
    category: string;
    version: number;
    status: string;
    effective_date: string | null;
    review_date: string | null;
    requires_attestation: boolean;
    confirmation: { confirmed: number; total: number; in_effect: boolean } | null;
}

interface Filters {
    search: string | null;
    category: string | null;
    status: string | null;
    review: string | null;
    confirm: string | null;
}

interface Props extends PageProps {
    policies: {
        data: Policy[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
        last_page: number;
    };
    filters: Filters;
    summary: {
        total: number;
        active: number;
        draft: number;
        requires_attestation: number;
        waiting_on_members: number;
        board_member_count: number;
        review_overdue: number;
    };
    categories: Array<{ value: string; label: string }>;
}

function cleanParams(values: Partial<Filters>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values).filter(
            ([, v]) => v !== null && v !== undefined && v !== '' && v !== 'all',
        ),
    ) as Record<string, string>;
}

export default function PolicyIndex({
    auth,
    policies,
    filters,
    summary,
    categories,
}: Props) {
    const canManage = Boolean(auth.can?.governance?.policies?.manage);
    const [wizardOpen, setWizardOpen] = useDialogDeepLink('create', canManage);
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<Policy>();
    // The NZ calendar date, not the UTC one (a day out on NZ mornings).
    const today = toDateInput(new Date());

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Partial<Filters>) =>
        router.get(
            '/governance/policies',
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

    const hasFilters = Boolean(
        filters.search ||
            filters.category ||
            filters.status ||
            filters.review ||
            filters.confirm,
    );
    const clearFilters = () =>
        router.get('/governance/policies', {}, { preserveScroll: true });

    const openPolicy = (policy: Policy) =>
        router.visit(`/governance/policies/${policy.id}`);

    const reviewOverdue = (policy: Policy) =>
        Boolean(
            policy.status === 'active' &&
                policy.review_date &&
                policy.review_date < today,
        );

    const actionsFor = (policy: Policy): MenuItem[] =>
        compactMenu([
            { label: 'Open policy', icon: Eye, onClick: () => openPolicy(policy) },
            canManage &&
                policy.confirmation !== null && {
                    label: 'Who has confirmed',
                    icon: UsersRound,
                    onClick: () =>
                        router.visit(
                            `/governance/policies/${policy.id}#confirmations`,
                        ),
                },
        ]);

    const activePercent =
        summary.total > 0 ? (summary.active / summary.total) * 100 : 0;

    const columns: EntityTableColumn<Policy>[] = [
        {
            key: 'category',
            label: 'Category',
            width: '1fr',
            cell: (p) => (
                <EntityChip icon={Tag}>{policyCategoryLabel(p.category)}</EntityChip>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (p) => {
                const chip = governanceStatus('policy_status', p.status);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.6fr',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    Version {p.version}
                </span>
            ),
        },
        {
            key: 'review',
            label: 'Next review',
            width: '0.9fr',
            cell: (p) =>
                p.review_date ? (
                    reviewOverdue(p) ? (
                        <EntityStatusChip variant="critical">
                            Overdue · {formatDateOnly(p.review_date)}
                        </EntityStatusChip>
                    ) : (
                        formatDateOnly(p.review_date)
                    )
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'confirmed',
            label: 'Read and confirmed',
            width: '1fr',
            cell: (p) =>
                p.confirmation ? (
                    p.confirmation.in_effect ? (
                        <EntityStatusChip
                            variant={
                                p.confirmation.total > 0 &&
                                p.confirmation.confirmed >= p.confirmation.total
                                    ? 'success'
                                    : 'warning'
                            }
                        >
                            Confirmed:{' '}
                            {confirmedOf(
                                p.confirmation.confirmed,
                                p.confirmation.total,
                            )}
                        </EntityStatusChip>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            Not in effect yet
                        </span>
                    )
                ) : p.requires_attestation ? (
                    <span className="text-xs text-muted-foreground">
                        Once approved
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Not needed
                    </span>
                ),
        },
    ];

    const header = (
        <PageHeader
            icon={BookOpen}
            title="Policies"
            titleChip={
                summary.total === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        No policies yet
                    </PageHeaderStatusChip>
                ) : summary.review_overdue > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {plural(summary.review_overdue, 'review')} overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Reviews up to date
                    </PageHeaderStatusChip>
                )
            }
            subline={`Rules the board has approved — read and confirm them here · ${plural(summary.total, 'policy', 'policies')}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search policies…"
                    />
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            New policy
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Policies"
                        ariaLabel="View all policies"
                        href="/governance/policies"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.draft} in draft
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved"
                        value={summary.active}
                        ariaLabel="View approved policies"
                        href="/governance/policies?status=active"
                    >
                        <PageHeaderMeterDonut
                            percent={activePercent}
                            caption={`${summary.active} of ${summary.total} policies`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft policies"
                        href="/governance/policies?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not approved yet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Waiting on members"
                        tone={summary.waiting_on_members > 0 ? 'warning' : 'brand'}
                        ariaLabel="View policies board members still need to confirm"
                        href="/governance/policies?confirm=waiting"
                    >
                        <PageHeaderMeterBig>
                            {summary.waiting_on_members}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.waiting_on_members === 1
                                ? 'policy not confirmed by everyone'
                                : 'policies not confirmed by everyone'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Review overdue"
                        tone={summary.review_overdue > 0 ? 'critical' : 'brand'}
                        ariaLabel="View policies overdue for review"
                        href="/governance/policies?review=overdue"
                    >
                        <PageHeaderMeterBig>
                            {summary.review_overdue}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their review date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PolicyViewToggle value="policies" />
                    <PageHeaderFilterSelect
                        icon={Tag}
                        label="All categories"
                        value={filters.category ?? 'all'}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories,
                        ]}
                        onChange={(v) => go({ category: v })}
                    />
                    <PageHeaderFilterSelect
                        label="Any status"
                        value={filters.status ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any status' },
                            ...POLICY_STATUS_OPTIONS,
                        ]}
                        onChange={(v) => go({ status: v })}
                    />
                    <PageHeaderFilterCheck
                        label="Review overdue"
                        checked={filters.review === 'overdue'}
                        onChange={(on) => go({ review: on ? 'overdue' : null })}
                    />
                    <PageHeaderFilterCheck
                        label="Waiting on members"
                        checked={filters.confirm === 'waiting'}
                        onChange={(on) => go({ confirm: on ? 'waiting' : null })}
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
                { title: 'Policies', href: '/governance/policies' },
            ]}
        >
            <Head title="Policies" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={hasFilters ? 'Matching policies' : 'All policies'}
                        caption={`${policies.data.length} of ${policies.total} shown`}
                        right={
                            hasFilters ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />

                    {policies.data.length === 0 ? (
                        <EmptyState
                            icon={BookOpen}
                            title={
                                hasFilters
                                    ? 'No policies match your filters'
                                    : 'No policies yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canManage
                                      ? 'Write the first board policy to start the library.'
                                      : 'Board policies appear here once the board approves them.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setWizardOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New policy
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={policies.data}
                            rowKey={(p) => p.id}
                            identityLabel="Policy"
                            identity={(p) => ({
                                icon: reviewOverdue(p) ? CalendarClock : BookOpen,
                                name: p.title,
                                linkLabel: `Open ${p.title}`,
                                subline: p.effective_date
                                    ? `${policyCategoryLabel(p.category)} · In effect from ${formatDateOnly(p.effective_date)}`
                                    : `${policyCategoryLabel(p.category)} · No date set`,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            hrefFor={(p) => `/governance/policies/${p.id}`}
                            onOpen={openPolicy}
                            onRowContextMenu={(e, p) => ctxMenu.open(e, p)}
                            mutedFor={(p) =>
                                p.status === 'archived' || p.status === 'superseded'
                            }
                            minWidth={820}
                        />
                    )}

                    <LaravelPagination
                        links={policies.links}
                        lastPage={policies.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={BookOpen}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage ? (
                <PolicyWizardDialog
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                />
            ) : null}
        </AppLayout>
    );
}
