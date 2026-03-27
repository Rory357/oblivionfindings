import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Plus, TrendingUp, Trash2 } from 'lucide-react';

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
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-700 border-gray-300' },
    final: { label: 'Final', className: 'bg-green-100 text-green-700 border-green-300' },
};

export default function CashFlowForecastIndex({ forecasts }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Cash Flow Forecast', href: '/finance/cash-flow-forecast' },
    ];

    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this forecast?')) {
            router.delete(`/finance/cash-flow-forecast/${id}`);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash Flow Forecast" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
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
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Name</th>
                                        <th className="pb-3 pr-4 font-medium">Period</th>
                                        <th className="pb-3 pr-4 font-medium">Type</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Opening Balance</th>
                                        <th className="pb-3 pr-4 font-medium">Scenarios</th>
                                        <th className="pb-3 pr-4 font-medium">Status</th>
                                        <th className="pb-3 font-medium">Created</th>
                                        <th className="pb-3 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {forecasts.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No forecasts yet. Create your first cash flow forecast to get started.
                                            </td>
                                        </tr>
                                    ) : (
                                        forecasts.data.map((forecast) => {
                                            const status = statusConfig[forecast.status] ?? statusConfig.draft;
                                            return (
                                                <tr
                                                    key={forecast.id}
                                                    className="border-b last:border-0 hover:bg-muted/50 cursor-pointer"
                                                    onClick={() => router.visit(`/finance/cash-flow-forecast/${forecast.id}`)}
                                                >
                                                    <td className="py-3 pr-4 font-medium">{forecast.name}</td>
                                                    <td className="py-3 pr-4">
                                                        {formatDate(forecast.period_start)} &ndash;{' '}
                                                        {formatDate(forecast.period_end)}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {periodTypeLabels[forecast.period_type] ?? forecast.period_type}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatNZD(forecast.opening_balance)}
                                                    </td>
                                                    <td className="py-3 pr-4">{forecast.scenarios_count}</td>
                                                    <td className="py-3 pr-4">
                                                        <Badge variant="outline" className={status.className}>
                                                            {status.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-3 pr-4 whitespace-nowrap">
                                                        <div>{formatDate(forecast.forecast_date)}</div>
                                                        {forecast.created_by && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {forecast.created_by.name}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="py-3">
                                                        {forecast.status === 'draft' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    handleDelete(forecast.id);
                                                                }}
                                                            >
                                                                <Trash2 className="h-4 w-4 text-destructive" />
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

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
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
