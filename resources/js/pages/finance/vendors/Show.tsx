import { Head, Link } from '@inertiajs/react';
import { type PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Edit, DollarSign, FileText, ShoppingCart, Users } from 'lucide-react';

interface Contact {
    id: number;
    name: string;
    role: string | null;
    email: string | null;
    phone: string | null;
    is_primary: boolean;
}

interface Vendor {
    id: number;
    name: string;
    trading_name: string | null;
    vendor_type: string;
    gst_number: string | null;
    bank_account_number: string | null;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    payment_terms_days: number | null;
    is_active: boolean;
    notes: string | null;
    contacts: Contact[];
}

interface Bill {
    id: number;
    bill_number: string;
    bill_date: string;
    due_date: string;
    total_amount: number;
    amount_paid: number;
    status: string;
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    order_date: string;
    total_amount: number;
    status: string;
}

interface Props extends PageProps {
    vendor: Vendor;
    bills: Bill[];
    purchaseOrders: PurchaseOrder[];
    totalOutstanding: number;
    totalPaidYtd: number;
}

const vendorTypeLabels: Record<string, string> = {
    supplier: 'Supplier',
    contractor: 'Contractor',
    utility: 'Utility',
    government: 'Government',
    other: 'Other',
};

const vendorTypeColors: Record<string, string> = {
    supplier: 'bg-blue-100 text-blue-800',
    contractor: 'bg-primary/10 text-primary',
    utility: 'bg-amber-100 text-amber-800',
    government: 'bg-teal-100 text-teal-800',
    other: 'bg-muted text-foreground',
};

const billStatusColors: Record<string, string> = {
    draft: 'bg-muted text-foreground',
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-blue-100 text-blue-800',
    paid: 'bg-green-100 text-green-800',
    overdue: 'bg-red-100 text-red-800',
    cancelled: 'bg-muted text-muted-foreground',
};

const poStatusColors: Record<string, string> = {
    draft: 'bg-muted text-foreground',
    pending_approval: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-blue-100 text-blue-800',
    sent: 'bg-primary/10 text-primary',
    received: 'bg-green-100 text-green-800',
    cancelled: 'bg-muted text-muted-foreground',
};

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const formatStatus = (status: string) =>
    status
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

