import { Head, Link } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import {
    DollarSign,
    TrendingUp,
    TrendingDown,
    Wallet,
    FileText,
    CreditCard,
    Plus,
    ArrowRight,
    ArrowUpRight,
    ArrowDownRight,
} from 'lucide-react';
import {
    BarChart,
    Bar,
    AreaChart,
    Area,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Legend,
} from 'recharts';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

interface MonthlyData {
    month: string;
    amount: number;
}

interface ExpenseCategory {
    account_name: string;
    amount: number;
}

interface UpcomingBill {
    id: number;
    bill_number: string;
    vendor_name: string;
    due_date: string;
    amount_due: number;
}

interface RecentJournal {
    id: number;
    journal_number: string;
    journal_date: string;
    description: string | null;
    total_amount: number;
    type: string;
    created_by: string | null;
}

interface Props extends PageProps {
    totalRevenue: number;
    totalExpenses: number;
    netProfit: number;
    cashBalance: number;
    accountsReceivable: number;
    accountsPayable: number;
    revenueByMonth: MonthlyData[];
    expensesByMonth: MonthlyData[];
    topExpenseCategories: ExpenseCategory[];
    upcomingBillsDue: UpcomingBill[];
    recentJournals: RecentJournal[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Dashboard' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

function KpiCard({
    title,
    value,
    icon: Icon,
    className = '',
    trend,
}: {
    title: string;
    value: string;
    icon: React.ComponentType<{ className?: string }>;
    className?: string;
    trend?: { percent: number; positive: boolean } | null;
}) {
    return (
        <Card>
            <CardContent className="p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">{title}</p>
                        <p className={`text-2xl font-bold ${className}`}>{value}</p>
                        {trend != null && (
                            <div className={`mt-1 flex items-center gap-1 text-xs ${trend.positive ? 'text-status-success' : 'text-status-critical'}`}>
                                {trend.positive ? (
                                    <ArrowUpRight className="h-3 w-3" />
                                ) : (
                                    <ArrowDownRight className="h-3 w-3" />
                                )}
                                <span>{Math.abs(trend.percent).toFixed(1)}% vs last month</span>
                            </div>
                        )}
                    </div>
                    <div className="rounded-full bg-muted p-3">
                        <Icon className="h-5 w-5 text-muted-foreground" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function computeTrend(data: MonthlyData[]): { percent: number; positive: boolean } | null {
    if (data.length < 2) return null;
    const current = data[data.length - 1].amount;
    const previous = data[data.length - 2].amount;
    if (previous === 0) return null;
    const percent = ((current - previous) / Math.abs(previous)) * 100;
    return { percent, positive: percent >= 0 };
}

export default function FinanceDashboard({
    totalRevenue,
    totalExpenses,
    netProfit,
    cashBalance,
    accountsReceivable,
    accountsPayable,
    revenueByMonth,
    expensesByMonth,
    topExpenseCategories,
    upcomingBillsDue,
    recentJournals,
}: Props) {
    const chartData = revenueByMonth.map((rev, i) => ({
        month: rev.month,
        revenue: rev.amount,
        expenses: expensesByMonth[i]?.amount ?? 0,
    }));

    const profitData = revenueByMonth.map((rev, i) => ({
        month: rev.month,
        profit: rev.amount - (expensesByMonth[i]?.amount ?? 0),
    }));

    const revenueTrend = computeTrend(revenueByMonth);
    const expenseTrend = computeTrend(expensesByMonth);

    const profitMonthly = revenueByMonth.map((rev, i) => ({
        month: rev.month,
        amount: rev.amount - (expensesByMonth[i]?.amount ?? 0),
    }));
    const profitTrend = computeTrend(profitMonthly);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance Dashboard" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Finance Dashboard</h1>
                    <div className="flex gap-2">
                        <Button asChild size="sm">
                            <Link href={'/finance/journals/create'}>
                                <Plus className="mr-1 h-4 w-4" />
                                New Journal
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link href={'/finance/bills/create'}>
                                <Plus className="mr-1 h-4 w-4" />
                                New Bill
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link href={'/finance/receivables'}>
                                <FileText className="mr-1 h-4 w-4" />
                                Receivables
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link href={'/finance/bank-reconciliation'}>
                                <CreditCard className="mr-1 h-4 w-4" />
                                Bank Recon
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <KpiCard
                        title="Revenue (Month)"
                        value={formatCurrency(totalRevenue)}
                        icon={TrendingUp}
                        className="text-status-success"
                        trend={revenueTrend}
                    />
                    <KpiCard
                        title="Expenses (Month)"
                        value={formatCurrency(totalExpenses)}
                        icon={TrendingDown}
                        className="text-status-critical"
                        trend={expenseTrend ? { ...expenseTrend, positive: !expenseTrend.positive } : null}
                    />
                    <KpiCard
                        title="Net Profit"
                        value={formatCurrency(netProfit)}
                        icon={DollarSign}
                        className={netProfit >= 0 ? 'text-status-success' : 'text-status-critical'}
                        trend={profitTrend}
                    />
                    <KpiCard
                        title="Cash Balance"
                        value={formatCurrency(cashBalance)}
                        icon={Wallet}
                    />
                    <KpiCard
                        title="AR Outstanding"
                        value={formatCurrency(accountsReceivable)}
                        icon={FileText}
                    />
                    <KpiCard
                        title="AP Outstanding"
                        value={formatCurrency(accountsPayable)}
                        icon={CreditCard}
                    />
                </div>

                {/* Net Profit Trend */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Net Profit Trend</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={profitData}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                    <XAxis dataKey="month" tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                    <YAxis tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                    <Tooltip formatter={(value?: number) => formatCurrency(value ?? 0)} />
                                    <Area type="monotone" dataKey="profit" stroke="#3b82f6" fill="#3b82f6" fillOpacity={0.1} name="Net Profit" />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                {/* Charts */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Revenue vs Expenses Bar Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Revenue vs Expenses (Last 6 Months)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis dataKey="month" tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <YAxis tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <Tooltip formatter={(value?: number) => formatCurrency(value ?? 0)} />
                                        <Legend />
                                        <Bar dataKey="revenue" fill="#10b981" name="Revenue" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="expenses" fill="#ef4444" name="Expenses" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Expense Categories Pie Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top Expense Categories (This Month)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {topExpenseCategories.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No expenses recorded this month.</p>
                            ) : (
                                <>
                                    <div className="h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie
                                                    data={topExpenseCategories}
                                                    dataKey="amount"
                                                    nameKey="account_name"
                                                    cx="50%"
                                                    cy="50%"
                                                    outerRadius={90}
                                                    innerRadius={50}
                                                    paddingAngle={2}
                                                >
                                                    {topExpenseCategories.map((_, idx) => (
                                                        <Cell key={idx} fill={CHART_COLORS[idx % CHART_COLORS.length]} />
                                                    ))}
                                                </Pie>
                                                <Tooltip formatter={(value?: number) => formatCurrency(value ?? 0)} />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-2 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        {topExpenseCategories.map((cat, idx) => (
                                            <div key={idx} className="flex items-center gap-1">
                                                <div
                                                    className="h-2 w-3 rounded"
                                                    style={{ backgroundColor: CHART_COLORS[idx % CHART_COLORS.length] }}
                                                />
                                                <span className="truncate">{cat.account_name}</span>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Upcoming Bills */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Upcoming Bills Due (Next 7 Days)</CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={'/finance/bills'}>
                                    View all <ArrowRight className="ml-1 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {upcomingBillsDue.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No bills due in the next 7 days.</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Bill #</TableHead>
                                            <TableHead>Vendor</TableHead>
                                            <TableHead>Due Date</TableHead>
                                            <TableHead className="text-right">Amount Due</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {upcomingBillsDue.map((bill) => (
                                            <TableRow key={bill.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/finance/bills/${bill.id}`}
                                                        className="font-medium text-status-info hover:underline"
                                                    >
                                                        {bill.bill_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{bill.vendor_name}</TableCell>
                                                <TableCell>{formatDate(bill.due_date)}</TableCell>
                                                <TableCell className="text-right font-medium">
                                                    {formatCurrency(bill.amount_due)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Journals */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Recent Journal Entries</CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={'/finance/journals'}>
                                    View all <ArrowRight className="ml-1 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {recentJournals.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No journal entries yet.</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Journal #</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentJournals.map((journal) => (
                                            <TableRow key={journal.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/finance/journals/${journal.id}`}
                                                        className="font-medium text-status-info hover:underline"
                                                    >
                                                        {journal.journal_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{formatDate(journal.journal_date)}</TableCell>
                                                <TableCell className="max-w-[200px] truncate">
                                                    {journal.description ?? '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-medium">
                                                    {formatCurrency(journal.total_amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
