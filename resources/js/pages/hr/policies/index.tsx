import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Search, Plus, CheckCircle, ShieldCheck } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

type BreadcrumbItem = { title: string; href: string };

type Policy = {
    id: number;
    title: string;
    slug: string;
    category: string;
    is_active: boolean;
    requires_attestation: boolean;
    current_version: {
        id: number;
        version_number: string;
        effective_from: string;
    } | null;
};

type Props = {
    policies: {
        data: Policy[];
        links: any[];
    };
    categories: string[];
    filters: {
        category: string | null;
        active_only: boolean | string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Policies', href: '/hr/policies' },
];

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const getCategoryColor = (category: string) => {
    const colors: Record<string, string> = {
        'employment': 'bg-blue-100 text-blue-800 border-blue-200',
        'health_and_safety': 'bg-green-100 text-green-800 border-green-200',
        'safeguarding': 'bg-primary/10 text-primary border-primary',
        'data_protection': 'bg-amber-100 text-amber-800 border-amber-200',
        'conduct': 'bg-red-100 text-red-800 border-red-200',
        'leave': 'bg-teal-100 text-teal-800 border-teal-200',
        'training': 'bg-primary/10 text-primary border-primary',
        'general': 'bg-muted text-foreground border-border',
    };
    return colors[category] || 'bg-muted text-foreground border-border';
};

export default function PoliciesIndex({ policies, categories, filters, can }: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/policies', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Policy Library" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Policy Library</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Organisation policies, procedures, and staff attestations
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/hr/policies/attestations">
                            <Button size="sm" variant="outline">
                                <ShieldCheck className="mr-1.5 h-4 w-4" />
                                Attestations
                            </Button>
                        </Link>
                        {can.manage && (
                            <Link href="/hr/policies/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Create Policy
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">Category</Label>
                            <Select
                                value={filters.category ?? NONE}
                                onValueChange={(v) => onFilter({ category: v === NONE ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="All categories" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Categories</SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem key={c} value={c} className="capitalize">
                                            {c.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select
                                value={filters.active_only === true || filters.active_only === 'true' ? 'active' : filters.active_only === false || filters.active_only === 'false' ? 'inactive' : NONE}
                                onValueChange={(v) => {
                                    if (v === NONE) onFilter({ active_only: null });
                                    else if (v === 'active') onFilter({ active_only: 'true' });
                                    else onFilter({ active_only: 'false' });
                                }}
                            >
                                <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All</SelectItem>
                                    <SelectItem value="active">Active Only</SelectItem>
                                    <SelectItem value="inactive">Inactive Only</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Policy</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Current Version</TableHead>
                                    <TableHead>Effective From</TableHead>
                                    <TableHead>Attestation</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {policies.data.map((policy) => (
                                    <TableRow key={policy.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <FileText className="h-4 w-4 text-muted-foreground" />
                                                <span className="font-medium">{policy.title}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={getCategoryColor(policy.category)}>
                                                {policy.category.replace(/_/g, ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {policy.is_active ? (
                                                <Badge className="bg-green-100 text-green-800 border-green-200">
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="text-muted-foreground">
                                                    Inactive
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {policy.current_version ? (
                                                <span className="text-sm font-medium">
                                                    v{policy.current_version.version_number}
                                                </span>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">No version</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {policy.current_version
                                                ? formatDate(policy.current_version.effective_from)
                                                : '--'}
                                        </TableCell>
                                        <TableCell>
                                            {policy.requires_attestation ? (
                                                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                                    Required
                                                </Badge>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">Not required</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/policies/${policy.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!policies.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-sm text-muted-foreground">
                                            No policies found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {policies?.links?.length ? (
                    <LaravelPagination links={policies.links} />
                ) : null}
            </div>
        </AppLayout>
    );
}
