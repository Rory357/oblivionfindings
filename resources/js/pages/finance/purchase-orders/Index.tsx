import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

type Vendor = { id: number; name: string };

type PurchaseOrder = {
    id: number;
    po_number: string;
    vendor?: Vendor | null;
    order_date: string;
    expected_date: string | null;
    total_amount: string;
    status: string;
};

type PaginationLink = { url: string | null; label: string; active: boolean };

const formatNZD = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground' },
    approved: { label: 'Approved', className: 'bg-status-info-bg text-status-info' },
    sent: { label: 'Sent', className: 'bg-primary/10 text-primary' },
    partially_received: { label: 'Partially Received', className: 'bg-status-warning-bg text-status-warning' },
    received: { label: 'Received', className: 'bg-status-success-bg text-status-success' },
    cancelled: { label: 'Cancelled', className: 'bg-status-critical-bg text-status-critical' },
};

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? { label: status, className: 'bg-muted text-foreground' };
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.className}`}>{config.label}</span>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Purchase Orders', href: '/finance/purchase-orders' },
];

export default function PurchaseOrderIndex() {
    const { purchaseOrders, vendors, filters } = usePage().props as any;

    const rows: PurchaseOrder[] = purchaseOrders?.data ?? [];
    const vendorList: Vendor[] = vendors ?? [];

    const current = {
        status: filters?.status ?? '',
        vendor_id: filters?.vendor_id ?? '',
        search: filters?.search ?? '',
    };

    function apply(next: Record<string, string>) {
        router.get('/finance/purchase-orders', { ...current, ...next }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase Orders" />
            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Purchase Orders</h1>
                        <p className="text-muted-foreground">Manage purchase orders and convert them to bills.</p>
                    </div>
                    <Link href="/finance/purchase-orders/create">
                        <Button>New Purchase Order</Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div className="space-y-1">
                            <Label>Status</Label>
                            <Select
                                value={current.status || 'all'}
                                onValueChange={(v) => apply({ status: v === 'all' ? '' : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="sent">Sent</SelectItem>
                                    <SelectItem value="partially_received">Partially Received</SelectItem>
                                    <SelectItem value="received">Received</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Vendor</Label>
                            <Select
                                value={current.vendor_id ? String(current.vendor_id) : 'all'}
                                onValueChange={(v) => apply({ vendor_id: v === 'all' ? '' : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All vendors" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {vendorList.map((v) => (
                                        <SelectItem key={v.id} value={String(v.id)}>
                                            {v.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Search</Label>
                            <Input
                                value={current.search}
                                placeholder="PO number..."
                                onChange={(e) => apply({ search: e.target.value })}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>PO Number</TableHead>
                                    <TableHead>Vendor</TableHead>
                                    <TableHead>Order Date</TableHead>
                                    <TableHead>Expected Date</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                                            No purchase orders found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((po) => (
                                        <TableRow key={po.id}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/purchase-orders/${po.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {po.po_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{po.vendor?.name ?? '-'}</TableCell>
                                            <TableCell>{po.order_date}</TableCell>
                                            <TableCell>{po.expected_date ?? '-'}</TableCell>
                                            <TableCell className="text-right">{formatNZD(po.total_amount)}</TableCell>
                                            <TableCell>
                                                <StatusBadge status={po.status} />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {purchaseOrders?.links ? (
                    <div className="flex flex-wrap gap-2">
                        {purchaseOrders.links.map((l: PaginationLink, idx: number) => (
                            <Button
                                key={idx}
                                variant={l.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true, preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
