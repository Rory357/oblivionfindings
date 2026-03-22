import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Package } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

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
    filters: { status: string | null; category: string | null; search: string | null };
    categories: SelectOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Assets', href: '/hr/assets' },
];

const statusColors: Record<string, string> = {
    available: 'bg-green-100 text-green-800',
    assigned: 'bg-blue-100 text-blue-800',
    maintenance: 'bg-yellow-100 text-yellow-800',
    retired: 'bg-slate-100 text-slate-800',
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
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(num);
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

export default function AssetsIndex({ assets, filters, categories, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/assets', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assets" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Asset Management</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Track company assets and their assignments
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" asChild>
                            <Link href="/hr/assets/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Asset
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search by name, tag, serial..."
                                value={filters.search || ''}
                                onChange={(e) => onFilter({ search: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Category</Label>
                            <Select
                                value={filters.category || 'all'}
                                onValueChange={(val) => onFilter({ category: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    {categories.map((cat) => (
                                        <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) => onFilter({ status: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="available">Available</SelectItem>
                                    <SelectItem value="assigned">Assigned</SelectItem>
                                    <SelectItem value="maintenance">Maintenance</SelectItem>
                                    <SelectItem value="retired">Retired</SelectItem>
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
                                        onClick={() => router.get(`/hr/assets/${asset.id}`)}
                                    >
                                        <TableCell className="font-mono text-xs font-medium">{asset.asset_tag}</TableCell>
                                        <TableCell className="font-medium">{asset.name}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{categoryLabels[asset.category] || asset.category}</Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-slate-600">
                                            {[asset.make, asset.model].filter(Boolean).join(' ') || '-'}
                                        </TableCell>
                                        <TableCell>
                                            {asset.current_assignment?.employee_profile?.user?.name || '-'}
                                        </TableCell>
                                        <TableCell className="text-sm">{formatCurrency(asset.purchase_cost)}</TableCell>
                                        <TableCell>
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[asset.status] ?? ''}`}>
                                                {asset.status}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!assets.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">
                                            No assets found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {assets?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {assets.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
