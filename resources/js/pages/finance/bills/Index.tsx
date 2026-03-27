import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { Plus, Search, AlertTriangle } from 'lucide-react';
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

interface Props extends PageProps {
    bills: PaginatedBills;
    vendors: Vendor[];
    filters: Filters;
}

const formatCurrency = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-800' },
    awaiting_approval: { label: 'Awaiting Approval', className: 'bg-yellow-100 text-yellow-800' },
    approved: { label: 'Approved', className: 'bg-blue-100 text-blue-800' },
    partially_paid: { label: 'Partially Paid', className: 'bg-orange-100 text-orange-800' },
    paid: { label: 'Paid', className: 'bg-green-100 text-green-800' },
    cancelled: { label: 'Cancelled', className: 'bg-red-100 text-red-800' },
};

export default function BillsIndex({ auth, bills, vendors, filters }: Props) {
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
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Bills', href: '/finance/bills' },
            ]}
        >
            <Head title="Bills" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Bills</h1>
                        <p className="text-gray-500 mt-1">Manage accounts payable</p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/bills/create">
                            <Plus className="w-4 h-4 mr-2" />
                            New Bill
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
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
                                    <TableCell colSpan={8} className="text-center text-gray-500 py-8">
                                        No bills found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bills.data.map((bill) => (
                                    <TableRow
                                        key={bill.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-gray-50',
                                            isOverdue(bill) && 'bg-red-50 hover:bg-red-100',
                                        )}
                                        onClick={() => router.get(`/finance/bills/${bill.id}`)}
                                    >
                                        <TableCell className="font-medium">
                                            <Link href={`/finance/bills/${bill.id}`} className="text-blue-600 hover:underline">
                                                {bill.bill_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-gray-500">{bill.vendor_reference ?? '-'}</TableCell>
                                        <TableCell>{bill.vendor?.name ?? '-'}</TableCell>
                                        <TableCell>{formatDate(bill.bill_date)}</TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1">
                                                {isOverdue(bill) && <AlertTriangle className="w-3.5 h-3.5 text-red-500" />}
                                                <span className={cn(isOverdue(bill) && 'text-red-600 font-medium')}>
                                                    {formatDate(bill.due_date)}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">{formatCurrency(bill.total_amount)}</TableCell>
                                        <TableCell className="text-right">{formatCurrency(bill.amount_paid)}</TableCell>
                                        <TableCell>
                                            <Badge className={statusConfig[bill.status]?.className ?? 'bg-gray-100 text-gray-800'}>
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
