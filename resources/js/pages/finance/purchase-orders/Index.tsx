import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { NewPoDialog, PayablesTabsFooter, formatMoney, type AccountOption } from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, ShoppingCart } from 'lucide-react';
import { useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Purchase Orders', href: '/finance/purchase-orders' },
];

export default function PurchaseOrderIndex() {
    const { purchaseOrders, vendors, filters, canManage, accounts } = usePage().props as any;
    const [newPoOpen, setNewPoOpen] = useState(false);

    const rows: PurchaseOrder[] = purchaseOrders?.data ?? [];
    const vendorList: Vendor[] = vendors ?? [];
    const accountList: AccountOption[] = accounts ?? [];

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
            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={ShoppingCart}
                        title="Purchase Orders"
                        description="Manage purchase orders and convert them to bills."
                        stats={[
                            { label: 'Total', value: purchaseOrders?.total ?? rows.length },
                        ]}
                        actions={
                            canManage && (
                                <Button size="sm" onClick={() => setNewPoOpen(true)}>
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    New Purchase Order
                                </Button>
                            )
                        }
                        footer={<PayablesTabsFooter active="purchase-orders" />}
                    />
                }
            >
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
                                            <TableCell className="text-right">{formatMoney(po.total_amount)}</TableCell>
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
            </PageLayout>

            {canManage && (
                <NewPoDialog
                    open={newPoOpen}
                    onClose={() => setNewPoOpen(false)}
                    vendors={vendorList}
                    accounts={accountList}
                />
            )}
        </AppLayout>
    );
}
