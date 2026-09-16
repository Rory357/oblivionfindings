import { FinanceSectionRail, formatMoney } from '@/components/finance';
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
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Banknote,
    CalendarRange,
    Download,
    Eye,
    Landmark,
    Plus,
    Sparkles,
    Upload,
} from 'lucide-react';
import { ChangeEvent, FormEvent, useState } from 'react';

type BankAccount = {
    id: number;
    name: string;
};

type Transaction = {
    id: number;
    transaction_date: string | null;
    amount: number;
    description: string;
    reference: string | null;
    payee: string | null;
    source: string | null;
    status: string;
    is_from_feed: boolean;
    bank_account: BankAccount | null;
};

type PaginatedTransactions = {
    data: Transaction[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

type Filters = {
    bank_account_id: string;
    status: string;
    start_date: string;
    end_date: string;
};

type Summary = {
    filtered_count: number;
    filtered_value: number;
    unreconciled_count: number;
    unreconciled_value: number;
    bank_accounts: number;
};

type Props = {
    transactions: PaginatedTransactions;
    bankAccounts: BankAccount[];
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    latestStatementImport: {
        status: string;
        imported_count: number;
        skipped_count: number;
        completed_at: string | null;
        failed_at: string | null;
        recovery_message: string | null;
    } | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Transactions', href: '/finance/bank-transactions' },
];

const ALL = '__ALL__';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'unreconciled', label: 'Unreconciled' },
    { value: 'matched', label: 'Matched' },
    { value: 'reconciled', label: 'Reconciled' },
];

const formatDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

