import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { NewBillDialog, PayablesTabsFooter, formatMoney, useRowContextMenu, type AccountOption, type RowCtxItem } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { FinanceSummaryCard } from '@/components/finance/summary-card';
import { Plus, Search, AlertTriangle, DollarSign, Clock, CalendarClock, ArrowDownToLine, Download, Eye, Pencil } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface Vendor {
    id: number;
    name: string;
}

interface BillLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: number | null;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_id: number;
    vendor_reference: string | null;
    vendor: Vendor | null;
    bill_date: string;
    due_date: string;
    total_amount: string;
    amount_paid: string;
    status: string;
    notes: string | null;
    lines: BillLine[];
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
    canManage: boolean;
    accounts: AccountOption[];
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Bills', href: '/finance/bills' },
];

export default function BillsIndex({ auth, bills, vendors, filters, summary, canManage, accounts }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [vendorId, setVendorId] = useState(filters.vendor_id ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [newBillOpen, setNewBillOpen] = useState(false);
    const [editBill, setEditBill] = useState<Bill | null>(null);

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

    const hasFilters = Boolean(search || (status && status !== 'all') || (vendorId && vendorId !== 'all') || dateFrom || dateTo);

    const isOverdue = (bill: Bill) => {
        if (bill.status === 'paid' || bill.status === 'cancelled') return false;
        return new Date(bill.due_date) < new Date();
    };

    // Right-click row menu — mirrors the row's existing inline actions (Open first).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (bill: Bill): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            { kind: 'item', label: 'Open', icon: Eye, onSelect: () => router.get(`/finance/bills/${bill.id}`) },
        ];
        if (canManage && bill.status === 'draft') {
            items.push({ kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => setEditBill(bill) });
        }
        return items;
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Bills" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={ArrowDownToLine}
                        title="Bills"
                        description="Manage accounts payable"
                        stats={[
                            { label: 'Total unpaid', value: formatMoney(summary.total_unpaid) },
                            { label: 'Overdue', value: formatMoney(summary.total_overdue) },
                            { label: 'Due this week', value: formatMoney(summary.due_this_week) },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/finance/bills/export?${new URLSearchParams(Object.entries({ status, vendor_id: vendorId, search, date_from: dateFrom, date_to: dateTo }).filter(([, v]) => v)).toString()}`}>
                                        <Download className="w-4 h-4 mr-1.5" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setNewBillOpen(true)}>
                                        <Plus className="w-4 h-4 mr-1.5" />
                                        New Bill
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<PayablesTabsFooter active="bills" />}
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <FinanceSummaryCard icon={DollarSign} tone="info" label="Total Unpaid" value={formatMoney(summary.total_unpaid)} />
                    <FinanceSummaryCard icon={AlertTriangle} tone="critical" label="Overdue" value={formatMoney(summary.total_overdue)} />
                    <FinanceSummaryCard icon={CalendarClock} tone="warning" label="Due This Week" value={formatMoney(summary.due_this_week)} />
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
                                {canManage && <TableHead className="text-right">Actions</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bills.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={canManage ? 9 : 8} className="p-0">
                                        {hasFilters ? (
                                            <EmptySearch
                                                onClear={clearFilters}
                                                title="No bills match your filters"
                                                className="border-0"
                                            />
                                        ) : (
                                            <EmptyList
                                                icon={ArrowDownToLine}
                                                itemName="bill"
                                                title="No bills yet"
                                                description="Record your first supplier bill to get started."
                                                className="border-0"
                                                action={
                                                    canManage ? (
                                                        <Button size="sm" onClick={() => setNewBillOpen(true)}>
                                                            New bill
                                                        </Button>
                                                    ) : undefined
                                                }
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bills.data.map((bill) => (
                                    <TableRow
                                        key={bill.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-muted/50',
                                            isOverdue(bill) && 'bg-status-critical-bg hover:bg-status-critical-bg dark:hover:bg-status-critical',
                                        )}
                                        onClick={() => router.get(`/finance/bills/${bill.id}`)}
                                        onContextMenu={rowMenu.open(rowMenuItems(bill))}
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
                                        <TableCell className="text-right font-medium">{formatMoney(bill.total_amount)}</TableCell>
                                        <TableCell className="text-right">{formatMoney(bill.amount_paid)}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={bill.status} />
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                {bill.status === 'draft' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setEditBill(bill);
                                                        }}
                                                    >
                                                        Edit
                                                    </Button>
                                                )}
                                            </TableCell>
                                        )}
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

                {rowMenu.element}
            </PageLayout>

            {canManage && (
                <NewBillDialog
                    open={newBillOpen}
                    onClose={() => setNewBillOpen(false)}
                    vendors={vendors}
                    accounts={accounts}
                />
            )}

            {canManage && editBill && (
                <NewBillDialog
                    key={editBill.id}
                    open
                    bill={editBill}
                    onClose={() => setEditBill(null)}
                    vendors={vendors}
                    accounts={accounts}
                />
            )}
        </AppLayout>
    );
}
