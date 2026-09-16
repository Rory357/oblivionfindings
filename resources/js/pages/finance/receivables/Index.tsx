import { FinanceSectionRail, FinanceTierTwoNav } from '@/components/finance';
import { formatMoney } from '@/components/finance/money';
import {
    EntityContextMenu,
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
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarRange, FileText, Receipt, User } from 'lucide-react';
import { useMemo, useState } from 'react';

type BucketKey = 'current' | '1_30' | '31_60' | '61_90' | '90_plus';

type ClientAging = {
    client_id: number | null;
    client_name: string;
    current: number;
    '1_30': number;
    '31_60': number;
    '61_90': number;
    '90_plus': number;
    total: number;
};

type Totals = Record<BucketKey, number> & { total: number };

type Summary = {
    total_outstanding: number;
    total_overdue: number;
    unpaid_count: number;
    overdue_count: number;
    client_count: number;
};

type PageProps = {
    clients: ClientAging[];
    totals: Totals;
    summary: Summary;
};

const ALL = '__all';

const BUCKETS: { key: BucketKey; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: '1_30', label: '1–30 days' },
    { key: '31_60', label: '31–60 days' },
    { key: '61_90', label: '61–90 days' },
    { key: '90_plus', label: '90+ days' },
];

const BUCKET_OPTIONS = [
    { value: ALL, label: 'Any age' },
    ...BUCKETS.map((bucket) => ({ value: bucket.key, label: bucket.label })),
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Aged AR', href: '/finance/receivables' },
];

export default function AgedReceivables({
    clients,
    totals,
    summary,
}: PageProps) {
    const [search, setSearch] = useState('');
    const [bucket, setBucket] = useState<string>(ALL);
    const [overdueOnly, setOverdueOnly] = useState(false);
    const ctxMenu = useEntityContextMenu<ClientAging>();

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();
        return clients.filter((client) => {
            if (term && !client.client_name.toLowerCase().includes(term))
                return false;
            if (bucket !== ALL && !(client[bucket as BucketKey] > 0))
                return false;
            if (overdueOnly && client.total - client.current <= 0) return false;
            return true;
        });
    }, [clients, search, bucket, overdueOnly]);

    const hasFilters = Boolean(search.trim()) || bucket !== ALL || overdueOnly;

    const clearFilters = () => {
        setSearch('');
        setBucket(ALL);
        setOverdueOnly(false);
    };

    const overduePct =
        totals.total > 0
            ? Math.min(
                  100,
                  Math.round(
                      ((totals.total - totals.current) / totals.total) * 100,
                  ),
              )
            : 0;

    const statementHref = (client: ClientAging) =>
        client.client_id
            ? `/finance/receivables/statements?client_id=${client.client_id}`
            : '/finance/receivables/statements';

    const actionsFor = (client: ClientAging): MenuItem[] =>
        compactMenu([
            client.client_id
                ? {
                      label: 'Open statement',
                      icon: FileText,
                      onClick: () => router.visit(statementHref(client)),
                  }
                : null,
            {
                label: 'View unpaid invoices',
                icon: Receipt,
                onClick: () =>
                    router.visit(
                        `/finance/invoices?status=unpaid&search=${encodeURIComponent(
                            client.client_name,
                        )}`,
                    ),
            },
        ]);

    const amountCell = (value: number, tone?: 'warning' | 'critical') => {
        if (!value) return <span className="text-muted-foreground">—</span>;
        return (
            <span
                className={
                    tone === 'critical'
                        ? 'font-medium text-status-critical tabular-nums'
                        : tone === 'warning'
                          ? 'font-medium text-status-warning tabular-nums'
                          : 'tabular-nums'
                }
            >
                {formatMoney(value)}
            </span>
        );
    };

    const columns: EntityTableColumn<ClientAging>[] = [
        {
            key: 'current',
            label: 'Current',
            width: '0.8fr',
            align: 'right',
            cell: (client) => amountCell(client.current),
        },
        {
            key: '1_30',
            label: '1–30 days',
            width: '0.8fr',
            align: 'right',
            cell: (client) => amountCell(client['1_30'], 'warning'),
        },
        {
            key: '31_60',
            label: '31–60 days',
            width: '0.8fr',
            align: 'right',
            cell: (client) => amountCell(client['31_60'], 'warning'),
        },
        {
            key: '61_90',
            label: '61–90 days',
            width: '0.8fr',
            align: 'right',
            cell: (client) => amountCell(client['61_90'], 'critical'),
        },
        {
            key: '90_plus',
            label: '90+ days',
            width: '0.8fr',
            align: 'right',
            cell: (client) => amountCell(client['90_plus'], 'critical'),
        },
        {
            key: 'total',
            label: 'Total owing',
            width: '0.9fr',
            align: 'right',
            cell: (client) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(client.total)}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={CalendarRange}
            title="Aged receivables"
            titleChip={
                summary.overdue_count > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {summary.overdue_count} overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Nothing overdue
                    </PageHeaderStatusChip>
                )
            }
            subline={`Accounts receivable · ${summary.client_count} payers with a balance · ${summary.unpaid_count} unpaid invoices`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search payers…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Outstanding"
                        href="/finance/invoices?status=unpaid"
                        ariaLabel="View unpaid invoices"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.total)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {summary.client_count} payers
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Within terms"
                        tone="success"
                        href="/finance/invoices?status=sent"
                        ariaLabel="View invoices still within terms"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.current)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Not yet due
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue share"
                        tone={overduePct > 0 ? 'warning' : 'brand'}
                        href="/finance/invoices?status=overdue"
                        ariaLabel="View overdue invoices"
                    >
                        <PageHeaderMeterDonut
                            percent={overduePct}
                            caption={`${formatMoney(
                                totals.total - totals.current,
                            )} past due`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="90+ days"
                        tone={totals['90_plus'] > 0 ? 'critical' : 'brand'}
                        href="/finance/receivables/statements"
                        ariaLabel="Open client statements"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals['90_plus'])}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Issue a statement
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Age"
                        value={bucket}
                        allValue={ALL}
                        options={BUCKET_OPTIONS}
                        onChange={setBucket}
                    />
                    <PageHeaderFilterCheck
                        label="Overdue only"
                        checked={overdueOnly}
                        onChange={setOverdueOnly}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Aged receivables" />

            <PageLayout hero={header} tabs={<FinanceTierTwoNav />}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Ageing by payer"
                        caption={`${rows.length} of ${clients.length} shown`}
                        right={
                            <span className="text-caption tabular-nums">
                                {BUCKETS.map(
                                    (b) =>
                                        `${b.label} ${formatMoney(totals[b.key])}`,
                                ).join(' · ')}
                            </span>
                        }
                    />

                    {rows.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No payers match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={CalendarRange}
                                itemName="balance"
                                title="Nothing outstanding"
                                description="Every sent invoice has been receipted in full."
                            />
                        )
                    ) : (
                        <EntityTable
                            rows={rows}
                            rowKey={(client) =>
                                client.client_id ?? client.client_name
                            }
                            identityLabel="Payer"
                            identity={(client) => ({
                                icon: User,
                                name: client.client_name,
                                subline: client.client_id
                                    ? 'Client'
                                    : 'Funder or other payer',
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            hrefFor={(client) =>
                                client.client_id
                                    ? statementHref(client)
                                    : `/finance/invoices?status=unpaid&search=${encodeURIComponent(
                                          client.client_name,
                                      )}`
                            }
                            onRowContextMenu={ctxMenu.open}
                            minWidth={1040}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={User}
                    title={ctxMenu.ctx.record.client_name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}
