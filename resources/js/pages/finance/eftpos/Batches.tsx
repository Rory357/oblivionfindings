import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, Layers } from 'lucide-react';
import { useMemo } from 'react';

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

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'EFTPOS Batches', href: '/finance/eftpos/batches' },
];

export default function EftposBatches({
    batches,
    terminals,
    unmatchedBankTransactions,
    filters,
}: Props) {
    const handleReconcile = (batchId: number, bankTransactionId?: number) => {
        router.post(`/finance/eftpos/batches/${batchId}/reconcile`, {
            bank_transaction_id: bankTransactionId ?? null,
        });
    };

    const kpis = useMemo(() => {
        const data = batches.data;
        const totalSettlement = data.reduce(
            (sum, b) => sum + b.settlement_amount,
            0,
        );
        const totalFees = data.reduce((sum, b) => sum + b.fees, 0);
        const totalTxns = data.reduce(
            (sum, b) => sum + b.total_transactions,
            0,
        );
        const unreconciledCount = data.filter(
            (b) => b.status !== 'reconciled',
        ).length;
        return { totalSettlement, totalFees, totalTxns, unreconciledCount };
    }, [batches.data]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="EFTPOS Batches" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={Layers}
                        title="EFTPOS Batches"
                        description="Reconcile EFTPOS settlements with bank transactions."
                        stats={[
                            {
                                label: 'Settlement',
                                value: formatMoney(kpis.totalSettlement),
                            },
                            {
                                label: 'Fees',
                                value: formatMoney(kpis.totalFees),
                            },
                            { label: 'Transactions', value: kpis.totalTxns },
                            {
                                label: 'Unreconciled',
                                value: kpis.unreconciledCount,
                            },
                        ]}
                        actions={
                            <Button
                                asChild
                                size="sm"
                                variant="outline"
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/finance/eftpos/terminals">
                                    Manage Terminals
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 p-4">
                        <div className="w-40">
                            <Label>Status</Label>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(val) =>
                                    router.get(
                                        '/finance/eftpos/batches',
                                        {
                                            ...filters,
                                            status:
                                                val === 'all' ? undefined : val,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="closed">
                                        Closed
                                    </SelectItem>
                                    <SelectItem value="reconciled">
                                        Reconciled
                                    </SelectItem>
                                    <SelectItem value="discrepancy">
                                        Discrepancy
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="w-48">
                            <Label>Terminal</Label>
                            <Select
                                value={filters.terminal_id ?? 'all'}
                                onValueChange={(val) =>
                                    router.get(
                                        '/finance/eftpos/batches',
                                        {
                                            ...filters,
                                            terminal_id:
                                                val === 'all' ? undefined : val,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Terminals
                                    </SelectItem>
                                    {terminals.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
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
                                    router.get(
                                        '/finance/eftpos/batches',
                                        {
                                            ...filters,
                                            date_from:
                                                e.target.value || undefined,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            />
                        </div>

                        <div className="w-36">
                            <Label>To</Label>
                            <Input
                                type="date"
                                value={filters.date_to ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        '/finance/eftpos/batches',
                                        {
                                            ...filters,
                                            date_to:
                                                e.target.value || undefined,
                                        },
                                        { preserveState: true },
                                    )
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
                            <p className="text-lg font-medium text-muted-foreground">
                                No EFTPOS batches found.
                            </p>
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
                                        <TableHead className="text-right">
                                            Txns
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Refunds
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Fees
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Settlement
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {batches.data.map((batch) => {
                                        return (
                                            <TableRow key={batch.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/finance/eftpos/batches/${batch.id}`}
                                                        className="font-medium text-primary hover:underline"
                                                    >
                                                        {batch.batch_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {formatDate(
                                                        batch.batch_date,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {batch.terminal_name ?? '-'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {batch.total_transactions}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatMoney(
                                                        batch.total_amount,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-destructive">
                                                    {batch.total_refunds > 0
                                                        ? `-${formatMoney(batch.total_refunds)}`
                                                        : '-'}
                                                </TableCell>
                                                <TableCell className="text-right text-muted-foreground">
                                                    {batch.fees > 0
                                                        ? formatMoney(
                                                              batch.fees,
                                                          )
                                                        : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-medium">
                                                    {formatMoney(
                                                        batch.settlement_amount,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={batch.status}
                                                    />
                                                    {batch.discrepancy_amount !==
                                                        0 && (
                                                        <span className="ml-1 text-xs text-destructive">
                                                            (
                                                            {formatMoney(
                                                                batch.discrepancy_amount,
                                                            )}
                                                            )
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {batch.status ===
                                                        'closed' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                handleReconcile(
                                                                    batch.id,
                                                                )
                                                            }
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
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
