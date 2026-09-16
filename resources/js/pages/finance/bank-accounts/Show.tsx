import {
    BankAccountDialog,
    FinanceSectionRail,
    formatMoney,
    type AccountOption,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { StartReconciliationDialog } from '@/components/finance/start-reconciliation-dialog';
import {
    EntityChip,
    EntityTable,
    ListCaption,
    compactMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyList } from '@/components/ui/empty-state';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Banknote,
    Eye,
    FileText,
    Landmark,
    Pencil,
    Scale,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_number: string | null;
    account_type: string;
    gl_account_id: number | null;
    opening_balance: number;
    current_balance: number;
    is_primary: boolean;
    is_active: boolean;
    gl_account: { id: number; code: string; name: string } | null;
}

interface Transaction {
    id: number;
    transaction_date: string;
    amount: number;
    description: string;
    reference: string | null;
    source: string;
    status: string;
}

interface Reconciliation {
    id: number;
    statement_date: string;
    statement_balance: number;
    calculated_balance: number | null;
    status: string;
    completed_at: string | null;
}

interface BalanceHistoryEntry {
    date: string;
    amount: number;
}

interface Props {
    bankAccount: BankAccount;
    transactions: Transaction[];
    reconciliations: Reconciliation[];
    balanceHistory: BalanceHistoryEntry[];
    canManage: boolean;
    glAccounts: AccountOption[];
}

const ACCOUNT_TYPE_LABELS: Record<string, string> = {
    cheque: 'Cheque',
    savings: 'Savings',
    term_deposit: 'Term deposit',
    credit_card: 'Credit card',
};

