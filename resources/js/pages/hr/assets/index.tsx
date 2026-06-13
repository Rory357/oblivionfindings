import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { Head, Link, router } from '@inertiajs/react';
import { Box, Plus } from 'lucide-react';

interface SelectOption {
    value: string;
    label: string;
}

interface Asset {
    id: number;
    asset_tag: string;
    name: string;
    category: string;
    serial_number: string | null;
    make: string | null;
    model: string | null;
    status: string;
    purchase_cost: string | null;
    warranty_expiry: string | null;
    current_assignment: {
        id: number;
        employee_profile: {
            user: { id: number; name: string };
        };
    } | null;
}

interface Props {
    assets: { data: Asset[]; links: any[] };
    filters: {
        status: string | null;
        category: string | null;
        search: string | null;
    };
    categories: SelectOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Assets', href: '/hr/assets' },
];

const statusColors: Record<string, string> = {
    available: 'bg-status-success-bg text-status-success',
    assigned: 'bg-status-info-bg text-status-info',
    maintenance: 'bg-status-warning-bg text-status-warning',
    retired: 'bg-muted text-foreground',
};

const categoryLabels: Record<string, string> = {
    laptop: 'Laptop',
    phone: 'Phone',
    tablet: 'Tablet',
    vehicle: 'Vehicle',
    key: 'Key',
    card: 'Card',
    uniform: 'Uniform',
    other: 'Other',
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(num);
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

export default function AssetsIndex({
    assets,
    filters,
    categories,
    can,
}: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/assets',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assets" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Box}
                        title="Asset Management"
                        description="Track company assets and their assignments"
                        stats={[
                            { label: 'Total', value: assets.data.length },
                            {
                                label: 'Assigned',
                                value: assets.data.filter((a) => a.status === 'assigned').length,
                            },
                            {
                                label: 'Available',
                                value: assets.data.filter((a) => a.status === 'available').length,
                            },
                        ]}
                        actions={
                            can.manage && (
                                <Button size="sm" asChild>
                                    <Link href="/hr/assets/create">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Asset
                                    </Link>
                                </Button>
                            )
                        }
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <Input
                                placeholder="Search by name, tag, serial..."
                                value={filters.search || ''}
                                onChange={(e) =>
                                    onFilter({ search: e.target.value || null })
                                }
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Category
                            </Label>
                            <Select
                                value={filters.category || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        category: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Categories
                                    </SelectItem>
                                    {categories.map((cat) => (
                                        <SelectItem
                                            key={cat.value}
                                            value={cat.value}
                                        >
                                            {cat.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        status: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="available">
                                        Available
                                    </SelectItem>
                                    <SelectItem value="assigned">
                                        Assigned
                                    </SelectItem>
                                    <SelectItem value="maintenance">
                                        Maintenance
                                    </SelectItem>
                                    <SelectItem value="retired">
                                        Retired
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Assets Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Asset Tag</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Make / Model</TableHead>
                                    <TableHead>Assigned To</TableHead>
                                    <TableHead>Value</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {assets.data.map((asset) => (
                                    <TableRow
                                        key={asset.id}
                                        className="cursor-pointer hover:bg-muted/50"
                                        onClick={() =>
                                            router.get(`/hr/assets/${asset.id}`)
                                        }
                                    >
                                        <TableCell className="font-mono text-xs font-medium">
                                            {asset.asset_tag}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {asset.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {categoryLabels[
                                                    asset.category
                                                ] || asset.category}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {[asset.make, asset.model]
                                                .filter(Boolean)
                                                .join(' ') || '-'}
                                        </TableCell>
                                        <TableCell>
                                            {asset.current_assignment
                                                ?.employee_profile?.user
                                                ?.name || '-'}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatCurrency(
                                                asset.purchase_cost,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[asset.status] ?? ''}`}
                                            >
                                                {asset.status}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!assets.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No assets found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {assets?.links?.length ? (
                    <LaravelPagination links={assets.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
