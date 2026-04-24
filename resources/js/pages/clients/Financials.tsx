import { FleetStatCard } from '@/components/fleet-stat-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/fleet-utils';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    DollarSign,
    Receipt,
    Wallet,
} from 'lucide-react';

type LedgerEntry = {
    date: string;
    source: string;
    type: string;
    category: string | null;
    direction: string;
    amount: string;
    signed_amount: string;
    description: string;
    reference: string | null;
    running_balance?: string;
    is_gl_backed: boolean;
};

type Props = {
    client: { id: number; first_name: string; last_name: string; full_name: string };
    summary: {
        balance: { current: string; total_inflows: string; total_outflows: string; net: string };
        personal: { contributions: string; purchases: string; reimbursements: string };
        cost_of_care: {
            direct: { payroll: string; employer_oncost: string; mileage: string; transport: string; purchases: string; other: string; total: string };
            overheads: { rent: string; utilities: string; maintenance: string; house_operating: string; other: string; total: string };
            total: string;
            weekly_equivalent: string;
            resident_days: number;
        };
        funding: { agreement_count: number; total_budget: string; period_allocation: string; remaining: string };
        gap_analysis: { total_gap: string; weekly_gap: string; is_underfunded: boolean; funding_coverage_pct: string };
    };
    ledger: {
        entries: LedgerEntry[];
        summary: { total_inflows: string; total_outflows: string; net: string };
    };
    filters: { from: string; to: string };
};

const $ = (v: string | number) => formatCurrency(Number(v));
const formatDate = (d: string) => new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short' });

const typeLabel: Record<string, string> = {
    contribution: 'Contribution',
    funding: 'Funding',
    purchase: 'Purchase',
    reimbursement: 'Reimbursement',
    adjustment: 'Adjustment',
    transfer: 'Transfer',
    payroll_cost: 'Payroll',
    employer_oncost: 'On-Cost',
    mileage_reimbursement: 'Mileage',
    fuel_expense: 'Transport',
    client_ledger_expense: 'Expense',
    cost_allocation: 'Allocated Cost',
};

export default function ClientFinancials({ client, summary, ledger, filters }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Clients', href: '/clients' },
        { title: client.full_name, href: `/clients/${client.id}` },
        { title: 'Financials' },
    ];

    const gap = summary.gap_analysis;
    const care = summary.cost_of_care;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Financials - ${client.full_name}`} />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Financials</h1>
                    <p className="text-sm text-muted-foreground">{client.full_name} &middot; {filters.from} to {filters.to}</p>
                </div>

                {/* Hero Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FleetStatCard
                        label="Weekly Cost"
                        value={$(care.weekly_equivalent)}
                        icon={DollarSign}
                        color="purple"
                        subtitle="Cost of care"
                    />
                    <FleetStatCard
                        label="Weekly Funding"
                        value={summary.funding.agreement_count > 0 ? $(Number(summary.funding.period_allocation) / Math.max(1, Math.round((new Date(filters.to).getTime() - new Date(filters.from).getTime()) / (7 * 86400000)))) : 'No funding'}
                        icon={Banknote}
                        color={summary.funding.agreement_count > 0 ? 'blue' : 'slate'}
                        subtitle={summary.funding.agreement_count > 0 ? `${summary.funding.agreement_count} agreement(s)` : 'No active agreements'}
                    />
                    <FleetStatCard
                        label="Weekly Gap"
                        value={$(gap.weekly_gap)}
                        icon={gap.is_underfunded ? AlertTriangle : Wallet}
                        color={gap.is_underfunded ? 'red' : 'cyan'}
                        subtitle={gap.is_underfunded ? `${gap.funding_coverage_pct}% funded` : 'Fully funded'}
                    />
                    <FleetStatCard
                        label="Ledger Balance"
                        value={$(summary.balance.current)}
                        icon={Receipt}
                        color="purple"
                        subtitle={`${$(summary.balance.total_inflows)} in, ${$(summary.balance.total_outflows)} out`}
                    />
                </div>

                {/* Funding Gap Banner */}
                {gap.is_underfunded && (
                    <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/30">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                        <div>
                            <p className="text-sm font-medium text-red-800 dark:text-red-300">Underfunded Client</p>
                            <p className="mt-0.5 text-sm text-red-700 dark:text-red-400">
                                This client's cost of care exceeds funding by {$(gap.weekly_gap)}/week.
                                Funding covers {gap.funding_coverage_pct}% of costs.
                            </p>
                        </div>
                    </div>
                )}

                {/* Cost of Care + Staffing */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Direct Costs */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Direct Costs</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <CostRow label="Wages" amount={care.direct.payroll} />
                                <CostRow label="Employer On-Costs" amount={care.direct.employer_oncost} muted />
                                <CostRow label="Mileage" amount={care.direct.mileage} />
                                <CostRow label="Transport" amount={care.direct.transport} />
                                <CostRow label="Personal Purchases" amount={care.direct.purchases} />
                                {Number(care.direct.other) > 0 && <CostRow label="Other" amount={care.direct.other} />}
                                <div className="border-t pt-2">
                                    <CostRow label="Total Direct" amount={care.direct.total} bold />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Allocated Overheads */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Allocated Overheads</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <CostRow label="Rent" amount={care.overheads.rent} />
                                <CostRow label="Utilities" amount={care.overheads.utilities} />
                                <CostRow label="Maintenance" amount={care.overheads.maintenance} />
                                <CostRow label="House Operating" amount={care.overheads.house_operating} />
                                {Number(care.overheads.other) > 0 && <CostRow label="Other" amount={care.overheads.other} />}
                                <div className="border-t pt-2">
                                    <CostRow label="Total Overheads" amount={care.overheads.total} bold />
                                </div>
                                <div className="border-t pt-2">
                                    <CostRow label="Total Cost of Care" amount={care.total} bold highlight />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Ledger Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Ledger</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {ledger.entries.length > 0 ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Balance</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {ledger.entries.map((entry, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="tabular-nums text-sm">{formatDate(entry.date)}</TableCell>
                                            <TableCell>
                                                <Badge variant={entry.direction === 'inflow' ? 'default' : 'secondary'} className="text-[10px]">
                                                    {typeLabel[entry.type] || entry.type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="max-w-[300px] truncate text-sm">{entry.description}</TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <span className={`flex items-center justify-end gap-1 ${entry.direction === 'inflow' ? 'text-green-600' : 'text-red-600'}`}>
                                                    {entry.direction === 'inflow' ? <ArrowUpRight className="h-3 w-3" /> : <ArrowDownRight className="h-3 w-3" />}
                                                    {$(entry.amount)}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums text-sm">
                                                {entry.running_balance !== undefined ? $(entry.running_balance) : '-'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Receipt className="h-10 w-10 text-muted-foreground/40" />
                                <p className="mt-3 text-sm text-muted-foreground">No ledger entries for this period</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function CostRow({ label, amount, bold, muted, highlight }: { label: string; amount: string; bold?: boolean; muted?: boolean; highlight?: boolean }) {
    return (
        <div className={`flex items-center justify-between ${bold ? 'font-semibold' : ''} ${muted ? 'text-muted-foreground' : ''}`}>
            <span className={`text-sm ${highlight ? 'text-primary dark:text-primary/70' : ''}`}>{label}</span>
            <span className={`tabular-nums text-sm ${highlight ? 'text-primary dark:text-primary/70' : ''}`}>{$(amount)}</span>
        </div>
    );
}
