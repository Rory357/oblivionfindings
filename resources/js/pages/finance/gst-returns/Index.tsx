import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
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
import { formatDateOnly } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Calculator, Download, Eye, Plus, X } from 'lucide-react';

type GstReturn = {
    id: number;
    period_start: string;
    period_end: string;
    filing_frequency: string;
    basis: string;
    total_sales: string;
    total_gst_collected: string;
    total_purchases: string;
    total_gst_paid: string;
    gst_payable: string;
    status: string;
    ird_period: string;
    filed_at: string | null;
};

type PaginatedData = {
    data: GstReturn[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

/** Org-wide totals for the CURRENT filter — never the page in front of you. */
type Summary = {
    returns: number;
    gst_collected: number;
    gst_paid: number;
    gst_payable: number;
    draft: number;
    filed: number;
};

type PageProps = {
    gstReturns: PaginatedData;
    summary: Summary;
    filters: {
        status?: string;
        year?: string;
    };
};

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FREQUENCY_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    two_monthly: 'Two-monthly',
    six_monthly: 'Six-monthly',
};

const BASIS_LABELS: Record<string, string> = {
    invoice: 'Invoice',
    payments: 'Payments',
    hybrid: 'Hybrid',
};

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'filed', label: 'Filed' },
    { value: 'amended', label: 'Amended' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Tax & compliance', href: '/finance/tax' },
    { title: 'GST returns', href: '/finance/gst-returns' },
];

