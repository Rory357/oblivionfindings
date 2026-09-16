import {
    FinanceSectionRail,
    FinanceTierTwoNav,
    formatMoney,
} from '@/components/finance';
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
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarRange,
    CreditCard,
    Eye,
    Layers,
    Scale,
    Smartphone,
} from 'lucide-react';
import { useState } from 'react';

import { ReconcileBatchDialog, type UnmatchedBankTransaction } from './_dialogs';

interface Batch {
    id: number;
    batch_number: string;
    batch_date: string;
    terminal_name: string | null;
    terminal_id_code: string | null;
    total_transactions: number;
    total_amount: number;
    total_refunds: number;
    net_amount: number;
    fees: number;
    settlement_amount: number;
    status: string;
    reconciled_at: string | null;
    reconciled_by_name: string | null;
    discrepancy_amount: number;
    bank_transaction_amount: number | null;
}

interface Terminal {
    id: number;
    name: string;
    terminal_id: string;
}

interface Pagination {
    data: Batch[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Summary {
    batches: number;
    settlement: number;
    fees: number;
    transactions: number;
    unreconciled: number;
}

interface Filters {
    status?: string;
    terminal_id?: string;
    date_from?: string;
    date_to?: string;
}

interface Props extends PageProps {
    batches: Pagination;
    terminals: Terminal[];
    unmatchedBankTransactions: UnmatchedBankTransaction[];
    summary: Summary;
    filters: Filters;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'reconciled', label: 'Reconciled' },
    { value: 'discrepancy', label: 'Discrepancy' },
];

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'EFTPOS', href: '/finance/eftpos/terminals' },
    { title: 'Batches', href: '/finance/eftpos/batches' },
];

