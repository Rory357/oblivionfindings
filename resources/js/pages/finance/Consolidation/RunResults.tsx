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
import { BarChart3, DollarSign, TrendingDown, TrendingUp, Building2, Minus } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

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
    draft: 'bg-gray-500/10 text-gray-600 border-gray-500/30',
    processing: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    completed: 'bg-green-500/10 text-green-600 border-green-500/30',
    failed: 'bg-red-500/10 text-red-600 border-red-500/30',
};

function formatCurrency(value: string | number, currency: string = 'NZD'): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(Number(value));
}

function SummaryCard({ title, value, icon: Icon, currency }: { title: string; value: string; icon: any; currency: string }) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between p-6">
                <div>
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="text-2xl font-bold mt-1">{formatCurrency(value, currency)}</p>
                </div>
                <Icon className="h-8 w-8 text-muted-foreground/50" />
            </CardContent>
        </Card>
    );
}

export default function RunResults({ group, run }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Consolidation Run #${run.id}`} />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
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
                    <SummaryCard title="Total Revenue" value={run.total_revenue} icon={TrendingUp} currency={group.base_currency_code} />
                    <SummaryCard title="Total Expenses" value={run.total_expenses} icon={TrendingDown} currency={group.base_currency_code} />
                    <SummaryCard title="Total Assets" value={run.total_assets} icon={Building2} currency={group.base_currency_code} />
                    <SummaryCard title="Total Liabilities" value={run.total_liabilities} icon={Minus} currency={group.base_currency_code} />
                    <SummaryCard title="Total Equity" value={run.total_equity} icon={DollarSign} currency={group.base_currency_code} />
                </div>

                {/* Net Income & Eliminations */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card>
                        <CardContent className="p-6">
                            <p className="text-sm text-muted-foreground">Net Income</p>
                            <p className={`text-2xl font-bold mt-1 ${netIncome >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                {formatCurrency(netIncome, group.base_currency_code)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-6">
                            <p className="text-sm text-muted-foreground">Intercompany Eliminations</p>
                            <p className="text-2xl font-bold mt-1">
                                {run.eliminations_count} transactions
                                ({formatCurrency(run.eliminations_amount, group.base_currency_code)})
                            </p>
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
                                                    {formatCurrency(acc.balance, group.base_currency_code)}
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