export default function GstReturnsIndex({
    gstReturns,
    summary,
    filters,
}: PageProps) {
    const currentYear = new Date().getFullYear();
    const yearOptions = [
        { value: 'all', label: 'All years' },
        ...Array.from({ length: 5 }, (_, i) => {
            const year = String(currentYear - i);
            return { value: year, label: year };
        }),
    ];

    const status = filters.status ?? 'all';
    const year = filters.year ?? 'all';

    const applyFilter = (key: string, value: string | undefined) => {
        const params: Record<string, string> = { ...filters };
        if (value && value !== 'all') {
            params[key] = value;
        } else {
            delete params[key];
        }
        router.get('/finance/gst-returns', params, { preserveState: true });
    };

    const clearFilters = () => {
        router.get('/finance/gst-returns', {}, { preserveState: true });
    };

    const hasFilters = status !== 'all' || year !== 'all';

    const exportUrl = `/finance/gst-returns/export?${new URLSearchParams(
        Object.entries({
            status: filters.status ?? '',
            year: filters.year ?? '',
        }).filter(([, v]) => v) as [string, string][],
    ).toString()}`;

    const ctx = useEntityContextMenu<GstReturn>();

    const actionsFor = (gstReturn: GstReturn): MenuItem[] => [
        {
            label: 'Open return',
            icon: Eye,
            onClick: () => router.visit(`/finance/gst-returns/${gstReturn.id}`),
        },
    ];

    const columns: EntityTableColumn<GstReturn>[] = [
        {
            key: 'frequency',
            label: 'Frequency',
            width: '140px',
            cell: (r) => (
                <EntityChip>
                    {FREQUENCY_LABELS[r.filing_frequency] ?? r.filing_frequency}
                </EntityChip>
            ),
        },
        {
            key: 'basis',
            label: 'Basis',
            width: '110px',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {BASIS_LABELS[r.basis] ?? r.basis}
                </span>
            ),
        },
        {
            key: 'sales',
            label: 'Total sales',
            width: '150px',
            align: 'right',
            cell: (r) => (
                <span className="tabular-nums">
                    {formatMoney(r.total_sales)}
                </span>
            ),
        },
        {
            key: 'collected',
            label: 'GST collected',
            width: '150px',
            align: 'right',
            cell: (r) => (
                <span className="tabular-nums">
                    {formatMoney(r.total_gst_collected)}
                </span>
            ),
        },
        {
            key: 'paid',
            label: 'GST paid',
            width: '140px',
            align: 'right',
            cell: (r) => (
                <span className="tabular-nums">
                    {formatMoney(r.total_gst_paid)}
                </span>
            ),
        },
        {
            key: 'payable',
            label: 'Net payable',
            width: '160px',
            align: 'right',
            cell: (r) => {
                const payable = Number(r.gst_payable);
                return (
                    <span className="font-semibold tabular-nums">
                        {formatMoney(Math.abs(payable))}
                        {payable < 0 ? ' refund' : ''}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '120px',
            cell: (r) => <StatusBadge status={r.status} />,
        },
    ];

    const netPayable = summary.gst_payable;

    const header = (
        <PageHeader
            icon={Calculator}
            title="GST returns"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.draft > 0 ? 'warning' : 'success'}
                >
                    {summary.draft} draft{summary.draft === 1 ? '' : 's'}
                </PageHeaderStatusChip>
            }
            subline={`Tax & compliance · ${summary.returns} return${
                summary.returns === 1 ? '' : 's'
            } in this view · ${summary.filed} filed with IRD`}
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
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit('/finance/gst-returns/prepare')
                        }
                    >
                        Prepare return
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="GST collected"
                        tone="success"
                        href="/finance/gst-returns"
                        ariaLabel="View every GST return"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.gst_collected)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            output tax on sales
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="GST paid"
                        href="/finance/gst-returns"
                        ariaLabel="View every GST return"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.gst_paid)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            input tax on purchases
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label={netPayable < 0 ? 'Net refund' : 'Net payable'}
                        tone={netPayable < 0 ? 'success' : 'warning'}
                        href="/finance/ird-filings"
                        ariaLabel="View IRD filings"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(Math.abs(netPayable))}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {netPayable < 0
                                ? 'due back from IRD'
                                : 'owing to IRD'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Filed"
                        tone="success"
                        href="/finance/gst-returns?status=filed"
                        ariaLabel="View filed GST returns"
                    >
                        <PageHeaderMeterDonut
                            percent={
                                summary.returns === 0
                                    ? 0
                                    : (summary.filed / summary.returns) * 100
                            }
                            caption={`${summary.filed} of ${summary.returns} lodged`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Drafts"
                        tone={summary.draft > 0 ? 'warning' : 'brand'}
                        href="/finance/gst-returns?status=draft"
                        ariaLabel="View draft GST returns"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting to be filed
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
                        onChange={(value) => applyFilter('status', value)}
                    />
                    <PageHeaderFilterSelect
                        label="Year"
                        value={year}
                        allValue="all"
                        options={yearOptions}
                        onChange={(value) => applyFilter('year', value)}
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
            <Head title="GST returns" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Returns"
                        caption={`${gstReturns.data.length} of ${summary.returns} shown`}
                    />

                    {gstReturns.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No GST returns match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Calculator}
                                itemName="GST return"
                                title="No GST returns yet"
                                description="Prepare your first return to get started."
                                action={
                                    <Button size="sm" asChild>
                                        <Link href="/finance/gst-returns/prepare">
                                            Prepare return
                                        </Link>
                                    </Button>
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={gstReturns.data}
                                rowKey={(r) => r.id}
                                identityLabel="Period"
                                minWidth={1180}
                                identity={(r) => ({
                                    icon: Calculator,
                                    name: `${shortDate(r.period_start)} – ${shortDate(r.period_end)}`,
                                    subline: `IRD period ${r.ird_period}`,
                                })}
                                hrefFor={(r) => `/finance/gst-returns/${r.id}`}
                                columns={columns}
                                actionsFor={actionsFor}
                                onOpen={(r) =>
                                    router.visit(`/finance/gst-returns/${r.id}`)
                                }
                                onRowContextMenu={(e, r) => ctx.open(e, r)}
                            />
                            <LaravelPagination
                                links={gstReturns.links}
                                lastPage={gstReturns.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Calculator}
                    title={`IRD period ${ctx.ctx.record.ird_period}`}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}
        </AppLayout>
    );
}