export default function EftposBatches({
    batches,
    terminals,
    unmatchedBankTransactions,
    summary,
    filters,
}: Props) {
    const [reconcileTarget, setReconcileTarget] = useState<Batch | null>(null);
    const [rangeOpen, setRangeOpen] = useState(false);
    const [range, setRange] = useState({
        from: filters.date_from ?? '',
        to: filters.date_to ?? '',
    });
    const ctxMenu = useEntityContextMenu<Batch>();

    const applyFilters = (next: Filters) => {
        const merged = { ...filters, ...next };
        router.get(
            '/finance/eftpos/batches',
            Object.fromEntries(
                Object.entries(merged).filter(([, value]) => value),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const clearFilters = () => {
        setRange({ from: '', to: '' });
        router.get('/finance/eftpos/batches', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.status ||
            filters.terminal_id ||
            filters.date_from ||
            filters.date_to,
    );

    const rangeLabel =
        filters.date_from || filters.date_to
            ? `${filters.date_from ? formatDate(filters.date_from) : 'Earliest'} – ${
                  filters.date_to ? formatDate(filters.date_to) : 'Today'
              }`
            : 'Any date';

    const actionsFor = (batch: Batch): MenuItem[] =>
        compactMenu([
            {
                label: 'Open batch',
                icon: Eye,
                onClick: () =>
                    router.visit(`/finance/eftpos/batches/${batch.id}`),
            },
            batch.status === 'closed'
                ? {
                      label: 'Reconcile batch',
                      icon: Scale,
                      onClick: () => setReconcileTarget(batch),
                  }
                : null,
            {
                label: 'Manage terminals',
                icon: Smartphone,
                onClick: () => router.visit('/finance/eftpos/terminals'),
            },
        ]);

    const columns: EntityTableColumn<Batch>[] = [
        {
            key: 'date',
            label: 'Batch date',
            width: '0.9fr',
            cell: (batch) => formatDate(batch.batch_date),
        },
        {
            key: 'transactions',
            label: 'Txns',
            width: '0.5fr',
            align: 'right',
            cell: (batch) => (
                <span className="tabular-nums">{batch.total_transactions}</span>
            ),
        },
        {
            key: 'amount',
            label: 'Taken',
            width: '0.8fr',
            align: 'right',
            cell: (batch) => (
                <span className="tabular-nums">
                    {formatMoney(batch.total_amount)}
                </span>
            ),
        },
        {
            key: 'refunds',
            label: 'Refunds',
            width: '0.8fr',
            align: 'right',
            cell: (batch) =>
                batch.total_refunds > 0 ? (
                    <span className="tabular-nums text-status-critical">
                        −{formatMoney(batch.total_refunds)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'fees',
            label: 'Fees',
            width: '0.7fr',
            align: 'right',
            cell: (batch) =>
                batch.fees > 0 ? (
                    <span className="tabular-nums text-muted-foreground">
                        {formatMoney(batch.fees)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'settlement',
            label: 'Settlement',
            width: '0.9fr',
            align: 'right',
            cell: (batch) => (
                <span className="font-medium tabular-nums">
                    {formatMoney(batch.settlement_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '1fr',
            cell: (batch) => (
                <span className="flex min-w-0 flex-col gap-0.5">
                    <StatusBadge status={batch.status} />
                    {batch.discrepancy_amount !== 0 ? (
                        <span className="truncate text-[11px] text-status-critical">
                            {formatMoney(batch.discrepancy_amount)} out
                        </span>
                    ) : null}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Layers}
            title="EFTPOS batches"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.unreconciled > 0 ? 'warning' : 'success'}
                >
                    {summary.unreconciled > 0
                        ? `${summary.unreconciled} to reconcile`
                        : 'All reconciled'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${summary.batches} batches · ${terminals.length} terminals`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Settlement"
                        tone="success"
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View the bank transactions these settlements land in"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.settlement)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Expected into the bank
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Fees"
                        href="/finance/eftpos/batches"
                        ariaLabel="View every batch"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.fees)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Provider fees deducted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Card transactions"
                        href="/finance/eftpos/terminals"
                        ariaLabel="View terminals"
                    >
                        <PageHeaderMeterBig>
                            {summary.transactions}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {summary.batches} batches
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={summary.unreconciled > 0 ? 'warning' : 'brand'}
                        onClick={() => applyFilters({ status: 'closed' })}
                        ariaLabel="Show batches still to reconcile"
                    >
                        <PageHeaderMeterBig>
                            {summary.unreconciled}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Batches not yet matched to the bank
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                status: value === ALL ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Terminal"
                        value={filters.terminal_id || ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any terminal' },
                            ...terminals.map((terminal) => ({
                                value: String(terminal.id),
                                label: terminal.name,
                            })),
                        ]}
                        onChange={(value) =>
                            applyFilters({
                                terminal_id: value === ALL ? '' : value,
                            })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.date_from || filters.date_to,
                                )}
                                aria-label="Filter by batch date"
                            >
                                {rangeLabel}
                            </PageHeaderFilterButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setRangeOpen(false);
                                    applyFilters({
                                        date_from: range.from,
                                        date_to: range.to,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="batches-from">From</Label>
                                    <Input
                                        id="batches-from"
                                        type="date"
                                        value={range.from}
                                        onChange={(event) =>
                                            setRange((current) => ({
                                                ...current,
                                                from: event.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="batches-to">To</Label>
                                    <Input
                                        id="batches-to"
                                        type="date"
                                        value={range.to}
                                        onChange={(event) =>
                                            setRange((current) => ({
                                                ...current,
                                                to: event.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setRange({ from: '', to: '' });
                                            setRangeOpen(false);
                                            applyFilters({
                                                date_from: '',
                                                date_to: '',
                                            });
                                        }}
                                    >
                                        Clear
                                    </Button>
                                    <Button type="submit" size="sm">
                                        Show these dates
                                    </Button>
                                </div>
                            </form>
                        </PopoverContent>
                    </Popover>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="EFTPOS batches" />

            <PageLayout hero={header} tabs={<FinanceTierTwoNav />}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Batches"
                        caption={`${batches.data.length} of ${batches.total} shown`}
                    />

                    {batches.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No batches match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={CreditCard}
                                itemName="EFTPOS batch"
                                title="No EFTPOS batches yet"
                                description="Batches appear here once a terminal closes off its card takings for the day."
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={batches.data}
                                rowKey={(batch) => batch.id}
                                identityLabel="Batch"
                                identity={(batch) => ({
                                    icon: Layers,
                                    name: batch.batch_number,
                                    subline: [
                                        batch.terminal_name,
                                        batch.terminal_id_code,
                                    ]
                                        .filter(Boolean)
                                        .join(' · '),
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                hrefFor={(batch) =>
                                    `/finance/eftpos/batches/${batch.id}`
                                }
                                onRowContextMenu={ctxMenu.open}
                                minWidth={1180}
                            />
                            <LaravelPagination
                                links={batches.links}
                                lastPage={batches.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Layers}
                    title={ctxMenu.ctx.record.batch_number}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <ReconcileBatchDialog
                open={!!reconcileTarget}
                onClose={() => setReconcileTarget(null)}
                batch={
                    reconcileTarget
                        ? {
                              id: reconcileTarget.id,
                              batch_number: reconcileTarget.batch_number,
                              settlement_amount:
                                  reconcileTarget.settlement_amount,
                          }
                        : null
                }
                transactions={unmatchedBankTransactions}
            />
        </AppLayout>
    );
}
