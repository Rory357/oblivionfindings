import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TrendingUp, CalendarRange, Info } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Cash Flow Forecast', href: '/finance/cash-flow-forecast' },
    { title: 'New Forecast', href: '/finance/cash-flow-forecast/create' },
];

export default function CashFlowForecastCreate() {
    const today = new Date();
    const threeMonthsLater = new Date(today);
    threeMonthsLater.setMonth(threeMonthsLater.getMonth() + 3);

    const { data, setData, post, processing, errors } = useForm({
        period_start: today.toISOString().split('T')[0],
        period_end: threeMonthsLater.toISOString().split('T')[0],
        period_type: 'weekly' as string,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/finance/cash-flow-forecast');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Cash Flow Forecast" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/cash-flow-forecast"
                        title="New Cash Flow Forecast"
                        description="Select a forecast period and granularity. The system will project cash flows based on outstanding invoices, bills, recurring transactions, and bank balances."
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <CalendarRange className="h-5 w-5 text-muted-foreground" />
                            <div>
                                <CardTitle>Forecast Parameters</CardTitle>
                                <CardDescription>Define the date range and period granularity for your forecast.</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="period_start">Period Start</Label>
                                    <Input
                                        id="period_start"
                                        type="date"
                                        value={data.period_start}
                                        onChange={(e) => setData('period_start', e.target.value)}
                                    />
                                    {errors.period_start && (
                                        <p className="text-sm text-destructive">{errors.period_start}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="period_end">Period End</Label>
                                    <Input
                                        id="period_end"
                                        type="date"
                                        value={data.period_end}
                                        onChange={(e) => setData('period_end', e.target.value)}
                                    />
                                    {errors.period_end && (
                                        <p className="text-sm text-destructive">{errors.period_end}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="period_type">Period Granularity</Label>
                                <Select
                                    value={data.period_type}
                                    onValueChange={(v) => setData('period_type', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select granularity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="weekly">Weekly</SelectItem>
                                        <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                        <SelectItem value="monthly">Monthly</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.period_type && (
                                    <p className="text-sm text-destructive">{errors.period_type}</p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Weekly gives the most granular view; monthly is best for longer-range forecasts.
                                </p>
                            </div>

                            <div className="rounded-lg border bg-muted/50 p-4 text-sm">
                                <div className="flex items-start gap-2">
                                    <Info className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                                    <div>
                                        <p className="font-medium text-foreground mb-2">What will be included:</p>
                                        <ul className="list-disc list-inside space-y-1 text-muted-foreground">
                                            <li>Current bank account balances as opening position</li>
                                            <li>Outstanding invoice receipts (accounts receivable)</li>
                                            <li>Upcoming bill payments (accounts payable)</li>
                                            <li>Recurring journal entries (income and expenses)</li>
                                            <li>GST payment obligations</li>
                                            <li>Three scenarios: Base Case, Best Case, Worst Case</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end gap-3 pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <TrendingUp className="mr-2 h-4 w-4" />
                                    {processing ? 'Generating...' : 'Generate Forecast'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
