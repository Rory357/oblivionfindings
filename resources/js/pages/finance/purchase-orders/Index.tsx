import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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

const statusConfig: Record<string, { label: string; variant: string; className: string }> = {
    draft: { label: 'Draft', variant: 'secondary', className: 'bg-gray-100 text-gray-800' },
    approved: { label: 'Approved', variant: 'default', className: 'bg-blue-100 text-blue-800' },
    sent: { label: 'Sent', variant: 'default', className: 'bg-indigo-100 text-indigo-800' },
    partially_received: { label: 'Partially Received', variant: 'default', className: 'bg-yellow-100 text-yellow-800' },
    received: { label: 'Received', variant: 'default', className: 'bg-green-100 text-green-800' },
    cancelled: { label: 'Cancelled', variant: 'destructive', className: 'bg-red-100 text-red-800' },
};

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? { label: status, variant: 'secondary', className: 'bg-gray-100 text-gray-800' };
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.className}`}>{config.label}</span>;
}

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
        <AppLayout breadcrumbs={[{ title: 'Finance', href: '/finance/dashboard' }, { title: 'Purchase Orders', href: '/finance/purchase-orders' }]}>
            <Head title="Purchase Orders" />
            <div className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Purchase Orders</h1>
                        <p className="text-sm text-slate-500">Manage purchase orders and convert them to bills.</p>
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
                                        <TableCell colSpan={6} className="text-center text-sm text-slate-500">
                                            No purchase orders found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((po) => (
                                        <TableRow key={po.id}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/purchase-orders/${po.id}`}
                                                    className="font-medium text-blue-600 hover:underline"
                                                >
                                                    {po.po_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{po.vendor?.name ?? '—'}</TableCell>
                                            <TableCell>{po.order_date}</TableCell>
                                            <TableCell>{po.expected_date ?? '—'}</TableCell>
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
                            <button
                                key={idx}
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true, preserveState: true })}
                                className={`rounded border px-3 py-1 text-sm ${l.active ? 'bg-slate-100 font-semibold' : 'hover:bg-slate-50'} ${!l.url ? 'opacity-50' : ''}`}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
