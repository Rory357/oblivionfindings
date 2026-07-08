import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { PayablesTabsFooter, formatMoney, useRowContextMenu, type RowCtxItem } from '@/components/finance';
import { StatusBadge } from '@/components/ui/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, Plus, Send, Download, Eye } from 'lucide-react';

type PaymentRun = {
    id: number;
    run_number: string;
    payment_date: string;
    bank_account: { id: number; name: string; bank_name: string } | null;
    item_count: number;
    total_amount: number;
    status: string;
    processed_at: string | null;
};

type PaginatedData<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type PageProps = {
    paymentRuns: PaginatedData<PaymentRun>;
    filters: { status: string };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Payment Runs', href: '/finance/payment-runs' },
];

export default function PaymentRunsIndex({ paymentRuns, filters }: PageProps) {
    const handleStatusFilter = (value: string) => {
        router.get(
            '/finance/payment-runs',
            { status: value === 'all' ? '' : value },
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        router.get('/finance/payment-runs', {}, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.status);

    const completedCount = paymentRuns.data.filter((r) => r.status === 'completed').length;
    const processingCount = paymentRuns.data.filter((r) => r.status === 'processing').length;
    const draftCount = paymentRuns.data.filter((r) => r.status === 'draft').length;

    // Right-click row menu — mirrors the row's only inline action: opening the
    // payment run (the row onClick navigates to the show route). Approve/Process
    // live on the detail page behind confirm dialogs, not as inline row actions.
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (run: PaymentRun): RowCtxItem[] => [
        { kind: 'item', label: 'Open', icon: Eye, onSelect: () => router.visit(`/finance/payment-runs/${run.id}`) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment Runs" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Send}
                        title="Payment Runs"
                        description="Manage batch payments to vendors"
                        stats={[
                            { label: 'Total', value: paymentRuns.total },
                            { label: 'Completed', value: completedCount },
                            { label: 'Processing', value: processingCount },
                            { label: 'Drafts', value: draftCount },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/finance/payment-runs/export?${new URLSearchParams(Object.entries({ status: filters.status }).filter(([, v]) => v)).toString()}`}>
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                <Link href="/finance/payment-runs/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Payment Run
                                    </Button>
                                </Link>
                            </div>
                        }
                        footer={<PayablesTabsFooter active="payment-runs" />}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Banknote className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Payment Runs</CardTitle>
                            </div>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={handleStatusFilter}
                            >
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="Filter by status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {paymentRuns.data.length === 0 ? (
                            hasFilters ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No payment runs match your filters"
                                    className="border-0"
                                />
                            ) : (
                                <EmptyList
                                    icon={Banknote}
                                    itemName="payment run"
                                    title="No payment runs yet"
                                    description="Payment runs let you batch payments to vendors. Create one to get started."
                                    className="border-0"
                                    action={
                                        <Link href="/finance/payment-runs/create">
                                            <Button size="sm">New payment run</Button>
                                        </Link>
                                    }
                                />
                            )
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Run #</TableHead>
                                            <TableHead>Payment Date</TableHead>
                                            <TableHead>Bank Account</TableHead>
                                            <TableHead className="text-center">Items</TableHead>
                                            <TableHead className="text-right">Total Amount</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Processed At</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {paymentRuns.data.map((run) => {
                                            return (
                                                <TableRow
                                                    key={run.id}
                                                    className="cursor-pointer hover:bg-muted/50"
                                                    onClick={() => router.visit(`/finance/payment-runs/${run.id}`)}
                                                    onContextMenu={rowMenu.open(rowMenuItems(run))}
                                                >
                                                    <TableCell className="font-mono font-medium">
                                                        {run.run_number}
                                                    </TableCell>
                                                    <TableCell>{run.payment_date}</TableCell>
                                                    <TableCell>
                                                        {run.bank_account
                                                            ? `${run.bank_account.name} (${run.bank_account.bank_name})`
                                                            : '-'}
                                                    </TableCell>
                                                    <TableCell className="text-center">{run.item_count}</TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums">
                                                        {formatMoney(run.total_amount)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={run.status} />
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {run.processed_at || '-'}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>

                                {paymentRuns.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-center gap-1">
                                        {paymentRuns.links.map((link, i) => (
                                            <Button
                                                key={i}
                                                variant={link.active ? 'default' : 'outline'}
                                                size="sm"
                                                disabled={!link.url}
                                                onClick={() => {
                                                    if (link.url) {
                                                        router.visit(link.url);
                                                    }
                                                }}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                {rowMenu.element}
            </PageLayout>
        </AppLayout>
    );
}
