import {
    FinanceSectionRail,
    NewVendorDialog,
    formatMoney,
    type AccountOption,
} from '@/components/finance';
import type { EditableVendor } from '@/components/finance/new-vendor-dialog';
import {
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Building2,
    FileText,
    Pencil,
    Receipt,
    ShoppingCart,
    Users,
} from 'lucide-react';
import { useState } from 'react';

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
    default_expense_account_id: number | null;
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
    billsCount: number;
    openBillsCount: number;
    purchaseOrdersCount: number;
    canManage: boolean;
    expenseAccounts: AccountOption[];
}

const VENDOR_TYPE_LABELS: Record<string, string> = {
    supplier: 'Supplier',
    contractor: 'Contractor',
    utility: 'Utility',
    government: 'Government',
    other: 'Other',
};

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

type VendorTab = 'details' | 'contacts' | 'bills' | 'purchase-orders';

const TABS: { key: VendorTab; label: string; icon: typeof Building2 }[] = [
    { key: 'details', label: 'Details', icon: FileText },
    { key: 'contacts', label: 'Contacts', icon: Users },
    { key: 'bills', label: 'Bills', icon: Receipt },
    { key: 'purchase-orders', label: 'Purchase orders', icon: ShoppingCart },
];

const isVendorTab = (value: string | null): value is VendorTab =>
    TABS.some((t) => t.key === value);

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-caption">{label}</dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}