export default function BankAccountShow({
    bankAccount,
    transactions,
    reconciliations,
    balanceHistory,
    canManage = false,
    glAccounts = [],
}: Props) {
    const [importOpen, setImportOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [reconcileOpen, setReconcileOpen] = useState(false);

    const importForm = useForm({
        bank_account_id: bankAccount.id,
        file: null as File | null,
    });

    const handleImport = (e: React.FormEvent) => {
        e.preventDefault();
        if (!importForm.data.file) return;

        const formData = new FormData();
        formData.append('bank_account_id', String(bankAccount.id));
        formData.append('file', importForm.data.file);

        router.post('/finance/bank-transactions/import', formData, {
            onSuccess: () => {
                setImportOpen(false);
                importForm.reset();
            },
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Banking', href: '/finance/banking' },
        { title: 'Bank accounts', href: '/finance/bank-accounts' },
        {
            title: bankAccount.name,
            href: `/finance/bank-accounts/${bankAccount.id}`,
        },
    ];

    const movement = bankAccount.current_balance - bankAccount.opening_balance;
    const unreconciled = transactions.filter(
        (txn) => txn.status !== 'reconciled',
    ).length;
    const openReconciliation = reconciliations.find(
        (recon) => recon.status !== 'completed',
    );

    const transactionColumns: EntityTableColumn<Transaction>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '0.9fr',
            cell: (txn) => txn.transaction_date,
        },
        {
            key: 'reference',
            label: 'Reference',
            width: '0.9fr',
            cell: (txn) =>
                txn.reference ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'source',
            label: 'Source',
            width: '0.7fr',
            cell: (txn) => (
                <EntityChip outline>
                    <span className="capitalize">{txn.source}</span>
                </EntityChip>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '0.9fr',
            align: 'right',
            cell: (txn) => (
                <span
                    className={`font-medium tabular-nums ${
                        txn.amount >= 0
                            ? 'text-status-success'
                            : 'text-status-critical'
                    }`}
                >
                    {formatMoney(txn.amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (txn) => <StatusBadge status={txn.status} />,
        },
    ];

    const reconciliationColumns: EntityTableColumn<Reconciliation>[] = [
        {
            key: 'statement_balance',
            label: 'Statement balance',
            width: '1fr',
            align: 'right',
            cell: (recon) => (
                <span className="tabular-nums">
                    {formatMoney(recon.statement_balance)}
                </span>
            ),
        },
        {
            key: 'calculated_balance',
            label: 'Calculated balance',
            width: '1fr',
            align: 'right',
            cell: (recon) =>
                recon.calculated_balance !== null ? (
                    <span className="tabular-nums">
                        {formatMoney(recon.calculated_balance)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (recon) => <StatusBadge status={recon.status} />,
        },
        {
            key: 'completed_at',
            label: 'Completed',
            width: '0.9fr',
            cell: (recon) =>
                recon.completed_at ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const reconciliationActions = (recon: Reconciliation): MenuItem[] =>
        compactMenu([
            {
                label:
                    recon.status === 'completed'
                        ? 'Open reconciliation'
                        : 'Continue reconciliation',
                icon: Eye,
                onClick: () =>
                    router.visit(`/finance/bank-reconciliation/${recon.id}`),
            },
        ]);

    const header = (
        <PageHeader
            variant="profile"
            icon={Landmark}
            backHref="/finance/bank-accounts"
            title={bankAccount.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={bankAccount.is_active ? 'success' : 'neutral'}
                >
                    {bankAccount.is_active ? 'Active' : 'Inactive'}
                </PageHeaderStatusChip>
            }
            subline={[
                bankAccount.bank_name,
                ACCOUNT_TYPE_LABELS[bankAccount.account_type] ??
                    bankAccount.account_type,
                bankAccount.account_number ?? 'No account number',
                bankAccount.gl_account
                    ? `GL ${bankAccount.gl_account.code} ${bankAccount.gl_account.name}`
                    : 'No GL account linked',
                bankAccount.is_primary ? 'Primary account' : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canManage && (
                        <>
                            <PageHeaderGlassButton
                                icon={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={Upload}
                                onClick={() => setImportOpen(true)}
                            >
                                Import transactions
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={ArrowRight}
                                onClick={() => setReconcileOpen(true)}
                            >
                                Start reconciliation
                            </PageHeaderPrimaryButton>
                        </>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Current balance"
                        tone={
                            bankAccount.current_balance >= 0
                                ? 'success'
                                : 'critical'
                        }
                        href={`/finance/bank-transactions?bank_account_id=${bankAccount.id}`}
                        ariaLabel="View this account's bank transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(bankAccount.current_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {transactions.length} recent transactions
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Opening balance"
                        href={`/finance/bank-transactions?bank_account_id=${bankAccount.id}`}
                        ariaLabel="View the transactions behind the movement"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(bankAccount.opening_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {movement >= 0 ? 'Up ' : 'Down '}
                            {formatMoney(Math.abs(movement))} since opening
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={unreconciled > 0 ? 'warning' : 'brand'}
                        href={`/finance/bank-transactions?bank_account_id=${bankAccount.id}&status=unreconciled`}
                        ariaLabel="View unreconciled transactions on this account"
                    >
                        <PageHeaderMeterBig>{unreconciled}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Of the {transactions.length} shown below
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Reconciliations"
                        tone={openReconciliation ? 'warning' : 'brand'}
                        href={`/finance/bank-reconciliation?bank_account_id=${bankAccount.id}`}
                        ariaLabel="View reconciliations for this account"
                    >
                        <PageHeaderMeterBig>
                            {reconciliations.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {openReconciliation
                                ? 'One still in progress'
                                : 'None in progress'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={bankAccount.name} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {balanceHistory.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Transaction amounts over time
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="h-[280px]">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <LineChart data={balanceHistory}>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="date"
                                                tick={{ fontSize: 12 }}
                                                className="text-muted-foreground"
                                            />
                                            <YAxis
                                                tick={{ fontSize: 12 }}
                                                className="text-muted-foreground"
                                                tickFormatter={(v) =>
                                                    formatMoney(v)
                                                }
                                            />
                                            <Tooltip
                                                formatter={(value?: number) => [
                                                    formatMoney(value ?? 0),
                                                    'Amount',
                                                ]}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="amount"
                                                stroke={chartColor(0)}
                                                strokeWidth={2}
                                                dot={{
                                                    fill: chartColor(0),
                                                    r: 3,
                                                }}
                                                activeDot={{ r: 5 }}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <ListCaption
                        title="Recent transactions"
                        caption={`${transactions.length} shown`}
                    />
                    {transactions.length === 0 ? (
                        <EmptyList
                            icon={FileText}
                            itemName="transaction"
                            title="No transactions"
                            description="Import a CSV statement or add transactions manually."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setImportOpen(true)}
                                    >
                                        Import transactions
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={transactions}
                            rowKey={(txn) => txn.id}
                            identityLabel="Transaction"
                            identity={(txn) => ({
                                icon: Banknote,
                                name: txn.description,
                                subline: txn.transaction_date,
                            })}
                            columns={transactionColumns}
                            actionsFor={() => []}
                            minWidth={980}
                        />
                    )}

                    <ListCaption
                        title="Past reconciliations"
                        caption={`${reconciliations.length} shown`}
                    />
                    {reconciliations.length === 0 ? (
                        <EmptyList
                            icon={Scale}
                            itemName="reconciliation"
                            title="No reconciliations yet"
                            description="Reconcile a statement to confirm this account against the ledger."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setReconcileOpen(true)}
                                    >
                                        Start reconciliation
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={reconciliations}
                            rowKey={(recon) => recon.id}
                            identityLabel="Statement"
                            identity={(recon) => ({
                                icon: Scale,
                                name: recon.statement_date,
                                subline: bankAccount.name,
                            })}
                            columns={reconciliationColumns}
                            actionsFor={reconciliationActions}
                            hrefFor={(recon) =>
                                `/finance/bank-reconciliation/${recon.id}`
                            }
                            onOpen={(recon) =>
                                router.visit(
                                    `/finance/bank-reconciliation/${recon.id}`,
                                )
                            }
                            minWidth={980}
                        />
                    )}
                </div>
            </PageLayout>

            <Dialog
                open={importOpen}
                onOpenChange={(next) => !next && setImportOpen(false)}
            >
                <DialogContent>
                    <form onSubmit={handleImport}>
                        <DialogHeader>
                            <DialogTitle>Import bank transactions</DialogTitle>
                            <DialogDescription>
                                Upload a CSV statement for {bankAccount.name}.
                                Use the column order Date, Amount, Description,
                                Reference, with the first row as headers.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="mt-3 space-y-2">
                            <Label id="import-file-label">Statement file</Label>
                            {importForm.data.file ? (
                                <StagedFileCard
                                    file={importForm.data.file}
                                    onRemove={() =>
                                        importForm.setData('file', null)
                                    }
                                />
                            ) : (
                                <FileDropzone
                                    aria-labelledby="import-file-label"
                                    accept=".csv,.txt"
                                    multiple={false}
                                    title="Drag & drop the statement here"
                                    hint="CSV or text export from your bank"
                                    onFiles={(files) =>
                                        importForm.setData(
                                            'file',
                                            files[0] ?? null,
                                        )
                                    }
                                />
                            )}
                        </div>

                        <DialogFooter className="mt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setImportOpen(false)}
                                disabled={importForm.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
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

            {canManage && (
                <StartReconciliationDialog
                    open={reconcileOpen}
                    onClose={() => setReconcileOpen(false)}
                    bankAccountId={bankAccount.id}
                    bankAccounts={[
                        {
                            id: bankAccount.id,
                            name: bankAccount.name,
                            current_balance: bankAccount.current_balance,
                        },
                    ]}
                />
            )}

            {/* Mounted only while open so each edit starts from fresh props. */}
            {canManage && editOpen && (
                <BankAccountDialog
                    open
                    onClose={() => setEditOpen(false)}
                    glAccounts={glAccounts}
                    bankAccount={{
                        id: bankAccount.id,
                        name: bankAccount.name,
                        bank_name: bankAccount.bank_name,
                        account_number: bankAccount.account_number,
                        account_type: bankAccount.account_type,
                        gl_account_id:
                            bankAccount.gl_account_id ??
                            bankAccount.gl_account?.id ??
                            null,
                        is_primary: bankAccount.is_primary,
                        is_active: bankAccount.is_active,
                    }}
                />
            )}
        </AppLayout>
    );
}
