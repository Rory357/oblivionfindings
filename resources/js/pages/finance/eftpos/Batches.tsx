import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CheckCircle2, AlertTriangle, Clock, CreditCard } from 'lucide-react';

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

interface BankTransaction {
    id: number;
    transaction_date: string;
    amount: number;
    description: string | null;
    reference: string | null;
}

interface Pagination {
    data: Batch[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props extends PageProps {
    batches: Pagination;
    terminals: Terminal[];
    unmatchedBankTransactions: BankTransaction[];
    filters: {
        status?: string;
        terminal_id?: string;
        date_from?: string;
        date_to?: string;
    };
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; icon: typeof CheckCircle2; className: string }> = {
    open: { label: 'Open', icon: Clock, className: 'border-blue-300 text-blue-600' },
    closed: { label: 'Closed', icon: Clock, className: 'border-amber-300 text-amber-600' },
    reconciled: { label: 'Reconciled', icon: CheckCircle2, className: 'border-green-300 text-green-600' },
    discrepancy: { label: 'Discrepancy', icon: AlertTriangle, className: 'border-red-300 text-red-600' },
};

export default function EftposBatches({ batches, terminals, unmatchedBankTransactions, filters }: Props) {
    const handleReconcile = (batchId: number, bankTransactionId?: number) => {
        router.post(`/finance/eftpos/batches/${batchId}/reconcile`, {
            bank_transaction_id: bankTransactionId ?? null,
        });
    };

    return (
        <AppLayout>
            <Head title="EFTPOS Batches" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">EFTPOS Batches</h1>
                    <Button asChild variant="outline">
                        <Link href="/finance/eftpos/terminals">Manage Terminals</Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 p-4">
                        <div className="w-40">
                            <Label>Status</Label>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(val) =>
                                    router.get('/finance/eftpos/batches', { ...filters, status: val === 'all' ? undefined : val }, { preserveState: true })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                    <SelectItem value="reconciled">Reconciled</SelectItem>
                                    <SelectItem value="discrepancy">Discrepancy</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="w-48">
                            <Label>Terminal</Label>
                            <Select
                                value={filters.terminal_id ?? 'all'}
                                onValueChange={(val) =>
                                    router.get('/finance/eftpos/batches', { ...filters, terminal_id: val === 'all' ? undefined : val }, { preserveState: true })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Terminals</SelectItem>
                                    {terminals.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="w-36">
                            <Label>From</Label>
                            <Input
                                type="date"
                                value={filters.date_from ?? ''}
                                onChange={(e) =>
                                    router.get('/finance/eftpos/batches', { ...filters, date_from: e.target.value || undefined }, { preserveState: true })
                                }
                            />
                        </div>

                        <div className="w-36">
                            <Label>To</Label>
                            <Input
                                type="date"
                                value={filters.date_to ?? ''}
                                onChange={(e) =>
                                    router.get('/finance/eftpos/batches', { ...filters, date_to: e.target.value || undefined }, { preserveState: true })
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Batches Table */}
                {batches.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <CreditCard className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No EFTPOS batches found.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Batch</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Terminal</TableHead>
                                        <TableHead className="text-right">Txns</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Refunds</TableHead>
                                        <TableHead className="text-right">Fees</TableHead>
                                        <TableHead className="text-right">Settlement</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {batches.data.map((batch) => {
                                        const config = statusConfig[batch.status] ?? statusConfig.open;
                                        const StatusIcon = config.icon;
                                        return (
                                            <TableRow key={batch.id}>
                                                <TableCell>
                                                    <Link href={`/finance/eftpos/batches/${batch.id}`} className="font-medium text-blue-600 hover:underline">
                                                        {batch.batch_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{formatDate(batch.batch_date)}</TableCell>
                                                <TableCell className="text-sm">{batch.terminal_name ?? '-'}</TableCell>
                                                <TableCell className="text-right">{batch.total_transactions}</TableCell>
                                                <TableCell className="text-right">{formatCurrency(batch.total_amount)}</TableCell>
                                                <TableCell className="text-right text-red-600">
                                                    {batch.total_refunds > 0 ? `-${formatCurrency(batch.total_refunds)}` : '-'}
                                                </TableCell>
                                                <TableCell className="text-right text-muted-foreground">
                                                    {batch.fees > 0 ? formatCurrency(batch.fees) : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-medium">{formatCurrency(batch.settlement_amount)}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={config.className}>
                                                        <StatusIcon className="mr-1 h-3 w-3" />
                                                        {config.label}
                                                    </Badge>
                                                    {batch.discrepancy_amount !== 0 && (
                                                        <span className="ml-1 text-xs text-red-600">
                                                            ({formatCurrency(batch.discrepancy_amount)})
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {batch.status === 'closed' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => handleReconcile(batch.id)}
                                                        >
                                                            Reconcile
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {/* Pagination */}
                {batches.last_page > 1 && (
                    <div className="flex justify-center gap-1">
                        {batches.links.map((link, idx) => (
                            <Button
                                key={idx}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
