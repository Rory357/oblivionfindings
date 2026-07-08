import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/components/finance/money';
import { CheckCircle2, AlertTriangle } from 'lucide-react';

interface BatchData {
    id: number;
    batch_number: string;
    batch_date: string;
    settlement_date: string | null;
    terminal_name: string | null;
    terminal_id_code: string | null;
    provider: string | null;
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
    discrepancy_notes: string | null;
    bank_transaction: {
        id: number;
        amount: number;
        transaction_date: string;
        description: string | null;
    } | null;
    created_by_name: string | null;
}

interface Transaction {
    id: number;
    transaction_reference: string;
    transaction_date: string;
    card_type: string;
    transaction_type: string;
    amount: number;
    fee_amount: number;
    auth_code: string | null;
    card_last_four: string | null;
    status: string;
}

interface Props extends PageProps {
    batch: BatchData;
    transactions: Transaction[];
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const formatDateTime = (date: string) =>
    new Date(date).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const cardTypeLabels: Record<string, string> = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    eftpos: 'EFTPOS',
    amex: 'Amex',
    other: 'Other',
};

const txnTypeConfig: Record<string, { label: string; className: string }> = {
    purchase: { label: 'Purchase', className: 'bg-status-success-bg text-status-success' },
    refund: { label: 'Refund', className: 'bg-status-critical-bg text-status-critical' },
    cash_out: { label: 'Cash Out', className: 'bg-status-info-bg text-status-info' },
};

const statusBadge: Record<string, { label: string; className: string }> = {
    open: { label: 'Open', className: 'border-status-info/30 text-status-info' },
    closed: { label: 'Closed', className: 'border-status-warning/30 text-status-warning' },
    reconciled: { label: 'Reconciled', className: 'border-status-success/30 text-status-success' },
    discrepancy: { label: 'Discrepancy', className: 'border-status-critical/30 text-status-critical' },
};

export default function EftposBatchDetail({ batch, transactions }: Props) {
    const badge = statusBadge[batch.status] ?? statusBadge.open;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'EFTPOS Batches', href: '/finance/eftpos/batches' },
        { title: `Batch ${batch.batch_number}`, href: `/finance/eftpos/batches/${batch.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`EFTPOS Batch ${batch.batch_number}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/eftpos/batches"
                        title={`Batch ${batch.batch_number}`}
                        description={batch.terminal_name ? `Terminal: ${batch.terminal_name}` : undefined}
                        actions={
                            <Badge variant="outline" className={badge.className}>
                                {badge.label}
                            </Badge>
                        }
                    />
                }
            >
                {/* Batch Summary */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Amount</p>
                            <p className="text-xl font-bold">{formatMoney(batch.total_amount)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Refunds</p>
                            <p className="text-xl font-bold text-destructive">{formatMoney(batch.total_refunds)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Net Amount</p>
                            <p className="text-xl font-bold">{formatMoney(batch.net_amount)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Fees</p>
                            <p className="text-xl font-bold text-muted-foreground">{formatMoney(batch.fees)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Settlement</p>
                            <p className="text-xl font-bold text-status-success">{formatMoney(batch.settlement_amount)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Transactions</p>
                            <p className="text-xl font-bold">{batch.total_transactions}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Batch Details */}
                <Card>
                    <CardHeader>
                        <CardTitle>Batch Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">Batch Date</dt>
                                <dd className="font-medium">{formatDate(batch.batch_date)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Terminal</dt>
                                <dd className="font-medium">{batch.terminal_name ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Terminal ID</dt>
                                <dd className="font-mono">{batch.terminal_id_code ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Provider</dt>
                                <dd className="font-medium capitalize">{batch.provider ?? '-'}</dd>
                            </div>
                            {batch.reconciled_at && (
                                <>
                                    <div>
                                        <dt className="text-muted-foreground">Reconciled At</dt>
                                        <dd className="font-medium">{formatDateTime(batch.reconciled_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Reconciled By</dt>
                                        <dd className="font-medium">{batch.reconciled_by_name ?? '-'}</dd>
                                    </div>
                                </>
                            )}
                            {batch.discrepancy_amount !== 0 && (
                                <div className="col-span-2">
                                    <dt className="text-muted-foreground">Discrepancy</dt>
                                    <dd className="font-medium text-destructive">
                                        {formatMoney(batch.discrepancy_amount)}
                                        {batch.discrepancy_notes && (
                                            <span className="ml-2 text-muted-foreground">- {batch.discrepancy_notes}</span>
                                        )}
                                    </dd>
                                </div>
                            )}
                            {batch.bank_transaction && (
                                <div className="col-span-2">
                                    <dt className="text-muted-foreground">Matched Bank Transaction</dt>
                                    <dd className="font-medium">
                                        {formatMoney(batch.bank_transaction.amount)} on {formatDate(batch.bank_transaction.transaction_date)}
                                        {batch.bank_transaction.description && (
                                            <span className="ml-2 text-muted-foreground">({batch.bank_transaction.description})</span>
                                        )}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {/* Transactions Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Transactions ({transactions.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {transactions.length === 0 ? (
                            <p className="p-6 text-muted-foreground">No transactions in this batch.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Date/Time</TableHead>
                                        <TableHead>Card Type</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Fee</TableHead>
                                        <TableHead>Auth Code</TableHead>
                                        <TableHead>Card</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((txn) => {
                                        const typeConf = txnTypeConfig[txn.transaction_type] ?? {
                                            label: txn.transaction_type,
                                            className: 'bg-muted text-foreground',
                                        };
                                        return (
                                            <TableRow key={txn.id}>
                                                <TableCell className="font-mono text-sm">{txn.transaction_reference}</TableCell>
                                                <TableCell className="text-sm">{formatDateTime(txn.transaction_date)}</TableCell>
                                                <TableCell>{cardTypeLabels[txn.card_type] ?? txn.card_type}</TableCell>
                                                <TableCell>
                                                    <Badge className={typeConf.className} variant="outline">
                                                        {typeConf.label}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell
                                                    className={`text-right font-medium ${txn.transaction_type === 'refund' ? 'text-destructive' : ''}`}
                                                >
                                                    {txn.transaction_type === 'refund' ? '-' : ''}
                                                    {formatMoney(txn.amount)}
                                                </TableCell>
                                                <TableCell className="text-right text-sm text-muted-foreground">
                                                    {txn.fee_amount > 0 ? formatMoney(txn.fee_amount) : '-'}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">{txn.auth_code ?? '-'}</TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {txn.card_last_four ? `****${txn.card_last_four}` : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {txn.status === 'approved' ? (
                                                        <CheckCircle2 className="h-4 w-4 text-status-success" />
                                                    ) : txn.status === 'declined' ? (
                                                        <AlertTriangle className="h-4 w-4 text-destructive" />
                                                    ) : (
                                                        <span className="text-muted-foreground">{txn.status}</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
