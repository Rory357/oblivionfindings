import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Plus, Search, Package, DollarSign, TrendingDown, Calculator, Hash } from 'lucide-react';
import { useState, useCallback, FormEvent } from 'react';

interface FixedAsset {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    category: string;
    purchase_date: string;
    purchase_cost: string;
    accumulated_depreciation: string;
    residual_value: string;
    useful_life_months: number;
    depreciation_method: string;
    status: string;
}

interface PaginatedAssets {
    data: FixedAsset[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Summary {
    total_count: number;
    total_cost: number;
    total_depreciation: number;
    net_book_value: number;
    active_count: number;
}

interface Filters {
    category: string;
    status: string;
    search: string;
}

interface Props {
    assets: PaginatedAssets;
    summary: Summary;
    filters: Filters;
}

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const categoryLabels: Record<string, string> = {
    vehicle: 'Vehicle',
    equipment: 'Equipment',
    building: 'Building',
    furniture: 'Furniture',
    it_equipment: 'IT Equipment',
    land: 'Land',
};

const categoryColors: Record<string, string> = {
    vehicle: 'bg-status-info-bg text-status-info',
    equipment: 'bg-primary/10 text-primary',
    building: 'bg-status-warning-bg text-status-warning',
    furniture: 'bg-status-info-bg text-status-info',
    it_equipment: 'bg-primary/10 text-primary',
    land: 'bg-status-success-bg text-status-success',
};

const statusLabels: Record<string, string> = {
    active: 'Active',
    fully_depreciated: 'Fully Depreciated',
    disposed: 'Disposed',
};

const statusColors: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    fully_depreciated: 'bg-status-warning-bg text-status-warning',
    disposed: 'bg-muted text-muted-foreground',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Fixed Assets', href: '/finance/fixed-assets' },
];

export default function FixedAssetsIndex({ assets, summary, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [depModalOpen, setDepModalOpen] = useState(false);

    const depForm = useForm({
        depreciation_date: new Date().toISOString().split('T')[0],
    });

    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/fixed-assets',
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

    function handleRunDepreciation(e: FormEvent) {
        e.preventDefault();
        depForm.post('/finance/fixed-assets/run-depreciation', {
            onSuccess: () => setDepModalOpen(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fixed Assets" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Package}
                        title="Fixed Assets"
                        description="Manage your organisation's fixed asset register"
                        stats={[
                            { label: 'Total assets', value: summary.total_count },
                            { label: 'Total cost', value: formatNZD(summary.total_cost) },
                            { label: 'Depreciation', value: formatNZD(summary.total_depreciation) },
                            { label: 'Book value', value: formatNZD(summary.net_book_value) },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Dialog open={depModalOpen} onOpenChange={setDepModalOpen}>
                                    <DialogTrigger asChild>
                                        <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                            <Calculator className="mr-1.5 h-4 w-4" />
                                            Run Depreciation
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Run Depreciation</DialogTitle>
                                            <DialogDescription>
                                                Process monthly depreciation for all active assets. This will create depreciation
                                                records and post GL journals.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={handleRunDepreciation}>
                                            <div className="space-y-4 py-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="depreciation_date">Depreciation Date</Label>
                                                    <Input
                                                        id="depreciation_date"
                                                        type="date"
                                                        value={depForm.data.depreciation_date}
                                                        onChange={(e) => depForm.setData('depreciation_date', e.target.value)}
                                                    />
                                                    {depForm.errors.depreciation_date && (
                                                        <p className="text-sm text-destructive">{depForm.errors.depreciation_date}</p>
                                                    )}
                                                </div>
                                            </div>
                                            <DialogFooter>
                                                <Button type="button" variant="outline" onClick={() => setDepModalOpen(false)}>
                                                    Cancel
                                                </Button>
                                                <Button type="submit" disabled={depForm.processing}>
                                                    {depForm.processing ? 'Processing...' : 'Run Depreciation'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                                <Link href={'/finance/fixed-assets/create'}>
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Asset
                                    </Button>
                                </Link>
                            </div>
                        }
                    />
                }
            >
                {/* Summary Cards - 4 KPIs */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <Hash className="h-5 w-5 text-primary" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Assets</p>
                                    <p className="text-2xl font-bold">{summary.total_count}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info p-2">
                                    <DollarSign className="h-5 w-5 text-status-info" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Cost</p>
                                    <p className="text-2xl font-bold font-mono tabular-nums">
                                        {formatNZD(summary.total_cost)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2">
                                    <TrendingDown className="h-5 w-5 text-status-warning" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Depreciation</p>
                                    <p className="text-2xl font-bold font-mono tabular-nums">
                                        {formatNZD(summary.total_depreciation)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success p-2">
                                    <Package className="h-5 w-5 text-status-success" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Net Book Value</p>
                                    <p className="text-2xl font-bold font-mono tabular-nums">
                                        {formatNZD(summary.net_book_value)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-col sm:flex-row gap-4">
                            <div className="flex-1">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search by name or tag..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={handleSearchKeyDown}
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <Select
                                value={filters.category || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ category: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All Categories" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    <SelectItem value="vehicle">Vehicle</SelectItem>
                                    <SelectItem value="equipment">Equipment</SelectItem>
                                    <SelectItem value="building">Building</SelectItem>
                                    <SelectItem value="furniture">Furniture</SelectItem>
                                    <SelectItem value="it_equipment">IT Equipment</SelectItem>
                                    <SelectItem value="land">Land</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ status: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="fully_depreciated">Fully Depreciated</SelectItem>
                                    <SelectItem value="disposed">Disposed</SelectItem>
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
                        {assets.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Package className="h-12 w-12 text-muted-foreground/30 mb-4" />
                                <h3 className="text-lg font-medium mb-1">No fixed assets found</h3>
                                <p className="text-muted-foreground mb-4">
                                    Get started by adding your first fixed asset.
                                </p>
                                <Link href={'/finance/fixed-assets/create'}>
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Asset
                                    </Button>
                                </Link>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Tag</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Purchase Date</TableHead>
                                        <TableHead className="text-right">Cost</TableHead>
                                        <TableHead className="text-right">Accum. Depr.</TableHead>
                                        <TableHead className="text-right">Book Value</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {assets.data.map((asset) => {
                                        const bookValue = Number(asset.purchase_cost) - Number(asset.accumulated_depreciation);
                                        return (
                                            <TableRow key={asset.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/finance/fixed-assets/${asset.id}`}
                                                        className="font-medium text-primary hover:underline"
                                                    >
                                                        {asset.asset_name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground font-mono text-sm">
                                                    {asset.asset_tag || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="secondary"
                                                        className={categoryColors[asset.category] || ''}
                                                    >
                                                        {categoryLabels[asset.category] || asset.category}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {new Date(asset.purchase_date).toLocaleDateString('en-NZ')}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-sm">
                                                    {formatNZD(asset.purchase_cost)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-sm">
                                                    {formatNZD(asset.accumulated_depreciation)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-sm font-medium">
                                                    {formatNZD(bookValue)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="secondary"
                                                        className={statusColors[asset.status] || ''}
                                                    >
                                                        {statusLabels[asset.status] || asset.status}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {assets.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(assets.current_page - 1) * assets.per_page + 1} to{' '}
                            {Math.min(assets.current_page * assets.per_page, assets.total)} of{' '}
                            {assets.total} assets
                        </p>
                        <div className="flex gap-1">
                            {assets.links.map((link, i) => (
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
            </PageLayout>
        </AppLayout>
    );
}
