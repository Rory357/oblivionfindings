import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    DollarSign,
    TrendingUp,
    TrendingDown,
    Wallet,
    FileText,
    CreditCard,
    Plus,
    ArrowRight,
} from 'lucide-react';

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

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

function KpiCard({
    title,
    value,
    icon: Icon,
    className = '',
}: {
    title: string;
    value: string;
    icon: React.ComponentType<{ className?: string }>;
    className?: string;
}) {
    return (
        <Card>
            <CardContent className="p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">{title}</p>
                        <p className={`text-2xl font-bold ${className}`}>{value}</p>
                    </div>
                    <div className="rounded-full bg-muted p-3">
                        <Icon className="h-5 w-5 text-muted-foreground" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function BarChart({ revenueData, expenseData }: { revenueData: MonthlyData[]; expenseData: MonthlyData[] }) {
    const allAmounts = [...revenueData.map((d) => d.amount), ...expenseData.map((d) => d.amount)];
    const maxAmount = Math.max(...allAmounts, 1);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Revenue vs Expenses (Last 6 Months)</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    {revenueData.map((rev, idx) => {
                        const exp = expenseData[idx];
                        const revPct = (rev.amount / maxAmount) * 100;
                        const expPct = (exp?.amount ?? 0) / maxAmount * 100;

                        return (
                            <div key={rev.month} className="space-y-1">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="font-medium">{rev.month}</span>
                                    <span className="text-muted-foreground">
                                        {formatCurrency(rev.amount)} / {formatCurrency(exp?.amount ?? 0)}
                                    </span>
                                </div>
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span className="w-16 text-xs text-muted-foreground">Revenue</span>
                                        <div className="flex-1 rounded-full bg-muted h-3">
                                            <div
                                                className="h-3 rounded-full bg-green-500 transition-all"
                                                style={{ width: `${Math.max(revPct, 0.5)}%` }}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="w-16 text-xs text-muted-foreground">Expenses</span>
                                        <div className="flex-1 rounded-full bg-muted h-3">
                                            <div
                                                className="h-3 rounded-full bg-red-400 transition-all"
                                                style={{ width: `${Math.max(expPct, 0.5)}%` }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
                <div className="mt-4 flex gap-4 text-xs text-muted-foreground">
                    <div className="flex items-center gap-1">
                        <div className="h-2 w-4 rounded bg-green-500" />
                        Revenue
                    </div>
                    <div className="flex items-center gap-1">
                        <div className="h-2 w-4 rounded bg-red-400" />
                        Expenses
                    </div>
                </div>
            </CardContent>
        </Card>
    );
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
    return (
        <AppLayout>
            <Head title="Finance Dashboard" />

            <div className="space-y-6">
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
                        className="text-green-600"
                    />
                    <KpiCard
                        title="Expenses (Month)"
                        value={formatCurrency(totalExpenses)}
                        icon={TrendingDown}
                        className="text-red-600"
                    />
                    <KpiCard
                        title="Net Profit"
                        value={formatCurrency(netProfit)}
                        icon={DollarSign}
                        className={netProfit >= 0 ? 'text-green-600' : 'text-red-600'}
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

                {/* Charts & Lists */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <BarChart revenueData={revenueByMonth} expenseData={expensesByMonth} />

                    <Card>
                        <CardHeader>
                            <CardTitle>Top Expense Categories (This Month)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {topExpenseCategories.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No expenses recorded this month.</p>
                            ) : (
                                <div className="space-y-3">
                                    {topExpenseCategories.map((cat, idx) => {
                                        const maxExp = topExpenseCategories[0]?.amount || 1;
                                        const pct = (cat.amount / maxExp) * 100;
                                        return (
                                            <div key={idx} className="space-y-1">
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="truncate font-medium">{cat.account_name}</span>
                                                    <span className="font-semibold">{formatCurrency(cat.amount)}</span>
                                                </div>
                                                <div className="h-2 rounded-full bg-muted">
                                                    <div
                                                        className="h-2 rounded-full bg-blue-500"
                                                        style={{ width: `${pct}%` }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
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
                                                        className="font-medium text-blue-600 hover:underline"
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
                                                        className="font-medium text-blue-600 hover:underline"
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
