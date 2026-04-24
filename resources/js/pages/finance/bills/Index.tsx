import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { Plus, Search, AlertTriangle, DollarSign, Clock, CalendarClock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface Vendor {
    id: number;
    name: string;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_reference: string | null;
    vendor: Vendor | null;
    bill_date: string;
    due_date: string;
    total_amount: string;
    amount_paid: string;
    status: string;
}

interface PaginatedBills {
    data: Bill[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Filters {
    status?: string;
    vendor_id?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Summary {
    total_unpaid: number;
    total_overdue: number;
    due_this_week: number;
}

interface Props extends PageProps {
    bills: PaginatedBills;
    vendors: Vendor[];
    filters: Filters;
    summary: Summary;
}

const formatCurrency = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground dark:bg-muted dark:text-muted-foreground' },
    awaiting_approval: { label: 'Awaiting Approval', className: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning' },
    approved: { label: 'Approved', className: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
    partially_paid: { label: 'Partially Paid', className: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning' },
    paid: { label: 'Paid', className: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success' },
    cancelled: { label: 'Cancelled', className: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Bills', href: '/finance/bills' },
];

export default function BillsIndex({ auth, bills, vendors, filters, summary }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [vendorId, setVendorId] = useState(filters.vendor_id ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (status) params.status = status;
        if (vendorId) params.vendor_id = vendorId;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;

        router.get('/finance/bills', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setVendorId('');
        setDateFrom('');
        setDateTo('');
        router.get('/finance/bills', {}, { preserveState: true });
    };

    const isOverdue = (bill: Bill) => {
        if (bill.status === 'paid' || bill.status === 'cancelled') return false;
        return new Date(bill.due_date) < new Date();
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Bills" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Bills</h1>
                        <p className="text-muted-foreground mt-1">Manage accounts payable</p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/bills/create">
                            <Plus className="w-4 h-4 mr-2" />
                            New Bill
                        </Link>
                    </Button>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info-bg p-2 dark:bg-status-info">
                                    <DollarSign className="h-5 w-5 text-status-info dark:text-status-info" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Unpaid</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.total_unpaid)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-critical-bg p-2 dark:bg-status-critical">
                                    <AlertTriangle className="h-5 w-5 text-status-critical dark:text-status-critical" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Overdue</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.total_overdue)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning-bg p-2 dark:bg-status-warning">
                                    <CalendarClock className="h-5 w-5 text-status-warning dark:text-status-warning" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Due This Week</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.due_this_week)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search bill # or vendor ref..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                    className="pl-9"
                                />
                            </div>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="awaiting_approval">Awaiting Approval</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="partially_paid">Partially Paid</SelectItem>
                                    <SelectItem value="paid">Paid</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={vendorId} onValueChange={setVendorId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All Vendors" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Vendors</SelectItem>
                                    {vendors.map((v) => (
                                        <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                placeholder="From"
                            />
                            <div className="flex gap-2">
                                <Input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    placeholder="To"
                                />
                                <Button onClick={applyFilters} variant="secondary" className="shrink-0">
                                    Filter
                                </Button>
                                <Button onClick={clearFilters} variant="ghost" className="shrink-0">
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Bill #</TableHead>
                                <TableHead>Vendor Ref</TableHead>
                                <TableHead>Vendor</TableHead>
                                <TableHead>Bill Date</TableHead>
                                <TableHead>Due Date</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                                <TableHead className="text-right">Paid</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bills.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                                        No bills found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bills.data.map((bill) => (
                                    <TableRow
                                        key={bill.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-muted/50',
                                            isOverdue(bill) && 'bg-status-critical-bg hover:bg-status-critical-bg dark:bg-status-critical dark:hover:bg-status-critical',
                                        )}
                                        onClick={() => router.get(`/finance/bills/${bill.id}`)}
                                    >
                                        <TableCell className="font-medium">
                                            <Link href={`/finance/bills/${bill.id}`} className="text-primary hover:underline">
                                                {bill.bill_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{bill.vendor_reference ?? '-'}</TableCell>
                                        <TableCell>{bill.vendor?.name ?? '-'}</TableCell>
                                        <TableCell>{formatDate(bill.bill_date)}</TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1">
                                                {isOverdue(bill) && <AlertTriangle className="w-3.5 h-3.5 text-status-critical" />}
                                                <span className={cn(isOverdue(bill) && 'text-status-critical font-medium dark:text-status-critical')}>
                                                    {formatDate(bill.due_date)}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">{formatCurrency(bill.total_amount)}</TableCell>
                                        <TableCell className="text-right">{formatCurrency(bill.amount_paid)}</TableCell>
                                        <TableCell>
                                            <Badge className={statusConfig[bill.status]?.className ?? 'bg-muted text-foreground'}>
                                                {statusConfig[bill.status]?.label ?? bill.status}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {bills.last_page > 1 && (
                        <div className="flex items-center justify-center gap-1 p-4 border-t">
                            {bills.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
