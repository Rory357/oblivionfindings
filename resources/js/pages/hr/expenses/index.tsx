import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import { Plus, Eye } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

type ExpenseClaim = {
    id: number;
    claim_number: string;
    title: string;
    staff_name: string;
    status: string;
    total_amount: number;
    currency: string;
    items_count: number;
    submitted_at: string | null;
    created_at: string;
};

type Props = {
    claims: {
        data: ExpenseClaim[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: string | null; q: string };
    can: { create: boolean; manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/expenses' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-slate-500/30 text-muted-foreground bg-slate-500/10', label: 'Draft' },
    submitted: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Submitted' },
    approved: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
    rejected: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Rejected' },
    paid: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Paid' },
};

const formatCurrency = (amount: number, currency = 'NZD') => {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(amount);
};

export default function ExpenseIndex({ claims, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/expenses', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense Claims" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Expense Claims</h1>
                        <p className="text-sm text-muted-foreground">Manage employee expense claims and reimbursements</p>
                    </div>
                    {can.create && (
                        <Button asChild size="sm">
                            <Link href="/hr/expenses/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Claim
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-2">
                    {['all', 'draft', 'submitted', 'approved', 'rejected', 'paid'].map((s) => (
                        <Button
                            key={s}
                            variant={(!filters.status && s === 'all') || filters.status === s ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => onFilter({ status: s === 'all' ? null : s })}
                        >
                            <span className="capitalize">{s}</span>
                        </Button>
                    ))}
                    {can.manage && (
                        <Input
                            placeholder="Search by name..."
                            value={filters.q || ''}
                            onChange={(e) => onFilter({ q: e.target.value })}
                            className="ml-auto w-56"
                        />
                    )}
                </div>

                {/* Claims Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Claim #</TableHead>
                                    <TableHead>Title</TableHead>
                                    {can.manage && <TableHead>Employee</TableHead>}
                                    <TableHead>Items</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead className="w-16" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {claims.data.map((claim) => {
                                    const config = statusConfig[claim.status] || statusConfig.draft;
                                    return (
                                        <TableRow key={claim.id}>
                                            <TableCell className="font-mono text-sm">{claim.claim_number}</TableCell>
                                            <TableCell className="font-medium">{claim.title}</TableCell>
                                            {can.manage && <TableCell className="text-muted-foreground">{claim.staff_name}</TableCell>}
                                            <TableCell>{claim.items_count}</TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(claim.total_amount, claim.currency)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{claim.submitted_at || '-'}</TableCell>
                                            <TableCell>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/hr/expenses/${claim.id}`}>
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {claims.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={can.manage ? 8 : 7} className="py-8 text-center text-muted-foreground">
                                            No expense claims found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {claims.links?.length > 3 && (
                    <LaravelPagination links={claims.links} />
                )}
            </div>
        </AppLayout>
    );
}
