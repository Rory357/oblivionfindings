import {
    DonorFundDialog,
    FinanceSectionRail,
    formatMoney,
    type DonorFundFundingStream,
    type DonorFundGlAccount,
    type EditableDonorFund,
} from '@/components/finance';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
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
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    Download,
    Eye,
    HandHeart,
    Pencil,
    Plus,
    Receipt,
    X,
} from 'lucide-react';
import { useState } from 'react';

interface Fund {
    id: number;
    fund_code: string;
    fund_name: string;
    donor_name: string | null;
    donor_contact: string | null;
    fund_type: string;
    gl_account_id: number | null;
    funding_stream_id: number | null;
    restrictions: string | null;
    reporting_requirements: string | null;
    total_received: number;
    total_spent: number;
    available_balance: number;
    budget_amount: number | null;
    status: string;
    is_restricted: boolean;
    start_date: string | null;
    end_date: string | null;
    next_report_due: string | null;
    gl_account_name: string | null;
    funding_stream_name: string | null;
}

interface Summary {
    total_funds: number;
    total_received: number;
    total_spent: number;
    total_available: number;
    restricted_balance: number;
    unrestricted_balance: number;
    expiring_soon: number;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginatorLink[];
    last_page: number;
}

interface Filters {
    search?: string;
    status?: string;
    restricted?: string;
}

interface Props extends PageProps {
    funds: Paginated<Fund>;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    glAccounts: DonorFundGlAccount[];
    fundingStreams: DonorFundFundingStream[];
}

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FUND_TYPE_LABELS: Record<string, string> = {
    grant: 'Grant',
    donation: 'Donation',
    bequest: 'Bequest',
    trust: 'Trust',
    government: 'Government',
    sponsorship: 'Sponsorship',
};

const FUND_STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    active: { label: 'Active', variant: 'success' },
    fully_spent: { label: 'Fully spent', variant: 'warning' },
    expired: { label: 'Expired', variant: 'critical' },
    returned: { label: 'Returned', variant: 'neutral' },
};

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'fully_spent', label: 'Fully spent' },
    { value: 'expired', label: 'Expired' },
    { value: 'returned', label: 'Returned' },
];

