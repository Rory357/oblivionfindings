import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
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
import { BarChart3, DollarSign, TrendingDown, TrendingUp, Building2, Minus, ArrowLeftRight, Activity } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { type BreadcrumbItem } from '@/types';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
const formatCurrency = (amount: number) => new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

type AccountDetail = {
    entity_id: number;
    entity_name: string;
    account_code: string;
    account_name: string;
    account_type: string;
    balance: string;
    method: string;
    source_account_code?: string;
};

type ReportData = {
    revenue: string;
    expenses: string;
    assets: string;
    liabilities: string;
    equity: string;
    accounts: AccountDetail[];
};

type Run = {
    id: number;
    period_from: string;
    period_to: string;
    status: string;
    total_revenue: string;
    total_expenses: string;
    total_assets: string;
    total_liabilities: string;
    total_equity: string;
    eliminations_count: number;
    eliminations_amount: string;
    report_data: ReportData | null;
    notes: string | null;
    created_by: string | null;
    created_at: string;
};

type Group = {
    id: number;
    name: string;
    base_currency_code: string;
};

type PageProps = {
    group: Group;
    run: Run;
};

const statusColors: Record<string, string> = {
    draft: 'bg-muted-foreground/80/10 text-muted-foreground border-border/30',
    processing: 'bg-status-info-bg text-status-info border-status-info/30',
    completed: 'bg-status-success-bg text-status-success border-status-success/30',
    failed: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

function formatCurrencyStr(value: string | number, currency: string = 'NZD'): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(Number(value));
}

function SummaryCard({ title, value, icon: Icon, currency, colorClass }: { title: string; value: string; icon: any; currency: string; colorClass?: string }) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between p-6">
                <div>
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className={`text-2xl font-bold mt-1 ${colorClass ?? ''}`}>{formatCurrencyStr(value, currency)}</p>
                </div>
                <Icon className="h-8 w-8 text-muted-foreground/50" />
            </CardContent>
        </Card>
    );
}

