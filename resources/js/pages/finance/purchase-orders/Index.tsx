import {
    NewPoDialog,
    PayablesTabsFooter,
    formatMoney,
    useRowContextMenu,
    type AccountOption,
    type RowCtxItem,
} from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
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
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Eye, Plus, ShoppingCart } from 'lucide-react';
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
    const { purchaseOrders, vendors, filters, canManage, accounts } = usePage()
        .props as any;
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
        router.get(
            '/finance/purchase-orders',
            { ...current, ...next },
            { preserveState: true, preserveScroll: true },
        );
    }

    function clearFilters() {
        router.get(
            '/finance/purchase-orders',
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    const hasFilters = Boolean(
        current.search || current.status || current.vendor_id,
    );

    // Right-click row menu — mirrors the row's existing inline action (the PO-number link to the show route).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (po: PurchaseOrder): RowCtxItem[] => [
        {
            kind: 'item',
            label: 'Open',
            icon: Eye,
            onSelect: () => router.get(`/finance/purchase-orders/${po.id}`),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase Orders" />
            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={ShoppingCart}
                        title="Purchase Orders"
                        description="Manage purchase orders and convert them to bills."
                        stats={[
                            {
                                label: 'Total',
                                value: purchaseOrders?.total ?? rows.length,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={`/finance/purchase-orders/export?${new URLSearchParams(Object.entries({ status: current.status, vendor_id: current.vendor_id, search: current.search }).filter(([, v]) => v)).toString()}`}
                                    >
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setNewPoOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Purchase Order
                                    </Button>
                                )}
                            </div>
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
                                onValueChange={(v) =>
                                    apply({ status: v === 'all' ? '' : v })
                                }
                            >
                                <SelectTrigger aria-label="Filter by status">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="approved">
                                        Approved
                                    </SelectItem>
                                    <SelectItem value="sent">Sent</SelectItem>
                                    <SelectItem value="partially_received">
                                        Partially Received
                                    </SelectItem>
                                    <SelectItem value="received">
                                        Received
                                    </SelectItem>
                                    <SelectItem value="cancelled">
                                        Cancelled
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Vendor</Label>
                            <Select
                                value={
                                    current.vendor_id
                                        ? String(current.vendor_id)
                                        : 'all'
                                }
                                onValueChange={(v) =>
                                    apply({ vendor_id: v === 'all' ? '' : v })
                                }
                            >
                                <SelectTrigger aria-label="Filter by vendor">
                                    <SelectValue placeholder="All vendors" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {vendorList.map((v) => (
                                        <SelectItem
                                            key={v.id}
                                            value={String(v.id)}
                                        >
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
                                onChange={(e) =>
                                    apply({ search: e.target.value })
                                }
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
                                    <TableHead className="text-right">
                                        Total
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            {hasFilters ? (
                                                <EmptySearch
                                                    onClear={clearFilters}
                                                    title="No purchase orders match your filters"
                                                    className="border-0"
                                                />
                                            ) : (
                                                <EmptyList
                                                    icon={ShoppingCart}
                                                    itemName="purchase order"
                                                    title="No purchase orders yet"
                                                    description="Create your first purchase order to get started."
                                                    className="border-0"
                                                    action={
                                                        canManage ? (
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    setNewPoOpen(
                                                                        true,
                                                                    )
                                                                }
                                                            >
                                                                New purchase
                                                                order
                                                            </Button>
                                                        ) : undefined
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((po) => (
                                        <TableRow
                                            key={po.id}
                                            onContextMenu={rowMenu.open(
                                                rowMenuItems(po),
                                            )}
                                        >
                                            <TableCell>
                                                <Link
                                                    href={`/finance/purchase-orders/${po.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {po.po_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {po.vendor?.name ?? '-'}
                                            </TableCell>
                                            <TableCell>
                                                {po.order_date}
                                            </TableCell>
                                            <TableCell>
                                                {po.expected_date ?? '-'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(po.total_amount)}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={po.status}
                                                />
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
                        {purchaseOrders.links.map(
                            (l: PaginationLink, idx: number) => (
                                <Button
                                    key={idx}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!l.url}
                                    onClick={() =>
                                        l.url &&
                                        router.get(
                                            l.url,
                                            {},
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: l.label,
                                    }}
                                />
                            ),
                        )}
                    </div>
                ) : null}

                {rowMenu.element}
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
