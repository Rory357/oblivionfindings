import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, FileCheck, CheckCircle, Clock, ListChecks } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { useCallback, useMemo } from 'react';

const formatCurrency = (amount: number) => new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

interface Reconciliation {
    id: number;
    statement_date: string;
    statement_balance: string;
    calculated_balance: string | null;
    status: string;
    completed_at: string | null;
    bank_account: { id: number; name: string };
    completed_by: { id: number; name: string } | null;
}

interface PaginatedReconciliations {
    data: Reconciliation[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface BankAccount {
    id: number;
    name: string;
}

interface Filters {
    bank_account_id: string;
    status: string;
}

interface Props {
    reconciliations: PaginatedReconciliations;
    bankAccounts: BankAccount[];
    filters: Filters;
}

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const statusBadge = (status: string) => {
    switch (status) {
        case 'completed':
            return <Badge className="bg-status-success-bg text-status-success border-status-success/30">Completed</Badge>;
        case 'in_progress':
            return <Badge className="bg-status-info-bg text-status-info border-status-info/30">In Progress</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
};

export default function ReconciliationIndex({ reconciliations, bankAccounts, filters }: Props) {
    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/bank-reconciliation',
                { ...filters, ...newFilters, page: 1 },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Reconciliation', href: '/finance/bank-reconciliation' },
    ];

    const totalCount = reconciliations.total;
    const completedCount = useMemo(() => reconciliations.data.filter((r) => r.status === 'completed').length, [reconciliations.data]);
    const inProgressCount = useMemo(() => reconciliations.data.filter((r) => r.status === 'in_progress').length, [reconciliations.data]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Reconciliation" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Bank Reconciliation</h1>
                        <p className="text-muted-foreground mt-1">
                            Reconcile bank statements against your ledger
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={'/finance/bank-reconciliation/create'}>
                            <Plus className="w-4 h-4 mr-2" />
                            New Reconciliation
                        </Link>
                    </Button>
                </div>

                {/* KPI Cards */}
                {totalCount > 0 && (
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <Card>
                            <CardContent className="pt-6">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-lg bg-status-info p-2">
                                        <ListChecks className="h-5 w-5 text-status-info" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Total Reconciliations</p>
                                        <p className="text-2xl font-semibold">{totalCount}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="pt-6">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-lg bg-status-success p-2">
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Completed (this page)</p>
                                        <p className="text-2xl font-semibold">{completedCount}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="pt-6">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-lg bg-status-warning p-2">
                                        <Clock className="h-5 w-5 text-status-warning" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">In Progress (this page)</p>
                                        <p className="text-2xl font-semibold">{inProgressCount}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="flex flex-col sm:flex-row gap-4">
                            <Select
                                value={filters.bank_account_id || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ bank_account_id: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[220px]">
                                    <SelectValue placeholder="All Bank Accounts" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Bank Accounts</SelectItem>
                                    {bankAccounts.map((account) => (
                                        <SelectItem key={account.id} value={String(account.id)}>
                                            {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ status: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {reconciliations.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <FileCheck className="h-12 w-12 text-muted-foreground/40 mb-4" />
                                <h3 className="text-lg font-medium text-foreground mb-1">No reconciliations</h3>
                                <p className="text-muted-foreground mb-4">
                                    Start your first bank reconciliation.
                                </p>
                                <Button asChild>
                                    <Link href={'/finance/bank-reconciliation/create'}>
                                        <Plus className="w-4 h-4 mr-2" />
                                        New Reconciliation
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Bank Account</TableHead>
                                        <TableHead>Statement Date</TableHead>
                                        <TableHead className="text-right">Statement Balance</TableHead>
                                        <TableHead className="text-right">Calculated Balance</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Completed At</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reconciliations.data.map((recon) => (
                                        <TableRow key={recon.id}>
                                            <TableCell className="font-medium">
                                                {recon.bank_account?.name}
                                            </TableCell>
                                            <TableCell>{recon.statement_date}</TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatNZD(recon.statement_balance)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {recon.calculated_balance ? formatNZD(recon.calculated_balance) : '-'}
                                            </TableCell>
                                            <TableCell>{statusBadge(recon.status)}</TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {recon.completed_at || '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Link href={`/finance/bank-reconciliation/${recon.id}`}>
                                                    <Button variant="ghost" size="sm">
                                                        {recon.status === 'in_progress' ? 'Continue' : 'View'}
                                                    </Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {reconciliations.last_page > 1 && (
                    <div className="flex items-center justify-between mt-4">
                        <p className="text-sm text-muted-foreground">
                            Showing {(reconciliations.current_page - 1) * reconciliations.per_page + 1} to{' '}
                            {Math.min(reconciliations.current_page * reconciliations.per_page, reconciliations.total)} of{' '}
                            {reconciliations.total} reconciliations
                        </p>
                        <div className="flex gap-1">
                            {reconciliations.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
