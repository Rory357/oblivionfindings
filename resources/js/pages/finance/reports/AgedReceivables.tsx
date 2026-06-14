import { Head } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Printer, DollarSign, CheckCircle, AlertTriangle, ArrowUpFromLine } from 'lucide-react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { useMemo } from 'react';

interface AgedRow {
    client_name: string;
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    days_90_plus: number;
    total: number;
}

interface GrandTotal {
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    days_90_plus: number;
    total: number;
}

interface Report {
    rows: AgedRow[];
    grand_total: GrandTotal;
}

interface Props extends PageProps {
    report: Report;
}

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const agingColumns = [
    { key: 'current' as const, label: 'Current', className: 'text-status-success dark:text-status-success' },
    { key: 'days_1_30' as const, label: '1-30 Days', className: 'text-status-warning dark:text-status-warning' },
    { key: 'days_31_60' as const, label: '31-60 Days', className: 'text-status-warning dark:text-status-warning' },
    { key: 'days_61_90' as const, label: '61-90 Days', className: 'text-status-critical dark:text-status-critical' },
    { key: 'days_90_plus' as const, label: '90+ Days', className: 'text-status-critical dark:text-status-critical' },
];

const PIE_COLORS = ['#10b981', '#f59e0b', '#f97316', '#ef4444', '#991b1b'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Reports', href: '/finance/reports/aged-receivables' },
    { title: 'Aged Receivables', href: '/finance/reports/aged-receivables' },
];

export default function AgedReceivables({ report }: Props) {
    const { grand_total } = report;

    const currentPct = grand_total.total > 0
        ? ((grand_total.current / grand_total.total) * 100).toFixed(1)
        : '0.0';

    const overdueAmount = grand_total.days_31_60 + grand_total.days_61_90 + grand_total.days_90_plus;

    const pieData = useMemo(() => [
        { name: 'Current', value: grand_total.current },
        { name: '1-30 Days', value: grand_total.days_1_30 },
        { name: '31-60 Days', value: grand_total.days_31_60 },
        { name: '61-90 Days', value: grand_total.days_61_90 },
        { name: '90+ Days', value: grand_total.days_90_plus },
    ].filter(d => d.value > 0), [grand_total]);

    const barData = useMemo(() =>
        [...report.rows]
            .sort((a, b) => b.total - a.total)
            .slice(0, 10)
            .map(r => ({
                name: r.client_name.length > 20 ? r.client_name.substring(0, 20) + '...' : r.client_name,
                total: r.total,
            })),
        [report.rows],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Aged Receivables" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={ArrowUpFromLine}
                        title="Aged Receivables"
                        description="Outstanding receivables by client and aging period."
                        stats={[
                            { label: 'Outstanding', value: formatCurrency(grand_total.total) },
                            { label: 'Current', value: `${currentPct}%` },
                            { label: 'Overdue 31+', value: formatCurrency(overdueAmount) },
                        ]}
                        actions={
                            <Button variant="outline" size="sm" onClick={() => window.print()} className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                <Printer className="mr-1 h-4 w-4" />
                                Print
                            </Button>
                        }
                    />
                }
            >
                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Total Outstanding</p>
                                    <p className="text-2xl font-bold tabular-nums">{formatCurrency(grand_total.total)}</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <DollarSign className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Current %</p>
                                    <p className="text-2xl font-bold tabular-nums text-status-success dark:text-status-success">{currentPct}%</p>
                                    <p className="text-xs text-muted-foreground">{formatCurrency(grand_total.current)} current</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <CheckCircle className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Overdue (31+ Days)</p>
                                    <p className="text-2xl font-bold tabular-nums text-status-critical dark:text-status-critical">{formatCurrency(overdueAmount)}</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <AlertTriangle className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Aging Bucket Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={pieData}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={50}
                                            outerRadius={90}
                                            paddingAngle={2}
                                            dataKey="value"
                                            label={({ name, percent }) => `${name} ${((percent ?? 0) * 100).toFixed(0)}%`}
                                        >
                                            {pieData.map((_, idx) => (
                                                <Cell key={idx} fill={PIE_COLORS[idx % PIE_COLORS.length]} />
                                            ))}
                                        </Pie>
                                        <Tooltip formatter={(value) => formatCurrency(value as number)} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top 10 Clients by Amount</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={barData} layout="vertical" margin={{ left: 20, right: 20 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis type="number" tickFormatter={(v) => formatCurrency(v)} />
                                        <YAxis type="category" dataKey="name" width={130} tick={{ fontSize: 12 }} />
                                        <Tooltip formatter={(value) => formatCurrency(value as number)} />
                                        <Bar dataKey="total" fill={CHART_COLORS[0]} radius={[0, 4, 4, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Outstanding Receivables by Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {report.rows.length === 0 ? (
                            <p className="text-muted-foreground text-center py-8">No outstanding receivables.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Client</TableHead>
                                            {agingColumns.map((col) => (
                                                <TableHead key={col.key} className={`text-right ${col.className}`}>
                                                    {col.label}
                                                </TableHead>
                                            ))}
                                            <TableHead className="text-right font-bold">Total</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {report.rows.map((row, idx) => (
                                            <TableRow key={idx}>
                                                <TableCell className="font-medium">{row.client_name}</TableCell>
                                                {agingColumns.map((col) => (
                                                    <TableCell key={col.key} className="text-right">
                                                        {row[col.key] > 0 ? formatCurrency(row[col.key]) : '-'}
                                                    </TableCell>
                                                ))}
                                                <TableCell className="text-right font-semibold">
                                                    {formatCurrency(row.total)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="border-t-2 font-bold">
                                            <TableCell>Grand Total</TableCell>
                                            {agingColumns.map((col) => (
                                                <TableCell key={col.key} className={`text-right ${col.className}`}>
                                                    {formatCurrency(report.grand_total[col.key])}
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right">
                                                {formatCurrency(report.grand_total.total)}
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
