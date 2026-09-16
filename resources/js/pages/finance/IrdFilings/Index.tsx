import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
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
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Download, Eye, Landmark, Plus, Users, X } from 'lucide-react';
import { useState } from 'react';

import {
    type GstReturnOption,
    NewGstFilingDialog,
    NewPaydayFilingDialog,
    type PayrollRunOption,
} from './_dialogs';

type Filing = {
    id: number;
    filing_type: string;
    period_from: string;
    period_to: string;
    total_amount: string;
    status: string;
    ird_reference: string | null;
    submitted_at: string | null;
    error_message: string | null;
    created_by: { id: number; name: string } | null;
    created_at: string;
};

type PaginatedData = {
    data: Filing[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

/** Org-wide totals for the CURRENT filter — never the page in front of you. */
type Summary = {
    filings: number;
    filed: number;
    pending: number;
    problems: number;
    filed_amount: number;
};

type PageProps = {
    filings: PaginatedData;
    availableGstReturns: GstReturnOption[];
    availablePayrollRuns: PayrollRunOption[];
    summary: Summary;
    filters: {
        filing_type?: string;
        status?: string;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Tax & compliance', href: '/finance/tax' },
    { title: 'IRD filings', href: '/finance/ird-filings' },
];

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FILING_TYPE_LABELS: Record<string, string> = {
    gst: 'GST return',
    payday: 'Payday filing',
    rlwt: 'RLWT',
    rwt: 'RWT',
    aim: 'AIM',
    ir3: 'IR3',
    ir4: 'IR4',
    ir7: 'IR7',
};

const TYPE_OPTIONS = [
    { value: 'all', label: 'All types' },
    { value: 'gst', label: 'GST' },
    { value: 'payday', label: 'Payday' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'validated', label: 'Validated' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'error', label: 'Error' },
];

export default function IrdFilingsIndex({
    filings,
    availableGstReturns,
    availablePayrollRuns,
    summary,
    filters,
}: PageProps) {
    const [gstDialogOpen, setGstDialogOpen] = useState(false);
    const [paydayDialogOpen, setPaydayDialogOpen] = useState(false);

    const filingType = filters.filing_type ?? 'all';
    const status = filters.status ?? 'all';

    const applyFilter = (key: string, value: string | undefined) => {
        const params: Record<string, string> = { ...filters };
        if (value && value !== 'all') {
            params[key] = value;
        } else {
            delete params[key];
        }
        router.get('/finance/ird-filings', params, { preserveState: true });
    };

    const clearFilters = () => {
        router.get('/finance/ird-filings', {}, { preserveState: true });
    };

    const hasFilters = filingType !== 'all' || status !== 'all';

    const exportUrl = `/finance/ird-filings/export?${new URLSearchParams(
        Object.entries({
            filing_type: filters.filing_type ?? '',
            status: filters.status ?? '',
        }).filter(([, v]) => v) as [string, string][],
    ).toString()}`;

    const ctx = useEntityContextMenu<Filing>();

    const actionsFor = (filing: Filing): MenuItem[] => [
        {
            label: 'Open filing',
            icon: Eye,
            onClick: () => router.visit(`/finance/ird-filings/${filing.id}`),
        },
    ];

    const columns: EntityTableColumn<Filing>[] = [
        {
            key: 'period',
            label: 'Period',
            width: '1fr',
            cell: (filing) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {shortDate(filing.period_from)} –{' '}
                    {shortDate(filing.period_to)}
                </span>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '160px',
            align: 'right',
            cell: (filing) => {
                const amount = Number(filing.total_amount);
                return (
                    <span className="font-semibold tabular-nums">
                        {formatMoney(Math.abs(amount))}
                        {amount < 0 ? ' refund' : ''}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (filing) => <StatusBadge status={filing.status} />,
        },
        {
            key: 'reference',
            label: 'IRD reference',
            width: '190px',
            cell: (filing) =>
                filing.ird_reference ? (
                    <span className="truncate tabular-nums">
                        {filing.ird_reference}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'submitted',
            label: 'Submitted',
            width: '190px',
            cell: (filing) =>
                filing.submitted_at ? (
                    <span className="whitespace-nowrap text-muted-foreground">
                        {formatDateTime(filing.submitted_at)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'created_by',
            label: 'Created by',
            width: '170px',
            cell: (filing) =>
                filing.created_by ? (
                    <span className="truncate">{filing.created_by.name}</span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    const header = (
        <PageHeader
            icon={Landmark}
            title="IRD filings"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.pending > 0 ? 'warning' : 'success'}
                >
                    {summary.pending} pending
                </PageHeaderStatusChip>
            }
            subline={`Tax & compliance · ${summary.filings} filing${
                summary.filings === 1 ? '' : 's'
            } in this view · ${summary.filed} sent to IRD`}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton
                        icon={Users}
                        onClick={() => setPaydayDialogOpen(true)}
                    >
                        New payday filing
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setGstDialogOpen(true)}
                    >
                        New GST filing
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Filings"
                        href="/finance/ird-filings"
                        ariaLabel="View every IRD filing"
                    >
                        <PageHeaderMeterBig>
                            {summary.filings}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {hasFilters ? 'matching this filter' : 'all time'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Sent to IRD"
                        tone="success"
                        href="/finance/ird-filings?status=submitted"
                        ariaLabel="View submitted IRD filings"
                    >
                        <PageHeaderMeterDonut
                            percent={
                                summary.filings === 0
                                    ? 0
                                    : (summary.filed / summary.filings) * 100
                            }
                            caption={`${summary.filed} of ${summary.filings} lodged`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Pending"
                        tone={summary.pending > 0 ? 'warning' : 'brand'}
                        href="/finance/ird-filings?status=draft"
                        ariaLabel="View draft IRD filings"
                    >
                        <PageHeaderMeterBig>
                            {summary.pending}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            drafted or validated, not yet sent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Needs attention"
                        tone={summary.problems > 0 ? 'critical' : 'brand'}
                        href="/finance/ird-filings?status=rejected"
                        ariaLabel="View rejected IRD filings"
                    >
                        <PageHeaderMeterBig>
                            {summary.problems}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            rejected or errored
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Value filed"
                        href="/finance/gst-returns"
                        ariaLabel="View GST returns"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.filed_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            declared across sent filings
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Type"
                        value={filingType}
                        allValue="all"
                        options={TYPE_OPTIONS}
                        onChange={(value) => applyFilter('filing_type', value)}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue="all"
                        options={STATUS_OPTIONS}
                        onChange={(value) => applyFilter('status', value)}
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
            <Head title="IRD filings" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Filings"
                        caption={`${filings.data.length} of ${summary.filings} shown`}
                    />

                    {filings.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No filings match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Landmark}
                                itemName="filing"
                                title="No filings yet"
                                description="Create a filing from a GST return or a posted payroll run to get started."
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setGstDialogOpen(true)}
                                    >
                                        New GST filing
                                    </Button>
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={filings.data}
                                rowKey={(filing) => filing.id}
                                identityLabel="Filing"
                                minWidth={1180}
                                identity={(filing) => ({
                                    icon: Landmark,
                                    name:
                                        FILING_TYPE_LABELS[
                                            filing.filing_type
                                        ] ?? filing.filing_type,
                                    subline: filing.error_message ?? undefined,
                                    extra: (
                                        <EntityChip>
                                            {filing.filing_type.toUpperCase()}
                                        </EntityChip>
                                    ),
                                })}
                                hrefFor={(filing) =>
                                    `/finance/ird-filings/${filing.id}`
                                }
                                columns={columns}
                                actionsFor={actionsFor}
                                onOpen={(filing) =>
                                    router.visit(
                                        `/finance/ird-filings/${filing.id}`,
                                    )
                                }
                                onRowContextMenu={(e, filing) =>
                                    ctx.open(e, filing)
                                }
                            />
                            <LaravelPagination
                                links={filings.links}
                                lastPage={filings.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Landmark}
                    title={
                        FILING_TYPE_LABELS[ctx.ctx.record.filing_type] ??
                        ctx.ctx.record.filing_type
                    }
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            <NewGstFilingDialog
                open={gstDialogOpen}
                onClose={() => setGstDialogOpen(false)}
                gstReturns={availableGstReturns}
            />
            <NewPaydayFilingDialog
                open={paydayDialogOpen}
                onClose={() => setPaydayDialogOpen(false)}
                payrollRuns={availablePayrollRuns}
            />
        </AppLayout>
    );
}
