import { CompensationHero, CompensationTabs, type CompensationHeroStats } from '@/components/hr';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
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
import { Eye, Plus, Receipt } from 'lucide-react';

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
    stats: CompensationHeroStats;
    can: { create: boolean; manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/compensation/expenses' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
    submitted: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Approved',
    },
    rejected: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Rejected',
    },
    paid: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'Paid',
    },
};

const formatCurrency = (amount: number, currency = 'NZD') => {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
    }).format(amount);
};

export default function ExpenseIndex({ claims, filters, stats, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/expenses',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense Claims" />

            <PageLayout hero={<CompensationHero stats={stats} />}>
                <CompensationTabs active="expenses" />

                {can.create ? (
                    <div className="flex justify-end">
                        <Button asChild size="sm">
                            <Link href="/hr/compensation/expenses/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New claim
                            </Link>
                        </Button>
                    </div>
                ) : null}

                {/* Filters */}
                <div className="flex flex-wrap gap-2">
                    {[
                        'all',
                        'draft',
                        'submitted',
                        'approved',
                        'rejected',
                        'paid',
                    ].map((s) => (
                        <Button
                            key={s}
                            variant={
                                (!filters.status && s === 'all') ||
                                filters.status === s
                                    ? 'default'
                                    : 'outline'
                            }
                            size="sm"
                            onClick={() =>
                                onFilter({ status: s === 'all' ? null : s })
                            }
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
                                    {can.manage && (
                                        <TableHead>Employee</TableHead>
                                    )}
                                    <TableHead>Items</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead className="w-16" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {claims.data.map((claim) => {
                                    const config =
                                        statusConfig[claim.status] ||
                                        statusConfig.draft;
                                    return (
                                        <TableRow key={claim.id}>
                                            <TableCell className="font-mono text-sm">
                                                {claim.claim_number}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {claim.title}
                                            </TableCell>
                                            {can.manage && (
                                                <TableCell className="text-muted-foreground">
                                                    {claim.staff_name}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                {claim.items_count}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(
                                                    claim.total_amount,
                                                    claim.currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {claim.submitted_at || '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/compensation/expenses/${claim.id}`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {claims.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={can.manage ? 8 : 7}
                                            className="py-8 text-center text-muted-foreground"
                                        >
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
            </PageLayout>
        </AppLayout>
    );
}