export default function BankTransactionsIndex({
    transactions,
    bankAccounts,
    filters,
    summary,
    canManage = false,
    latestStatementImport,
}: Props) {
    const [showManualDialog, setShowManualDialog] = useState(false);
    const [showImportDialog, setShowImportDialog] = useState(false);
    const [rangeOpen, setRangeOpen] = useState(false);
    const [range, setRange] = useState({
        from: filters.start_date ?? '',
        to: filters.end_date ?? '',
    });
    const ctxMenu = useEntityContextMenu<Transaction>();

    const manualForm = useForm({
        bank_account_id: filters.bank_account_id || '',
        transaction_date: new Date().toISOString().split('T')[0],
        amount: '',
        description: '',
        reference: '',
        payee: '',
    });

    const importForm = useForm<{
        bank_account_id: string;
        file: File | null;
    }>({
        bank_account_id: filters.bank_account_id || '',
        file: null,
    });

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            '/finance/bank-transactions',
            Object.fromEntries(
                Object.entries(merged).filter(([, value]) => value),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const clearFilters = () => {
        setRange({ from: '', to: '' });
        router.get('/finance/bank-transactions', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.bank_account_id ||
            filters.status ||
            filters.start_date ||
            filters.end_date,
    );

    const handleManualSubmit = (event: FormEvent) => {
        event.preventDefault();
        manualForm.post('/finance/bank-transactions', {
            preserveScroll: true,
            onSuccess: () => {
                setShowManualDialog(false);
                manualForm.reset('amount', 'description', 'reference', 'payee');
            },
        });
    };

    const handleImportSubmit = (event: FormEvent) => {
        event.preventDefault();
        importForm.post('/finance/bank-transactions/import', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowImportDialog(false);
                importForm.reset('file');
            },
        });
    };

    const handleFileChange = (event: ChangeEvent<HTMLInputElement>) => {
        importForm.setData('file', event.target.files?.[0] ?? null);
    };

    const exportQuery = new URLSearchParams(
        Object.entries({
            bank_account_id: filters.bank_account_id,
            status: filters.status,
            start_date: filters.start_date,
            end_date: filters.end_date,
        }).filter(([, value]) => value),
    ).toString();

    const actionsFor = (transaction: Transaction): MenuItem[] =>
        compactMenu([
            transaction.bank_account
                ? {
                      label: 'Open bank account',
                      icon: Landmark,
                      onClick: () =>
                          router.visit(
                              `/finance/bank-accounts/${transaction.bank_account!.id}`,
                          ),
                  }
                : null,
            canManage && transaction.status === 'unreconciled'
                ? {
                      label: 'Find matches',
                      icon: Sparkles,
                      onClick: () =>
                          router.post(
                              `/finance/payment-matching/suggest/${transaction.id}`,
                              {},
                              { preserveScroll: true },
                          ),
                  }
                : null,
            transaction.status === 'unreconciled'
                ? {
                      label: 'Open payment matching',
                      icon: Eye,
                      onClick: () => router.visit('/finance/payment-matching'),
                  }
                : null,
        ]);

    const rangeLabel =
        filters.start_date || filters.end_date
            ? `${filters.start_date ? formatDate(filters.start_date) : 'Earliest'} – ${
                  filters.end_date ? formatDate(filters.end_date) : 'Today'
              }`
            : 'Any date';

    const columns: EntityTableColumn<Transaction>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '0.9fr',
            cell: (transaction) => formatDate(transaction.transaction_date),
        },
        {
            key: 'account',
            label: 'Bank account',
            width: '1fr',
            cell: (transaction) =>
                transaction.bank_account?.name ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'reference',
            label: 'Reference',
            width: '0.9fr',
            cell: (transaction) =>
                transaction.reference ??
                transaction.source ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (transaction) => <StatusBadge status={transaction.status} />,
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '1fr',
            align: 'right',
            cell: (transaction) => (
                <span
                    className={`inline-flex items-center gap-1.5 font-medium tabular-nums ${
                        transaction.amount >= 0
                            ? 'text-status-success'
                            : 'text-status-critical'
                    }`}
                >
                    {transaction.amount >= 0 ? (
                        <ArrowDownToLine className="h-3.5 w-3.5" />
                    ) : (
                        <ArrowUpFromLine className="h-3.5 w-3.5" />
                    )}
                    {formatMoney(transaction.amount)}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Banknote}
            title="Bank transactions"
            subline={`Banking · ${summary.bank_accounts} accounts · ${summary.unreconciled_count} still to reconcile`}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = `/finance/bank-transactions/export${
                                exportQuery ? `?${exportQuery}` : ''
                            }`;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <>
                            <PageHeaderGlassButton
                                icon={Upload}
                                onClick={() => setShowImportDialog(true)}
                            >
                                Import CSV
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => setShowManualDialog(true)}
                            >
                                New transaction
                            </PageHeaderPrimaryButton>
                        </>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="In this view"
                        href="/finance/bank-transactions"
                        ariaLabel="View every bank transaction"
                    >
                        <PageHeaderMeterBig>
                            {summary.filtered_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {hasFilters
                                ? 'Transactions matching the filters'
                                : 'Transactions on record'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Net value"
                        tone={
                            summary.filtered_value >= 0 ? 'success' : 'critical'
                        }
                        href="/finance/cash-position"
                        ariaLabel="View the cash position"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.filtered_value)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Money in less money out
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={
                            summary.unreconciled_count > 0 ? 'warning' : 'brand'
                        }
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View unreconciled transactions"
                    >
                        <PageHeaderMeterBig>
                            {summary.unreconciled_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(summary.unreconciled_value)} still to
                            match
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bank accounts"
                        href="/finance/bank-accounts"
                        ariaLabel="View bank accounts"
                    >
                        <PageHeaderMeterBig>
                            {summary.bank_accounts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Feeding this register
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Bank account"
                        value={filters.bank_account_id || ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any bank account' },
                            ...bankAccounts.map((account) => ({
                                value: String(account.id),
                                label: account.name,
                            })),
                        ]}
                        onChange={(value) =>
                            applyFilters({
                                bank_account_id: value === ALL ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            applyFilters({ status: value === ALL ? '' : value })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.start_date || filters.end_date,
                                )}
                                aria-label="Filter by transaction date"
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
                                        start_date: range.from,
                                        end_date: range.to,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="transactions-from">
                                        From
                                    </Label>
                                    <Input
                                        id="transactions-from"
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
                                    <Label htmlFor="transactions-to">To</Label>
                                    <Input
                                        id="transactions-to"
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
                                                start_date: '',
                                                end_date: '',
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
            <Head title="Bank transactions" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {latestStatementImport?.status === 'failed' && (
                        <Card className="border-status-warning/30 bg-status-warning-bg">
                            <CardContent className="py-4 text-sm text-status-warning">
                                {latestStatementImport.recovery_message ??
                                    'The latest statement import failed and no rows were applied.'}{' '}
                                Review the account and file, then retry the
                                import.
                            </CardContent>
                        </Card>
                    )}

                    <ListCaption
                        title="Transactions"
                        caption={`${transactions.data.length} of ${transactions.total} shown`}
                    />

                    {transactions.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No transactions match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Banknote}
                                itemName="transaction"
                                title="No transactions yet"
                                description="Import a bank statement or record a manual transaction to get started."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setShowImportDialog(true)
                                            }
                                        >
                                            Import CSV
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={transactions.data}
                                rowKey={(transaction) => transaction.id}
                                identityLabel="Transaction"
                                identity={(transaction) => ({
                                    icon: Banknote,
                                    name: transaction.description,
                                    subline:
                                        transaction.payee ??
                                        (transaction.is_from_feed
                                            ? 'Imported from a bank feed'
                                            : 'Manual transaction'),
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                onRowContextMenu={ctxMenu.open}
                                minWidth={1080}
                            />
                            <LaravelPagination
                                links={transactions.links}
                                lastPage={transactions.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Banknote}
                    title={ctxMenu.ctx.record.description}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <Dialog open={showImportDialog} onOpenChange={setShowImportDialog}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={handleImportSubmit}>
                        <DialogHeader>
                            <DialogTitle>Import bank transactions</DialogTitle>
                            <DialogDescription>
                                Upload a CSV or TXT export from your bank to add
                                transactions in bulk.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label>Bank account</Label>
                                <Select
                                    value={
                                        importForm.data.bank_account_id || ALL
                                    }
                                    onValueChange={(value) =>
                                        importForm.setData(
                                            'bank_account_id',
                                            value === ALL ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Select account
                                        </SelectItem>
                                        {bankAccounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {importForm.errors.bank_account_id && (
                                    <p className="text-sm text-status-critical">
                                        {importForm.errors.bank_account_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="import-file">
                                    Transaction file
                                </Label>
                                <Input
                                    id="import-file"
                                    type="file"
                                    accept=".csv,.txt"
                                    onChange={handleFileChange}
                                />
                                {importForm.errors.file && (
                                    <p className="text-sm text-status-critical">
                                        {importForm.errors.file}
                                    </p>
                                )}
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setShowImportDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    !importForm.data.bank_account_id ||
                                    !importForm.data.file ||
                                    importForm.processing
                                }
                            >
                                {importForm.processing
                                    ? 'Importing…'
                                    : 'Import transactions'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={showManualDialog} onOpenChange={setShowManualDialog}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={handleManualSubmit}>
                        <DialogHeader>
                            <DialogTitle>Add a bank transaction</DialogTitle>
                            <DialogDescription>
                                Record a manual transaction when it did not
                                arrive through a bank feed.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Bank account</Label>
                                <Select
                                    value={
                                        manualForm.data.bank_account_id || ALL
                                    }
                                    onValueChange={(value) =>
                                        manualForm.setData(
                                            'bank_account_id',
                                            value === ALL ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Select account
                                        </SelectItem>
                                        {bankAccounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {manualForm.errors.bank_account_id && (
                                    <p className="text-sm text-status-critical">
                                        {manualForm.errors.bank_account_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="transaction_date">Date</Label>
                                <Input
                                    id="transaction_date"
                                    type="date"
                                    value={manualForm.data.transaction_date}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'transaction_date',
                                            event.target.value,
                                        )
                                    }
                                />
                                {manualForm.errors.transaction_date && (
                                    <p className="text-sm text-status-critical">
                                        {manualForm.errors.transaction_date}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="amount">Amount</Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    step="0.01"
                                    value={manualForm.data.amount}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0.00"
                                />
                                {manualForm.errors.amount && (
                                    <p className="text-sm text-status-critical">
                                        {manualForm.errors.amount}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="payee">Payee</Label>
                                <Input
                                    id="payee"
                                    value={manualForm.data.payee}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'payee',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Optional payee"
                                />
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    value={manualForm.data.description}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Describe the transaction"
                                />
                                {manualForm.errors.description && (
                                    <p className="text-sm text-status-critical">
                                        {manualForm.errors.description}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="reference">Reference</Label>
                                <Input
                                    id="reference"
                                    value={manualForm.data.reference}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Optional statement or transfer reference"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setShowManualDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={manualForm.processing}
                            >
                                {manualForm.processing
                                    ? 'Saving…'
                                    : 'Save transaction'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