export default function RunResults({ group, run }: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Consolidation', href: '/finance/consolidation' },
        { title: group.name, href: `/finance/consolidation/${group.id}` },
        { title: `Run #${run.id}`, href: `/finance/consolidation/${group.id}/runs/${run.id}` },
    ];

    const netIncome = Number(run.total_revenue) - Number(run.total_expenses);
    const accounts = run.report_data?.accounts || [];

    // Group accounts by type for display
    const accountsByType: Record<string, AccountDetail[]> = {};
    accounts.forEach((acc) => {
        const type = acc.account_type;
        if (!accountsByType[type]) accountsByType[type] = [];
        accountsByType[type].push(acc);
    });

    const typeOrder = ['revenue', 'expense', 'asset', 'liability', 'equity'];
    const typeLabels: Record<string, string> = {
        revenue: 'Revenue',
        expense: 'Expenses',
        asset: 'Assets',
        liability: 'Liabilities',
        equity: 'Equity',
    };

    // Bar chart data for financial totals
    const barChartData = [
        { name: 'Revenue', value: Number(run.total_revenue), fill: '#10b981' },
        { name: 'Expenses', value: Number(run.total_expenses), fill: '#ef4444' },
        { name: 'Assets', value: Number(run.total_assets), fill: '#3b82f6' },
        { name: 'Liabilities', value: Number(run.total_liabilities), fill: '#f59e0b' },
        { name: 'Equity', value: Number(run.total_equity), fill: '#8b5cf6' },
    ];

    // Pie chart data - composition by entity
    const entityTotals: Record<string, number> = {};
    accounts.forEach((acc) => {
        const name = acc.entity_name;
        if (!entityTotals[name]) entityTotals[name] = 0;
        entityTotals[name] += Math.abs(Number(acc.balance));
    });
    const pieChartData = Object.entries(entityTotals).map(([name, value]) => ({
        name,
        value,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Consolidation Run #${run.id}`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Consolidation Run #{run.id}
                        </h1>
                        <p className="text-muted-foreground">
                            {run.period_from} to {run.period_to}
                            {run.created_by && ` | Run by ${run.created_by}`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className={statusColors[run.status]}>
                            {run.status.charAt(0).toUpperCase() + run.status.slice(1)}
                        </Badge>
                        <Link href={`/finance/consolidation/${group.id}`}>
                            <Button variant="outline" size="sm">Back to Group</Button>
                        </Link>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    <SummaryCard title="Total Revenue" value={run.total_revenue} icon={TrendingUp} currency={group.base_currency_code} colorClass="text-status-success" />
                    <SummaryCard title="Total Expenses" value={run.total_expenses} icon={TrendingDown} currency={group.base_currency_code} colorClass="text-status-critical" />
                    <SummaryCard title="Total Assets" value={run.total_assets} icon={Building2} currency={group.base_currency_code} />
                    <SummaryCard title="Total Liabilities" value={run.total_liabilities} icon={Minus} currency={group.base_currency_code} />
                    <SummaryCard title="Total Equity" value={run.total_equity} icon={DollarSign} currency={group.base_currency_code} />
                </div>

                {/* KPI Cards: Net Income & Eliminations */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Net Income</p>
                                <p className={`text-2xl font-bold mt-1 ${netIncome >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                    {formatCurrencyStr(netIncome, group.base_currency_code)}
                                </p>
                                <p className="text-xs text-muted-foreground mt-1">Revenue minus Expenses</p>
                            </div>
                            <Activity className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Intercompany Eliminations</p>
                                <p className="text-2xl font-bold mt-1">
                                    {run.eliminations_count} <span className="text-base font-normal text-muted-foreground">transactions</span>
                                </p>
                                <p className="text-sm font-semibold text-muted-foreground mt-1">
                                    {formatCurrencyStr(run.eliminations_amount, group.base_currency_code)} eliminated
                                </p>
                            </div>
                            <ArrowLeftRight className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Bar Chart - Financial Totals */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Financial Summary</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[320px] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={barChartData} margin={{ top: 20, right: 30, left: 20, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                        <YAxis
                                            tick={{ fontSize: 12 }}
                                            tickFormatter={(value: number) => {
                                                if (Math.abs(value) >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
                                                if (Math.abs(value) >= 1000) return `$${(value / 1000).toFixed(0)}k`;
                                                return `$${value}`;
                                            }}
                                        />
                                        <Tooltip formatter={(value) => formatCurrency(Number(value))} />
                                        <Bar dataKey="value" name="Amount" radius={[4, 4, 0, 0]}>
                                            {barChartData.map((entry, index) => (
                                                <Cell key={`cell-${index}`} fill={entry.fill} />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Pie Chart - Entity Composition */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Entity Composition</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[320px] w-full">
                                {pieChartData.length > 0 ? (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={pieChartData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={110}
                                                paddingAngle={2}
                                                dataKey="value"
                                                label={({ name, percent }) => `${name} (${((percent ?? 0) * 100).toFixed(0)}%)`}
                                                labelLine={true}
                                            >
                                                {pieChartData.map((_entry, index) => (
                                                    <Cell key={`cell-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                                                ))}
                                            </Pie>
                                            <Tooltip formatter={(value) => formatCurrency(Number(value))} />
                                        </PieChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div className="flex items-center justify-center h-full text-muted-foreground">
                                        No entity data available
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Account Details */}
                {typeOrder.map((type) => {
                    const typeAccounts = accountsByType[type];
                    if (!typeAccounts || typeAccounts.length === 0) return null;

                    return (
                        <Card key={type}>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <BarChart3 className="h-5 w-5 text-muted-foreground" />
                                    <CardTitle>{typeLabels[type]}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Entity</TableHead>
                                            <TableHead>Account Code</TableHead>
                                            <TableHead>Account Name</TableHead>
                                            <TableHead>Method</TableHead>
                                            <TableHead className="text-right">Balance</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {typeAccounts.map((acc, idx) => (
                                            <TableRow key={`${type}-${idx}`}>
                                                <TableCell className="text-sm">{acc.entity_name}</TableCell>
                                                <TableCell className="font-mono text-sm">{acc.account_code}</TableCell>
                                                <TableCell>{acc.account_name}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className="text-xs">
                                                        {acc.method.charAt(0).toUpperCase() + acc.method.slice(1)}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatCurrencyStr(acc.balance, group.base_currency_code)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    );
                })}

                {run.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground whitespace-pre-wrap">{run.notes}</p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