export default function VendorsShow({
    vendor,
    bills,
    purchaseOrders,
    totalOutstanding,
    totalPaidYtd,
    billsCount,
    openBillsCount,
    purchaseOrdersCount,
    canManage,
    expenseAccounts,
}: Props) {
    const [tab, setTab] = useState<VendorTab>(() => {
        if (typeof window === 'undefined') return 'details';
        const requested = new URLSearchParams(window.location.search).get(
            'tab',
        );
        return isVendorTab(requested) ? requested : 'details';
    });
    const [editOpen, setEditOpen] = useState(false);

    const selectTab = (key: string) => {
        if (!isVendorTab(key)) return;
        setTab(key);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', key);
            window.history.replaceState({}, '', url);
        }
    };

    const address = [
        vendor.address_line_1,
        vendor.address_line_2,
        vendor.city,
        vendor.region,
        vendor.postal_code,
    ]
        .filter(Boolean)
        .join(', ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Vendors', href: '/finance/vendors' },
        { title: vendor.name, href: `/finance/vendors/${vendor.id}` },
    ];

    const billsHref = `/finance/bills?vendor_id=${vendor.id}`;
    const posHref = `/finance/purchase-orders?vendor_id=${vendor.id}`;

    const billColumns: EntityTableColumn<Bill>[] = [
        {
            key: 'bill_date',
            label: 'Bill date',
            width: '1fr',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.bill_date)}
                </span>
            ),
        },
        {
            key: 'due_date',
            label: 'Due',
            width: '1fr',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.due_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1fr',
            align: 'right',
            cell: (b) => (
                <span className="tabular-nums">
                    {formatMoney(b.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (b) => (
                <StatusBadge
                    status={b.status}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const poColumns: EntityTableColumn<PurchaseOrder>[] = [
        {
            key: 'order_date',
            label: 'Order date',
            width: '1fr',
            cell: (p) => (
                <span className="text-muted-foreground">
                    {formatDate(p.order_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1fr',
            align: 'right',
            cell: (p) => (
                <span className="tabular-nums">
                    {formatMoney(p.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '150px',
            cell: (p) => (
                <StatusBadge
                    status={p.status}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const contactColumns: EntityTableColumn<Contact>[] = [
        {
            key: 'email',
            label: 'Email',
            width: '1.6fr',
            cell: (c) => (
                <span className="truncate text-muted-foreground">
                    {c.email || '—'}
                </span>
            ),
        },
        {
            key: 'phone',
            label: 'Phone',
            width: '1.2fr',
            cell: (c) => (
                <span className="truncate text-muted-foreground">
                    {c.phone || '—'}
                </span>
            ),
        },
        {
            key: 'primary',
            label: 'Primary',
            width: '120px',
            cell: (c) =>
                c.is_primary ? (
                    <StatusBadge
                        variant="info"
                        className="rounded-[8px] font-semibold"
                    >
                        Primary
                    </StatusBadge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const editableVendor: EditableVendor = {
        ...vendor,
        contacts: vendor.contacts,
    };

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/vendors"
            icon={Building2}
            title={vendor.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={vendor.is_active ? 'success' : 'neutral'}
                >
                    {vendor.is_active ? 'Active' : 'Inactive'}
                </PageHeaderStatusChip>
            }
            subline={[
                VENDOR_TYPE_LABELS[vendor.vendor_type] ?? vendor.vendor_type,
                vendor.trading_name ? `Trading as ${vendor.trading_name}` : null,
                vendor.payment_terms_days != null
                    ? `${vendor.payment_terms_days}-day terms`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Receipt}
                        onClick={() => router.get(billsHref)}
                    >
                        View bills
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit vendor
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Outstanding"
                        tone="warning"
                        href={billsHref}
                        ariaLabel="View this vendor's unpaid bills"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalOutstanding)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {openBillsCount} bill
                            {openBillsCount === 1 ? '' : 's'} still to pay
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Paid this year"
                        tone="success"
                        href={`${billsHref}&status=paid`}
                        ariaLabel="View this vendor's paid bills"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalPaidYtd)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Since 1 January
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bills"
                        href={billsHref}
                        ariaLabel="View this vendor's bills"
                    >
                        <PageHeaderMeterBig>{billsCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            All bills for this vendor
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Purchase orders"
                        href={posHref}
                        ariaLabel="View this vendor's purchase orders"
                    >
                        <PageHeaderMeterBig>
                            {purchaseOrdersCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Raised with this vendor
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={vendor.name} />

            <PageLayout
                hero={header}
                tabs={
                    <TierTwoTabs
                        tabs={TABS.map((t) => ({
                            key: t.key,
                            label: t.label,
                            icon: t.icon,
                            count:
                                t.key === 'contacts'
                                    ? vendor.contacts.length
                                    : t.key === 'bills'
                                      ? billsCount
                                      : t.key === 'purchase-orders'
                                        ? purchaseOrdersCount
                                        : undefined,
                        }))}
                        activeTab={tab}
                        onTab={selectTab}
                        testIdPrefix="vendor"
                        ariaLabel="Vendor sections"
                        renderLink={() => null}
                    />
                }
            >
                {tab === 'details' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Vendor details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                                <DetailRow label="Type">
                                    {VENDOR_TYPE_LABELS[vendor.vendor_type] ??
                                        vendor.vendor_type}
                                </DetailRow>
                                <DetailRow label="GST number">
                                    {vendor.gst_number || '—'}
                                </DetailRow>
                                <DetailRow label="Email">
                                    {vendor.email ? (
                                        <a
                                            href={`mailto:${vendor.email}`}
                                            className="text-primary hover:underline"
                                        >
                                            {vendor.email}
                                        </a>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                <DetailRow label="Phone">
                                    {vendor.phone || '—'}
                                </DetailRow>
                                <DetailRow label="Address">
                                    {address || '—'}
                                </DetailRow>
                                <DetailRow label="Bank account">
                                    {vendor.bank_account_number || '—'}
                                </DetailRow>
                                <DetailRow label="Payment terms">
                                    {vendor.payment_terms_days != null
                                        ? `${vendor.payment_terms_days} days`
                                        : '—'}
                                </DetailRow>
                                {vendor.notes && (
                                    <div className="sm:col-span-2 lg:col-span-3">
                                        <dt className="text-caption">Notes</dt>
                                        <dd className="mt-1 text-sm whitespace-pre-line">
                                            {vendor.notes}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {tab === 'contacts' && (
                    <>
                        <ListCaption
                            title="Contacts"
                            caption={`${vendor.contacts.length} recorded`}
                        />
                        {vendor.contacts.length === 0 ? (
                            <EmptyList
                                icon={Users}
                                itemName="contact"
                                title="No contacts recorded"
                                description="Add a named contact on the vendor if you deal with someone in particular."
                            />
                        ) : (
                            <EntityTable
                                rows={vendor.contacts}
                                rowKey={(c) => c.id}
                                identityLabel="Contact"
                                identity={(c) => ({
                                    icon: Users,
                                    name: c.name,
                                    subline: c.role || undefined,
                                })}
                                columns={contactColumns}
                                actionsFor={() => []}
                                minWidth={760}
                            />
                        )}
                    </>
                )}

                {tab === 'bills' && (
                    <>
                        <ListCaption
                            title="Recent bills"
                            caption={`${bills.length} of ${billsCount} shown`}
                            right={
                                <Link
                                    href={billsHref}
                                    className="text-[12.5px] font-semibold text-primary hover:underline"
                                >
                                    See all bills
                                </Link>
                            }
                        />
                        {bills.length === 0 ? (
                            <EmptyList
                                icon={Receipt}
                                itemName="bill"
                                title="No bills for this vendor"
                                description="Bills raised against this vendor will appear here."
                            />
                        ) : (
                            <EntityTable
                                rows={bills}
                                rowKey={(b) => b.id}
                                identityLabel="Bill"
                                identity={(b) => ({
                                    icon: Receipt,
                                    name: b.bill_number,
                                })}
                                columns={billColumns}
                                actionsFor={() => []}
                                hrefFor={(b) => `/finance/bills/${b.id}`}
                                onOpen={(b) =>
                                    router.get(`/finance/bills/${b.id}`)
                                }
                                minWidth={820}
                            />
                        )}
                    </>
                )}

                {tab === 'purchase-orders' && (
                    <>
                        <ListCaption
                            title="Recent purchase orders"
                            caption={`${purchaseOrders.length} of ${purchaseOrdersCount} shown`}
                            right={
                                <Link
                                    href={posHref}
                                    className="text-[12.5px] font-semibold text-primary hover:underline"
                                >
                                    See all purchase orders
                                </Link>
                            }
                        />
                        {purchaseOrders.length === 0 ? (
                            <EmptyList
                                icon={ShoppingCart}
                                itemName="purchase order"
                                title="No purchase orders for this vendor"
                                description="Purchase orders raised with this vendor will appear here."
                            />
                        ) : (
                            <EntityTable
                                rows={purchaseOrders}
                                rowKey={(p) => p.id}
                                identityLabel="Purchase order"
                                identity={(p) => ({
                                    icon: ShoppingCart,
                                    name: p.po_number,
                                })}
                                columns={poColumns}
                                actionsFor={() => []}
                                hrefFor={(p) =>
                                    `/finance/purchase-orders/${p.id}`
                                }
                                onOpen={(p) =>
                                    router.get(
                                        `/finance/purchase-orders/${p.id}`,
                                    )
                                }
                                minWidth={760}
                            />
                        )}
                    </>
                )}
            </PageLayout>

            {canManage && editOpen && (
                <NewVendorDialog
                    open
                    vendor={editableVendor}
                    onClose={() => setEditOpen(false)}
                    expenseAccounts={expenseAccounts}
                />
            )}
        </AppLayout>
    );
}
