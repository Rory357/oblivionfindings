import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Plus, TrendingUp, Trash2, FileBarChart } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

type Forecast = {
    id: number;
    name: string;
    forecast_date: string;
    period_start: string;
    period_end: string;
    period_type: string;
    opening_balance: string;
    status: string;
    scenarios_count: number;
    created_by: { id: number; name: string } | null;
    created_at: string;
};

type PaginatedData = {
    data: Forecast[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
};

type PageProps = {
    forecasts: PaginatedData;
};

const formatNZD = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const periodTypeLabels: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
};

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground border-border' },
    final: { label: 'Final', className: 'bg-green-100 text-green-700 border-green-300' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Cash Flow Forecast', href: '/finance/cash-flow-forecast' },
];

export default function CashFlowForecastIndex({ forecasts }: PageProps) {
    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this forecast?')) {
            router.delete(`/finance/cash-flow-forecast/${id}`);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash Flow Forecast" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Cash Flow Forecast</h1>
                        <p className="text-muted-foreground">
                            Project future cash positions based on outstanding invoices, bills, and recurring transactions
                        </p>
                    </div>
                    <Link href="/finance/cash-flow-forecast/create">
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            New Forecast
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Forecasts</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {forecasts.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 text-center">
                                <div className="rounded-full bg-muted p-4 mb-4">
                                    <FileBarChart className="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 className="text-lg font-semibold">No forecasts yet</h3>
                                <p className="text-muted-foreground mt-1 max-w-sm">
                                    Create your first cash flow forecast to project future cash positions and plan ahead.
                                </p>
                                <Link href="/finance/cash-flow-forecast/create" className="mt-4">
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        New Forecast
                                    </Button>
                                </Link>
                            </div>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Period</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead className="text-right">Opening Balance</TableHead>
                                            <TableHead>Scenarios</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Created</TableHead>
                                            <TableHead className="w-12"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {forecasts.data.map((forecast) => {
                                            const status = statusConfig[forecast.status] ?? statusConfig.draft;
                                            return (
                                                <TableRow
                                                    key={forecast.id}
                                                    className="cursor-pointer"
                                                    onClick={() => router.visit(`/finance/cash-flow-forecast/${forecast.id}`)}
                                                >
                                                    <TableCell className="font-medium">{forecast.name}</TableCell>
                                                    <TableCell>
                                                        {formatDate(forecast.period_start)} &ndash;{' '}
                                                        {formatDate(forecast.period_end)}
                                                    </TableCell>
                                                    <TableCell>
                                                        {periodTypeLabels[forecast.period_type] ?? forecast.period_type}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums">
                                                        {formatNZD(forecast.opening_balance)}
                                                    </TableCell>
                                                    <TableCell>{forecast.scenarios_count}</TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className={status.className}>
                                                            {status.label}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        <div>{formatDate(forecast.forecast_date)}</div>
                                                        {forecast.created_by && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {forecast.created_by.name}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {forecast.status === 'draft' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    handleDelete(forecast.id);
                                                                }}
                                                            >
                                                                <Trash2 className="h-4 w-4 text-destructive" />
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>

                                {forecasts.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-center gap-1">
                                        {forecasts.links.map((link, i) => (
                                            <Button
                                                key={i}
                                                variant={link.active ? 'default' : 'outline'}
                                                size="sm"
                                                disabled={!link.url}
                                                onClick={() => link.url && router.visit(link.url)}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
