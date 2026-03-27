import { Head, Link, router } from '@inertiajs/react';
import { type PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Search, Building2 } from 'lucide-react';
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
    contractor: 'bg-purple-100 text-purple-800',
    utility: 'bg-amber-100 text-amber-800',
    government: 'bg-teal-100 text-teal-800',
    other: 'bg-gray-100 text-gray-800',
};

export default function VendorsIndex({ vendors, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Vendors', href: '/finance/vendors' },
            ]}
        >
            <Head title="Vendors" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Vendors</h1>
                        <p className="text-gray-500 mt-1">
                            Manage your suppliers, contractors and service providers
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/vendors/create">
                            <Plus className="w-4 h-4 mr-2" />
                            Add Vendor
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="flex flex-col sm:flex-row gap-4">
                            <div className="flex-1">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
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
                                <Building2 className="h-12 w-12 text-gray-300 mb-4" />
                                <h3 className="text-lg font-medium text-gray-900 mb-1">No vendors found</h3>
                                <p className="text-gray-500 mb-4">
                                    Get started by adding your first vendor.
                                </p>
                                <Button asChild>
                                    <Link href="/finance/vendors/create">
                                        <Plus className="w-4 h-4 mr-2" />
                                        Add Vendor
                                    </Link>
                                </Button>
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
                                        <TableRow key={vendor.id}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/vendors/${vendor.id}`}
                                                    className="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                                                >
                                                    {vendor.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-gray-500">
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
                                            <TableCell className="text-gray-500">
                                                {vendor.email || '-'}
                                            </TableCell>
                                            <TableCell className="text-gray-500">
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
                                                            ? 'bg-green-100 text-green-800'
                                                            : 'bg-gray-100 text-gray-600'
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
                    <div className="flex items-center justify-between mt-4">
                        <p className="text-sm text-gray-500">
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
            </div>
        </AppLayout>
    );
}