export default function VendorsShow({ vendor, bills, purchaseOrders, totalOutstanding, totalPaidYtd }: Props) {
    const address = [vendor.address_line_1, vendor.address_line_2, vendor.city, vendor.region, vendor.postal_code]
        .filter(Boolean)
        .join(', ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Vendors', href: '/finance/vendors' },
        { title: vendor.name, href: `/finance/vendors/${vendor.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={vendor.name} />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/finance/vendors">
                                <ArrowLeft className="w-4 h-4 mr-1" />
                                Back
                            </Link>
                        </Button>
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-2xl font-bold tracking-tight">{vendor.name}</h1>
                                <Badge
                                    variant={vendor.is_active ? 'default' : 'secondary'}
                                    className={
                                        vendor.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-muted text-muted-foreground'
                                    }
                                >
                                    {vendor.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>
                            {vendor.trading_name && (
                                <p className="text-muted-foreground mt-1">
                                    Trading as: {vendor.trading_name}
                                </p>
                            )}
                        </div>
                    </div>
                    <Button asChild>
                        <Link href={`/finance/vendors/${vendor.id}/edit`}>
                            <Edit className="w-4 h-4 mr-2" />
                            Edit
                        </Link>
                    </Button>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left column */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Vendor Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Vendor Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                                    <div>
                                        <dt className="text-sm font-medium text-muted-foreground">Type</dt>
                                        <dd className="mt-1">
                                            <Badge
                                                variant="secondary"
                                                className={vendorTypeColors[vendor.vendor_type] || ''}
                                            >
                                                {vendorTypeLabels[vendor.vendor_type] || vendor.vendor_type}
                                            </Badge>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-muted-foreground">GST Number</dt>
                                        <dd className="mt-1 text-sm">
                                            {vendor.gst_number || '-'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-muted-foreground">Email</dt>
                                        <dd className="mt-1 text-sm">
                                            {vendor.email ? (
                                                <a
                                                    href={`mailto:${vendor.email}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {vendor.email}
                                                </a>
                                            ) : (
                                                '-'
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-muted-foreground">Phone</dt>
                                        <dd className="mt-1 text-sm">
                                            {vendor.phone || '-'}
                                        </dd>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <dt className="text-sm font-medium text-muted-foreground">Address</dt>
                                        <dd className="mt-1 text-sm">
                                            {address || '-'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-muted-foreground">Payment Terms</dt>
                                        <dd className="mt-1 text-sm">
                                            {vendor.payment_terms_days != null
                                                ? `${vendor.payment_terms_days} days`
                                                : '-'}
                                        </dd>
                                    </div>
                                    {vendor.notes && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-sm font-medium text-muted-foreground">Notes</dt>
                                            <dd className="mt-1 text-sm whitespace-pre-line">
                                                {vendor.notes}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Contacts */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Users className="w-5 h-5 text-muted-foreground" />
                                    <CardTitle>Contacts</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {vendor.contacts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground text-center py-4">
                                        No contacts recorded.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Role</TableHead>
                                                <TableHead>Email</TableHead>
                                                <TableHead>Phone</TableHead>
                                                <TableHead></TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {vendor.contacts.map((contact) => (
                                                <TableRow key={contact.id}>
                                                    <TableCell className="font-medium">
                                                        {contact.name}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {contact.role || '-'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {contact.email || '-'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {contact.phone || '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {contact.is_primary && (
                                                            <Badge className="bg-blue-100 text-blue-800">
                                                                Primary
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent Bills */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <FileText className="w-5 h-5 text-muted-foreground" />
                                    <CardTitle>Recent Bills</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {bills.length === 0 ? (
                                    <p className="text-sm text-muted-foreground text-center py-4">
                                        No bills recorded.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Bill Number</TableHead>
                                                <TableHead>Date</TableHead>
                                                <TableHead>Due Date</TableHead>
                                                <TableHead className="text-right">Amount</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {bills.map((bill) => (
                                                <TableRow key={bill.id}>
                                                    <TableCell>
                                                        <Link
                                                            href={`/finance/bills/${bill.id}`}
                                                            className="font-medium text-primary hover:underline"
                                                        >
                                                            {bill.bill_number}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {formatDate(bill.bill_date)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {formatDate(bill.due_date)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatCurrency(bill.total_amount)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="secondary"
                                                            className={billStatusColors[bill.status] || 'bg-muted text-foreground'}
                                                        >
                                                            {formatStatus(bill.status)}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent Purchase Orders */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <ShoppingCart className="w-5 h-5 text-muted-foreground" />
                                    <CardTitle>Recent Purchase Orders</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {purchaseOrders.length === 0 ? (
                                    <p className="text-sm text-muted-foreground text-center py-4">
                                        No purchase orders recorded.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>PO Number</TableHead>
                                                <TableHead>Date</TableHead>
                                                <TableHead className="text-right">Amount</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {purchaseOrders.map((po) => (
                                                <TableRow key={po.id}>
                                                    <TableCell>
                                                        <Link
                                                            href={`/finance/purchase-orders/${po.id}`}
                                                            className="font-medium text-primary hover:underline"
                                                        >
                                                            {po.po_number}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {formatDate(po.order_date)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatCurrency(po.total_amount)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="secondary"
                                                            className={poStatusColors[po.status] || 'bg-muted text-foreground'}
                                                        >
                                                            {formatStatus(po.status)}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column - Financial Summary */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <DollarSign className="w-5 h-5 text-muted-foreground" />
                                    <CardTitle>Financial Summary</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Total Outstanding</p>
                                    <p className="text-2xl font-bold mt-1">
                                        {formatCurrency(totalOutstanding)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Total Paid YTD</p>
                                    <p className="text-2xl font-bold mt-1">
                                        {formatCurrency(totalPaidYtd)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