const RESTRICTED_OPTIONS = [
    { value: 'all', label: 'Restricted and unrestricted' },
    { value: 'restricted', label: 'Restricted only' },
    { value: 'unrestricted', label: 'Unrestricted only' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Tax & compliance', href: '/finance/tax' },
    { title: 'Donor funds', href: '/finance/donor-funds' },
];

export default function DonorFundsIndex({
    funds,
    filters,
    summary,
    canManage = false,
    glAccounts = [],
    fundingStreams = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editTarget, setEditTarget] = useState<Fund | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const status = filters.status ?? 'all';
    const restricted = filters.restricted ?? 'all';

    const apply = (next: Partial<Filters>) => {
        const merged: Filters = { search, status, restricted, ...next };
        const params: Record<string, string> = {};
        Object.entries(merged).forEach(([key, value]) => {
            if (value && value !== 'all') params[key] = value;
        });
        router.get('/finance/donor-funds', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/donor-funds', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.search || status !== 'all' || restricted !== 'all',
    );

    const exportUrl = `/finance/donor-funds/export?${new URLSearchParams(
        Object.entries({
            search: filters.search ?? '',
            status: status !== 'all' ? status : '',
            restricted: restricted !== 'all' ? restricted : '',
        }).filter(([, v]) => v) as [string, string][],
    ).toString()}`;

    const restrictedShare =
        summary.restricted_balance + summary.unrestricted_balance === 0
            ? 0
            : (summary.restricted_balance /
                  (summary.restricted_balance + summary.unrestricted_balance)) *
              100;

    const ctx = useEntityContextMenu<Fund>();

    /** The ONE menu feeding both the kebab and the right-click menu. */
    const actionsFor = (fund: Fund): MenuItem[] => {
        const items: MenuItem[] = [
            {
                label: 'Open fund',
                icon: Eye,
                onClick: () => router.visit(`/finance/donor-funds/${fund.id}`),
            },
        ];
        if (canManage) {
            items.push(
                {
                    label: 'Edit fund',
                    icon: Pencil,
                    onClick: () => setEditTarget(fund),
                },
                { separator: true },
                {
                    label: 'Record receipt',
                    icon: Banknote,
                    onClick: () =>
                        router.visit(
                            `/finance/donor-funds/${fund.id}?action=receipt`,
                        ),
                },
                {
                    label: 'Record expenditure',
                    icon: Receipt,
                    onClick: () =>
                        router.visit(
                            `/finance/donor-funds/${fund.id}?action=expenditure`,
                        ),
                },
            );
        }
        return items;
    };

    const columns: EntityTableColumn<Fund>[] = [
        {
            key: 'donor',
            label: 'Donor',
            width: '1fr',
            cell: (fund) =>
                fund.donor_name ? (
                    <span className="truncate">{fund.donor_name}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'type',
            label: 'Type',
            width: '140px',
            cell: (fund) => (
                <EntityChip>
                    {FUND_TYPE_LABELS[fund.fund_type] ?? fund.fund_type}
                </EntityChip>
            ),
        },
        {
            key: 'received',
            label: 'Received',
            width: '150px',
            align: 'right',
            cell: (fund) => (
                <span className="tabular-nums">
                    {formatMoney(fund.total_received)}
                </span>
            ),
        },
        {
            key: 'spent',
            label: 'Spent',
            width: '150px',
            align: 'right',
            cell: (fund) => (
                <span className="tabular-nums">
                    {formatMoney(fund.total_spent)}
                </span>
            ),
        },
        {
            key: 'available',
            label: 'Available',
            width: '180px',
            align: 'right',
            cell: (fund) => {
                const utilisation = fund.budget_amount
                    ? Math.round((fund.total_spent / fund.budget_amount) * 100)
                    : null;
                return (
                    <span className="font-semibold tabular-nums">
                        {formatMoney(fund.available_balance)}
                        {utilisation !== null ? (
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                {utilisation}% used
                            </span>
                        ) : null}
                    </span>
                );
            },
        },
        {
            key: 'restricted',
            label: 'Restriction',
            width: '150px',
            cell: (fund) => (
                <StatusBadge
                    status={fund.is_restricted ? 'restricted' : 'unrestricted'}
                />
            ),
        },
        {
            key: 'end_date',
            label: 'End date',
            width: '150px',
            cell: (fund) => {
                if (!fund.end_date) return <EmptyValue />;
                const past = new Date(fund.end_date) < new Date();
                return past ? (
                    <EntityStatusChip variant="critical">
                        {shortDate(fund.end_date)}
                    </EntityStatusChip>
                ) : (
                    <span className="whitespace-nowrap text-muted-foreground">
                        {shortDate(fund.end_date)}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '160px',
            cell: (fund) => {
                const badge = FUND_STATUS[fund.status] ?? FUND_STATUS.active;
                const reportDue =
                    fund.next_report_due &&
                    new Date(fund.next_report_due) <= new Date();
                return (
                    <span className="flex items-center gap-1.5">
                        <StatusBadge
                            variant={badge.variant}
                            label={badge.label}
                        />
                        {reportDue ? (
                            <AlertTriangle
                                className="h-4 w-4 text-status-warning"
                                aria-label="Report overdue"
                            />
                        ) : null}
                    </span>
                );
            },
        },
    ];

    const editableFund: EditableDonorFund | null = editTarget
        ? {
              id: editTarget.id,
              fund_code: editTarget.fund_code,
              fund_name: editTarget.fund_name,
              donor_name: editTarget.donor_name,
              donor_contact: editTarget.donor_contact,
              fund_type: editTarget.fund_type,
              gl_account_id: editTarget.gl_account_id,
              funding_stream_id: editTarget.funding_stream_id,
              budget_amount: editTarget.budget_amount,
              start_date: editTarget.start_date,
              end_date: editTarget.end_date,
              restrictions: editTarget.restrictions,
              reporting_requirements: editTarget.reporting_requirements,
              next_report_due: editTarget.next_report_due,
              is_restricted: editTarget.is_restricted,
              status: editTarget.status,
          }
        : null;

    const header = (
        <PageHeader
            icon={HandHeart}
            title="Donor funds"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.expiring_soon > 0 ? 'warning' : 'success'}
                >
                    {summary.total_funds} fund
                    {summary.total_funds === 1 ? '' : 's'}
                </PageHeaderStatusChip>
            }
            subline={`Tax & compliance · donations, grants and restricted funding · ${formatMoney(
                summary.total_available,
            )} still available`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({ search });
                        }}
                        placeholder="Search fund, code or donor…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New fund
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Received"
                        tone="success"
                        href="/finance/donor-funds"
                        ariaLabel="View every donor fund"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_received)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            donated across {summary.total_funds} fund
                            {summary.total_funds === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Spent"
                        href="/finance/donor-funds"
                        ariaLabel="View every donor fund"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_spent)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            applied to approved expenditure
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Available"
                        tone="success"
                        href="/finance/donor-funds?status=active"
                        ariaLabel="View active donor funds"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_available)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            still to be spent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Restricted"
                        href="/finance/donor-funds?restricted=restricted"
                        ariaLabel="View restricted donor funds"
                    >
                        <PageHeaderMeterDonut
                            percent={restrictedShare}
                            caption={`${formatMoney(summary.restricted_balance)} restricted · ${formatMoney(
                                summary.unrestricted_balance,
                            )} unrestricted`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Expiring soon"
                        tone={summary.expiring_soon > 0 ? 'warning' : 'brand'}
                        href="/finance/donor-funds?status=active"
                        ariaLabel="View active donor funds"
                    >
                        <PageHeaderMeterBig>
                            {summary.expiring_soon}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            funds close to their end date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue="all"
                        options={STATUS_OPTIONS}
                        onChange={(value) => apply({ status: value })}
                    />
                    <PageHeaderFilterSelect
                        label="Restriction"
                        value={restricted}
                        allValue="all"
                        options={RESTRICTED_OPTIONS}
                        onChange={(value) => apply({ restricted: value })}
                    />
                    {hasFilters ? (
                        <PageHeaderFilterButton icon={X} onClick={clearFilters}>
                            Clear filters
                        </PageHeaderFilterButton>
                    ) : null}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Donor funds" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Funds"
                        caption={`${funds.data.length} of ${summary.total_funds} shown`}
                    />

                    {funds.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No funds match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={HandHeart}
                                itemName="donor fund"
                                title="No donor funds yet"
                                description="Create your first fund to start tracking donations and grants."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New fund
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={funds.data}
                                rowKey={(fund) => fund.id}
                                identityLabel="Fund"
                                minWidth={1400}
                                identity={(fund) => ({
                                    icon: HandHeart,
                                    name: fund.fund_name,
                                    subline: fund.fund_code,
                                })}
                                hrefFor={(fund) =>
                                    `/finance/donor-funds/${fund.id}`
                                }
                                columns={columns}
                                actionsFor={actionsFor}
                                onOpen={(fund) =>
                                    router.visit(
                                        `/finance/donor-funds/${fund.id}`,
                                    )
                                }
                                onRowContextMenu={(e, fund) =>
                                    ctx.open(e, fund)
                                }
                            />
                            <LaravelPagination
                                links={funds.links}
                                lastPage={funds.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={HandHeart}
                    title={ctx.ctx.record.fund_name}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage && (
                <DonorFundDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    glAccounts={glAccounts}
                    fundingStreams={fundingStreams}
                />
            )}

            {canManage && editableFund ? (
                <DonorFundDialog
                    key={editableFund.id}
                    open
                    onClose={() => setEditTarget(null)}
                    glAccounts={glAccounts}
                    fundingStreams={fundingStreams}
                    fund={editableFund}
                />
            ) : null}
        </AppLayout>
    );
}
