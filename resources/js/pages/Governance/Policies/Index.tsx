import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    CalendarClock,
    ClipboardCheck,
    Eye,
    Plus,
    Tag,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    CounterPill,
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
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import {
    POLICY_STATUS_OPTIONS,
    POLICY_STATUS_VARIANT,
    PolicyWizardDialog,
    policyCategoryLabel,
    policyStatusLabel,
} from './_dialogs';

interface Policy {
    id: number;
    title: string;
    category: string;
    version: number;
    status: string;
    effective_date: string | null;
    review_date: string | null;
    requires_attestation: boolean;
    attestations_count: number;
}

interface Filters {
    search: string | null;
    category: string | null;
    status: string | null;
    review: string | null;
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
    const today = new Date().toISOString().split('T')[0];

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
        filters.search || filters.category || filters.status || filters.review,
    );
    const clearFilters = () =>
        router.get('/governance/policies', {}, { preserveScroll: true });

    const closeWizard = () => setWizardOpen(false);

    const openPolicy = (policy: Policy) =>
        router.visit(`/governance/policies/${policy.id}`);

    const actionsFor = (policy: Policy): MenuItem[] =>
        compactMenu([
            { label: 'Open policy', icon: Eye, onClick: () => openPolicy(policy) },
            policy.requires_attestation &&
                policy.status === 'active' && {
                    label: 'Attestations',
                    icon: ClipboardCheck,
                    onClick: () =>
                        router.visit('/governance/policies/attestations'),
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
            width: '0.8fr',
            cell: (p) => (
                <EntityStatusChip
                    variant={POLICY_STATUS_VARIANT[p.status] ?? 'neutral'}
                >
                    {policyStatusLabel(p.status)}
                </EntityStatusChip>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.5fr',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    v{p.version}
                </span>
            ),
        },
        {
            key: 'review',
            label: 'Next review',
            width: '0.9fr',
            cell: (p) =>
                p.review_date ? (
                    <span
                        className={
                            p.status === 'active' && p.review_date < today
                                ? 'font-semibold text-status-critical'
                                : undefined
                        }
                    >
                        {formatDateOnly(p.review_date)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'attestations',
            label: 'Attestations',
            width: '0.8fr',
            cell: (p) =>
                p.requires_attestation ? (
                    <CounterPill tone="neutral">{p.attestations_count}</CounterPill>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Not required
                    </span>
                ),
        },
    ];

    const header = (
        <PageHeader
            icon={BookOpen}
            title="Governance policies"
            subline="Board policies, review schedule and member attestation"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search policies by title or code…"
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
                        label="Active"
                        value={summary.active}
                        ariaLabel="View active policies"
                        href="/governance/policies?status=active"
                    >
                        <PageHeaderMeterDonut
                            percent={activePercent}
                            caption={
                                <>
                                    {summary.active} of {summary.total}
                                    <br />
                                    in effect
                                </>
                            }
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft policies"
                        href="/governance/policies?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            awaiting approval
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Attestation"
                        ariaLabel="View policy attestations"
                        href="/governance/policies/attestations"
                    >
                        <PageHeaderMeterBig>
                            {summary.requires_attestation}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active policies need sign-off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Review overdue"
                        tone={summary.review_overdue > 0 ? 'critical' : 'success'}
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
            <Head title="Governance policies" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Policy library"
                        caption={`${policies.data.length} of ${policies.total} shown`}
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
                                      ? 'Create the first board policy to start the library.'
                                      : 'Board policies will appear here once they are published.'
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
                                icon: p.review_date && p.review_date < today && p.status === 'active'
                                    ? CalendarClock
                                    : BookOpen,
                                name: p.title,
                                subline: `${policyCategoryLabel(p.category)} · effective ${formatDateOnly(p.effective_date)}`,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            hrefFor={(p) => `/governance/policies/${p.id}`}
                            onOpen={openPolicy}
                            onRowContextMenu={(e, p) => ctxMenu.open(e, p)}
                            mutedFor={(p) => p.status === 'archived'}
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
                <PolicyWizardDialog open={wizardOpen} onClose={closeWizard} />
            ) : null}
        </AppLayout>
    );
}
