import { Head, Link, router } from '@inertiajs/react';
import { type PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { NewVendorDialog, PayablesTabsFooter, useRowContextMenu, type AccountOption, type RowCtxItem } from '@/components/finance';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Search, Building2, Download, Eye } from 'lucide-react';
import { useState, useCallback } from 'react';

interface Vendor {
    id: number;
    name: string;
    trading_name: string | null;
    vendor_type: string;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    bills_count: number;
}

interface PaginatedVendors {
    data: Vendor[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Filters {
    search: string;
    vendor_type: string;
    is_active: string;
}

interface Props extends PageProps {
    vendors: PaginatedVendors;
    filters: Filters;
    canManage: boolean;
    expenseAccounts: AccountOption[];
}

const vendorTypeLabels: Record<string, string> = {
    supplier: 'Supplier',
    contractor: 'Contractor',
    utility: 'Utility',
    government: 'Government',
    other: 'Other',
};

const vendorTypeColors: Record<string, string> = {
    supplier: 'bg-status-info-bg text-status-info',
    contractor: 'bg-primary/10 text-primary',
    utility: 'bg-status-warning-bg text-status-warning',
    government: 'bg-status-info-bg text-status-info',
    other: 'bg-muted text-foreground',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Vendors', href: '/finance/vendors' },
];

export default function VendorsIndex({ vendors, filters, canManage, expenseAccounts }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [newVendorOpen, setNewVendorOpen] = useState(false);

    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/vendors',
                { ...filters, ...newFilters, page: 1 },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const handleSearch = useCallback(() => {
        applyFilters({ search });
    }, [search, applyFilters]);

    const handleSearchKeyDown = useCallback(
        (e: React.KeyboardEvent) => {
            if (e.key === 'Enter') {
                handleSearch();
            }
        },
        [handleSearch],
    );

    const activeCount = vendors.data.filter((v) => v.is_active).length;

    // Right-click row menu — mirrors the row's existing inline actions (Open first).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (vendor: Vendor): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            { kind: 'item', label: 'Open', icon: Eye, onSelect: () => router.get(`/finance/vendors/${vendor.id}`) },
        ];
        return items;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendors" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Building2}
                        title="Vendors"
                        description="Manage your suppliers, contractors and service providers"
                        stats={[
                            { label: 'Total', value: vendors.total },
                            { label: 'Active (this page)', value: activeCount },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/finance/vendors/export?${new URLSearchParams(Object.entries({ search, vendor_type: filters.vendor_type, is_active: filters.is_active }).filter(([, v]) => v)).toString()}`}>
                                        <Download className="w-4 h-4 mr-1.5" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setNewVendorOpen(true)}>
                                        <Plus className="w-4 h-4 mr-1.5" />
                                        Add Vendor
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<PayablesTabsFooter active="vendors" />}
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-col sm:flex-row gap-4">
                            <div className="flex-1">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search by name or email..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={handleSearchKeyDown}
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <Select
                                value={filters.vendor_type || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ vendor_type: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="supplier">Supplier</SelectItem>
                                    <SelectItem value="contractor">Contractor</SelectItem>
                                    <SelectItem value="utility">Utility</SelectItem>
                                    <SelectItem value="government">Government</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.is_active === '' ? 'all' : filters.is_active}
                                onValueChange={(value) =>
                                    applyFilters({ is_active: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="1">Active</SelectItem>
                                    <SelectItem value="0">Inactive</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button variant="outline" onClick={handleSearch}>
                                Search
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {vendors.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Building2 className="h-12 w-12 text-muted-foreground/30 mb-4" />
                                <h3 className="text-lg font-medium mb-1">No vendors found</h3>
                                <p className="text-muted-foreground mb-4">
                                    Get started by adding your first vendor.
                                </p>
                                {canManage && (
                                    <Button onClick={() => setNewVendorOpen(true)}>
                                        <Plus className="w-4 h-4 mr-2" />
                                        Add Vendor
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Trading Name</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Phone</TableHead>
                                        <TableHead className="text-center">Bills</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {vendors.data.map((vendor) => (
                                        <TableRow key={vendor.id} onContextMenu={rowMenu.open(rowMenuItems(vendor))}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/vendors/${vendor.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {vendor.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {vendor.trading_name || '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="secondary"
                                                    className={vendorTypeColors[vendor.vendor_type] || ''}
                                                >
                                                    {vendorTypeLabels[vendor.vendor_type] || vendor.vendor_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {vendor.email || '-'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {vendor.phone || '-'}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {vendor.bills_count}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={vendor.is_active ? 'default' : 'secondary'}
                                                    className={
                                                        vendor.is_active
                                                            ? 'bg-status-success-bg text-status-success'
                                                            : 'bg-muted text-muted-foreground'
                                                    }
                                                >
                                                    {vendor.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {vendors.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(vendors.current_page - 1) * vendors.per_page + 1} to{' '}
                            {Math.min(vendors.current_page * vendors.per_page, vendors.total)} of{' '}
                            {vendors.total} vendors
                        </p>
                        <div className="flex gap-1">
                            {vendors.links.map((link, i) => (
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

                {rowMenu.element}
            </PageLayout>

            {canManage && (
                <NewVendorDialog
                    open={newVendorOpen}
                    onClose={() => setNewVendorOpen(false)}
                    expenseAccounts={expenseAccounts}
                />
            )}
        </AppLayout>
    );
}
