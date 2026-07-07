import { Head, router } from '@inertiajs/react';
import { PageProps, type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { ReportsTabsFooter } from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Printer, CheckCircle, AlertTriangle, Landmark, HandCoins, Scale } from 'lucide-react';
import { useState, useMemo } from 'react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';
import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';

interface AccountRow {
    account_code: string;
    account_name: string;
    sub_type: string | null;
    balance: number;
}

interface Report {
    as_of_date: string;
    assets: AccountRow[];
    total_assets: number;
    liabilities: AccountRow[];
    total_liabilities: number;
    equity: AccountRow[];
    total_equity: number;
    balanced: boolean;
}

interface Props extends PageProps {
    report: Report;
    filters: { as_of_date: string };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Reports' },
    { title: 'Balance Sheet' },
];

function SectionTable({ title, rows, total }: { title: string; rows: AccountRow[]; total: number }) {
    return (
        <>
            <TableRow className="bg-muted/50">
                <TableCell colSpan={3} className="font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {rows.map((row, idx) => (
                <TableRow key={`${title}-${idx}`}>
                    <TableCell className="font-mono text-sm">{row.account_code || '-'}</TableCell>
                    <TableCell>{row.account_name}</TableCell>
                    <TableCell className="text-right">{formatMoney(row.balance)}</TableCell>
                </TableRow>
            ))}
            {rows.length === 0 && (
                <TableRow>
                    <TableCell colSpan={3} className="text-muted-foreground">
                        No accounts.
                    </TableCell>
                </TableRow>
            )}
            <TableRow className="border-t font-semibold">
                <TableCell colSpan={2}>Total {title}</TableCell>
                <TableCell className="text-right">{formatMoney(total)}</TableCell>
            </TableRow>
        </>
    );
}

export default function BalanceSheet({ report, filters }: Props) {
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date);

    const applyFilter = () => {
        router.get('/finance/reports/balance-sheet', { as_of_date: asOfDate }, { preserveState: true });
    };

    const pieData = useMemo(() => {
        return [
            { name: 'Assets', value: Math.abs(report.total_assets) },
            { name: 'Liabilities', value: Math.abs(report.total_liabilities) },
            { name: 'Equity', value: Math.abs(report.total_equity) },
        ].filter((d) => d.value > 0);
    }, [report.total_assets, report.total_liabilities, report.total_equity]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Balance Sheet" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Scale}
                        title="Balance Sheet"
                        description="Financial position showing assets, liabilities, and equity."
                        stats={[
                            { label: 'Assets', value: formatMoney(report.total_assets) },
                            { label: 'Liabilities', value: formatMoney(report.total_liabilities) },
                            { label: 'Equity', value: formatMoney(report.total_equity) },
                        ]}
                        actions={
                            <Button variant="outline" size="sm" onClick={() => window.print()} className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                <Printer className="mr-1 h-4 w-4" />
                                Print
                            </Button>
                        }
                        footer={<ReportsTabsFooter active="balance-sheet" />}
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-full bg-status-info-bg p-3">
                                <Landmark className="h-5 w-5 text-status-info dark:text-status-info" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Assets</p>
                                <p className="text-2xl font-bold text-status-info dark:text-status-info">
                                    {formatMoney(report.total_assets)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-full bg-status-critical-bg p-3">
                                <HandCoins className="h-5 w-5 text-status-critical dark:text-status-critical" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Liabilities</p>
                                <p className="text-2xl font-bold text-status-critical dark:text-status-critical">
                                    {formatMoney(report.total_liabilities)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-full bg-status-warning-bg p-3">
                                <Scale className="h-5 w-5 text-status-warning dark:text-status-warning" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Equity</p>
                                <p className="text-2xl font-bold text-status-warning dark:text-status-warning">
                                    {formatMoney(report.total_equity)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter */}
                <Card>
                    <CardContent className="flex items-end gap-4 pt-6">
                        <div>
                            <label className="mb-1 block text-sm font-medium">As of Date</label>
                            <Input
                                type="date"
                                value={asOfDate}
                                onChange={(e) => setAsOfDate(e.target.value)}
                                className="w-48"
                            />
                        </div>
                        <Button onClick={applyFilter}>Generate</Button>
                    </CardContent>
                </Card>

                {/* Pie Chart */}
                {pieData.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Composition Overview</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={pieData}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={60}
                                            outerRadius={100}
                                            paddingAngle={3}
                                            dataKey="value"
                                            label={({ name, percent }) =>
                                                `${name} ${((percent ?? 0) * 100).toFixed(0)}%`
                                            }
                                        >
                                            {pieData.map((_, index) => (
                                                <Cell key={`cell-${index}`} fill={chartColor(index)} />
                                            ))}
                                        </Pie>
                                        <Tooltip formatter={(value?: number) => formatMoney(value ?? 0)} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="mt-3 flex items-center justify-center gap-6 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-3 w-3 rounded-sm bg-status-info" /> Assets
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-3 w-3 rounded-sm bg-status-critical" /> Liabilities
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-3 w-3 rounded-sm bg-status-warning" /> Equity
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Existing Table */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>
                            Balance Sheet as at{' '}
                            {new Date(report.as_of_date).toLocaleDateString('en-NZ', {
                                day: '2-digit',
                                month: 'long',
                                year: 'numeric',
                            })}
                        </CardTitle>
                        {report.balanced ? (
                            <Badge variant="outline" className="border-status-success/30 text-status-success dark:text-status-success">
                                <CheckCircle className="mr-1 h-3 w-3" />
                                Balanced
                            </Badge>
                        ) : (
                            <Badge variant="destructive">
                                <AlertTriangle className="mr-1 h-3 w-3" />
                                Out of Balance
                            </Badge>
                        )}
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-32">Account Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <SectionTable title="Assets" rows={report.assets} total={report.total_assets} />
                                <SectionTable
                                    title="Liabilities"
                                    rows={report.liabilities}
                                    total={report.total_liabilities}
                                />
                                <SectionTable title="Equity" rows={report.equity} total={report.total_equity} />

                                <TableRow className="border-t-2 text-lg font-bold">
                                    <TableCell colSpan={2}>Total Liabilities + Equity</TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(report.total_liabilities + report.total_equity)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
