import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityChip,
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
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, HandCoins, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    SpendApprovalWizardDialog,
    formatNzd,
    spendStatusLabel as statusLabel,
    spendStatusVariant,
    type SpendApprovalFormOptions,
} from './_dialogs';

interface Approval {
    id: number;
    reference: string;
    title: string;
    category: string;
    amount: number;
    currency: string;
    status: string;
    requires_board: boolean;
    requested_by_id?: number;
    requested_by?: { id: number; name: string } | null;
    requestedBy?: { id: number; name: string } | null;
    decided_by?: { id: number; name: string } | null;
    decidedBy?: { id: number; name: string } | null;
    resolution?: { id: number; title: string; outcome: string } | null;
    submitted_at: string | null;
    decided_at: string | null;
    created_at: string;
}

interface Props extends PageProps {
    approvals: {
        data: Approval[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status: string | null;
        category: string | null;
        search: string | null;
    };
    summary: {
        pending: number;
        approved_ytd: number;
        rejected_ytd: number;
    };
    categories: Record<string, string>;
    thresholds: Record<string, number>;
    can_create?: boolean;
    form_options?: { sites: SpendApprovalFormOptions['sites'] } | null;
}

const ALL = '__all';

const STATUSES = [
    { value: ALL, label: 'Any status' },
    { value: 'pending', label: 'Pending (draft + submitted)' },
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'expired', label: 'Expired' },
];


export default function SpendApprovalsIndex({
    approvals,
    filters,
    summary,
    categories,
    thresholds,
    can_create = false,
    form_options = null,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useDialogDeepLink(
        'create',
        can_create && Boolean(form_options),
    );
    const ctxMenu = useEntityContextMenu<Approval>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/spend-approvals', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

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
        filters.status || filters.category || filters.search,
    );
    const open = (approval: Approval) =>
        router.visit(`/governance/spend-approvals/${approval.id}`);

    const actionsFor = (approval: Approval): MenuItem[] =>
        compactMenu([
            {
                label: 'Open request',
                icon: ExternalLink,
                onClick: () => open(approval),
            },
        ]);

    const requester = (approval: Approval) =>
        approval.requestedBy ?? approval.requested_by ?? null;
    const decider = (approval: Approval) =>
        approval.decidedBy ?? approval.decided_by ?? null;

    const header = (
        <PageHeader
            icon={HandCoins}
            title="Spend approvals"
            subline="Board and finance-committee sign-off for spend above configured thresholds"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search title, reference, description…"
                    />
                    {can_create && form_options ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New request
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Pending"
                        href="/governance/spend-approvals?status=pending"
                        tone={summary.pending > 0 ? 'warning' : 'brand'}
                        ariaLabel="View pending spend approvals"
                    >
                        <PageHeaderMeterBig>{summary.pending}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Draft or awaiting sign-off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved YTD"
                        href="/governance/spend-approvals?status=approved"
                    >
                        <PageHeaderMeterBig>
                            {formatNzd(summary.approved_ytd)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Authorised spend this year
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Rejected YTD"
                        href="/governance/spend-approvals?status=rejected"
                        tone={summary.rejected_ytd > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {formatNzd(summary.rejected_ytd)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Declined spend this year
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={STATUSES}
                        onChange={(value) =>
                            go({ status: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Category"
                        value={filters.category ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any category' },
                            ...Object.entries(categories).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        ]}
                        onChange={(value) =>
                            go({ category: value === ALL ? null : value })
                        }
                    />
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
                {
                    title: 'Spend approvals',
                    href: '/governance/spend-approvals',
                },
            ]}
        >
            <Head title="Spend approvals" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Approval thresholds
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {Object.entries(thresholds).map(([key, value]) => (
                                <div
                                    key={key}
                                    className="rounded-lg bg-muted/40 p-3"
                                >
                                    <p className="text-caption tracking-wide uppercase">
                                        {categories[key] ?? key}
                                    </p>
                                    <p className="mt-1 text-lg font-semibold tabular-nums">
                                        {formatNzd(value)}
                                    </p>
                                    <p className="text-caption">
                                        requires sign-off
                                    </p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <ListCaption
                        title="Requests"
                        caption={`${approvals.data.length} of ${approvals.total} shown`}
                    />

                    {approvals.data.length === 0 ? (
                        <EmptyState
                            icon={HandCoins}
                            title={
                                hasFilters
                                    ? 'No spend approvals match your filters'
                                    : 'No spend approvals yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Submit a request for board or finance-committee sign-off when spend exceeds the configured threshold.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({
                                                status: null,
                                                category: null,
                                                search: null,
                                            });
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : can_create && form_options ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New request
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={approvals.data}
                            rowKey={(approval) => approval.id}
                            identityLabel="Request"
                            identity={(approval) => ({
                                icon: HandCoins,
                                name: approval.title,
                                subline: `${approval.reference} · ${categories[approval.category] ?? approval.category}`,
                            })}
                            hrefFor={(approval) =>
                                `/governance/spend-approvals/${approval.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            minWidth={1000}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.8fr',
                                    cell: (approval) => (
                                        <EntityStatusChip
                                            variant={spendStatusVariant(
                                                approval.status,
                                            )}
                                        >
                                            {statusLabel(approval.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    width: '0.8fr',
                                    align: 'right',
                                    cell: (approval) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatNzd(approval.amount)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'board',
                                    label: 'Board sign-off',
                                    width: '0.8fr',
                                    cell: (approval) =>
                                        approval.requires_board ? (
                                            <EntityChip>Required</EntityChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'requested_by',
                                    label: 'Requested by',
                                    width: '1fr',
                                    cell: (approval) => (
                                        <PersonCell
                                            name={requester(approval)?.name}
                                        />
                                    ),
                                },
                                {
                                    key: 'created',
                                    label: 'Created',
                                    width: '0.8fr',
                                    cell: (approval) =>
                                        formatDateLong(approval.created_at),
                                },
                                {
                                    key: 'decided',
                                    label: 'Decided',
                                    width: '1fr',
                                    cell: (approval) =>
                                        approval.decided_at ? (
                                            <span className="truncate">
                                                {formatDateLong(
                                                    approval.decided_at,
                                                )}
                                                {decider(approval)
                                                    ? ` · ${decider(approval)?.name}`
                                                    : ''}
                                            </span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={approvals.links}
                        lastPage={approvals.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={HandCoins}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can_create && form_options ? (
                <SpendApprovalWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={{
                        categories,
                        thresholds,
                        sites: form_options.sites,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
