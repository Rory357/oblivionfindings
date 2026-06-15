import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AreaChart,
    Area,
    BarChart,
    Bar,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Calendar,
    Clock,
    Coins,
    CreditCard,
    DollarSign,
    FileText,
    Gauge,
    LayoutDashboard,
    MapPin,
    Percent,
    Plus,
    Receipt,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import { DonutCard } from '@/components/rostering/donut-card';
import { type DonutSegment } from '@/components/rostering/donut';
import {
    NewBillDialog,
    NewInvoiceDialog,
    NewJournalDialog,
    RecordReceiptDialog,
    StatusBadge,
    formatMoney,
    formatMoneyCompact,
    type StatusTone,
} from '@/components/finance';
import { FinanceHubsBar } from '@/components/finance/finance-hubs-bar';
import { NeedsAttentionStrip, type AttentionItem } from '@/components/finance/needs-attention-strip';
import { FinanceDashboardFooter } from '@/components/finance/finance-dashboard-footer';
import { cn } from '@/lib/utils';

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

interface RefItem {
    id: number;
    code: string;
    name: string;
}
interface NamedItem {
    id: number;
    name: string;
}
interface TaxRateItem {
    id: number;
    name: string;
    rate: string | number;
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
    revenueByFundingStream?: { name: string; amount: number }[];
    arAging?: {
        current: number;
        d1_30: number;
        d31_60: number;
        d61_90: number;
        d90_plus: number;
        over60: number;
        total: number;
    };
    upcomingBillsDue: UpcomingBill[];
    apDueWithin7?: { count: number; total: number };
    cashRunwayDays?: number | null;
    recentJournals: RecentJournal[];
    period?: Period;
    periodLabel?: string;
    // Reference data for the quick-action wizard modals. Supplied by the
    // controller in Phase B; default to empty so the modals still open.
    accounts?: RefItem[];
    costCentres?: RefItem[];
    fundingStreams?: RefItem[];
    vendors?: NamedItem[];
    clients?: NamedItem[];
    taxRates?: TaxRateItem[];
    // Hero footer filters (real options land in Phase B).
    siteOptions?: NamedItem[];
    funderOptions?: NamedItem[];
    orgName?: string;
}

type Period = 'month' | 'quarter' | 'fy';
type Modal = null | 'journal' | 'bill' | 'invoice' | 'receipt';
type KpiTone = 'primary' | 'success' | 'warning' | 'critical' | 'info';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Dashboard' },
];

const PERIODS: { key: Period; label: string }[] = [
    { key: 'month', label: 'This month' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'fy', label: 'Financial year' },
];
const PERIOD_LABEL: Record<Period, string> = {
    month: 'This month',
    quarter: 'This quarter',
    fy: 'FY2026',
};

const KPI_TILE: Record<KpiTone, string> = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
};

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

// fmtK for donut legends: values are carried in thousands so the SVG arc math
// stays small; multiply back up for the money label.
const fmtK = (v: number) => formatMoneyCompact(v * 1000);

function computeTrend(data: MonthlyData[]): { percent: number } | null {
    if (data.length < 2) return null;
    const current = data[data.length - 1].amount;
    const previous = data[data.length - 2].amount;
    if (previous === 0) return null;
    return { percent: ((current - previous) / Math.abs(previous)) * 100 };
}

// ---------------------------------------------------------------------------
// Placeholder data — clearly marked. Replaced by real props in later phases.
// ---------------------------------------------------------------------------

// TODO(D): claim-utilisation buckets from the funding/remittance pipeline.
const DONUT_UTILISATION: DonutSegment[] = [
    { key: 'paid', label: 'Claimed & paid', value: 312, color: 'var(--status-success)' },
    { key: 'awaiting', label: 'Awaiting remittance', value: 88, color: 'var(--chart-2)' },
    { key: 'delivered', label: 'Delivered, not yet claimed', value: 54, color: 'var(--status-warning)' },
    { key: 'writeoff', label: 'Unfunded / write-off risk', value: 33, color: 'var(--status-critical)' },
];
// TODO(D): funding claims from the funding pipeline.
const FUNDING_CLAIMS: { ref: string; funder: string; period: string; status: string; tone: StatusTone; amount: number }[] = [
    { ref: 'FC-3391', funder: 'MoH', period: 'May 2026', status: 'Awaiting remittance', tone: 'warning', amount: 214800 },
    { ref: 'FC-3402', funder: 'ACC', period: 'May 2026', status: 'Approved', tone: 'success', amount: 48260 },
    { ref: 'FC-3410', funder: 'Individualised Funding', period: 'Jun 2026', status: 'Delivered · unclaimed', tone: 'warning', amount: 31540 },
    { ref: 'FC-3415', funder: 'Respite/Carer', period: 'Jun 2026', status: 'Draft', tone: 'neutral', amount: 12900 },
    { ref: 'FC-3388', funder: 'MoH', period: 'Apr 2026', status: 'Write-off risk', tone: 'critical', amount: 8470 },
];

// TODO(B/D/F): attention items from real queries (AR aging, remittances, bills
// due, payroll state, GST calendar, delivered-unclaimed).
const ATTENTION_ITEMS: AttentionItem[] = [
    { id: 'ar90', severity: 'critical', icon: FileText, title: 'AR overdue 90+ days', body: 'Receivables aged past 90 days need chasing.', tag: '$24.6k · 90+ days' },
    { id: 'remit', severity: 'warning', icon: Coins, title: 'Funder remittances to reconcile', body: 'Payments received not yet matched to claims.', tag: '3 · $88k' },
    { id: 'bills7', severity: 'warning', icon: CreditCard, title: 'Bills due within 7 days', body: 'Approved bills falling due this week.', tag: '5 · $128k' },
    { id: 'payroll', severity: 'info', icon: Clock, title: 'Payroll run awaiting approval', body: 'Fortnightly run ready to lock & post.', tag: '$186.4k' },
    { id: 'gst', severity: 'warning', icon: Percent, title: 'GST return due', body: 'Period GST return filing deadline approaching.', tag: 'due 28 Jun' },
    { id: 'unclaimed', severity: 'warning', icon: Coins, title: 'Delivered hours not yet claimed', body: 'Service delivered without a funding claim raised.', tag: '$54k unclaimed' },
];

function PulseDot() {
    return (
        <span className="relative inline-flex h-2 w-2">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-foreground/60" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-primary-foreground" />
        </span>
    );
}

function KpiCard({
    label,
    value,
    icon: Icon,
    tone = 'primary',
    delta,
    sub,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    tone?: KpiTone;
    delta?: { percent: number; good: boolean } | null;
    sub?: string;
}) {
    return (
        <div className="rounded-[15px] border border-border bg-card p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-muted-foreground">{label}</span>
                <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-lg', KPI_TILE[tone])}>
                    <Icon className="h-4 w-4" />
                </span>
            </div>
            <div className="mt-2 text-2xl font-bold tracking-tight tabular-nums">{value}</div>
            <div className="mt-1.5 flex flex-wrap items-center gap-1 text-[11.5px]">
                {delta ? (
                    <span
                        className={cn(
                            'inline-flex items-center gap-0.5 font-bold tabular-nums',
                            delta.good ? 'text-status-success' : 'text-status-critical',
                        )}
                    >
                        {delta.percent >= 0 ? <ArrowUpRight className="h-3 w-3" /> : <ArrowDownRight className="h-3 w-3" />}
                        {Math.abs(delta.percent).toFixed(1)}%
                    </span>
                ) : null}
                {sub ? (
                    <span className="text-muted-foreground/70">
                        {delta ? '· ' : ''}
                        {sub}
                    </span>
                ) : null}
            </div>
        </div>
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
    revenueByFundingStream = [],
    arAging,
    upcomingBillsDue,
    apDueWithin7,
    cashRunwayDays,
    recentJournals,
    accounts = [],
    costCentres = [],
    fundingStreams = [],
    vendors = [],
    clients = [],
    taxRates = [],
    siteOptions = [],
    funderOptions = [],
    orgName = 'Whakaora Support Services',
    period: serverPeriod = 'month',
    periodLabel,
}: Props) {
    const [period, setPeriod] = useState<Period>(serverPeriod);
    const [modal, setModal] = useState<Modal>(null);
    const [siteFilter, setSiteFilter] = useState<number[]>([]);
    const [funderFilter, setFunderFilter] = useState<number[]>([]);

    // Period / filter changes → real Inertia partial reload. `only` trims the
    // payload to the period-aware metric props (ref-data closures are skipped).
    const reload = (next: { period?: Period; site?: number[]; funder?: number[] }) => {
        router.reload({
            only: [
                'totalRevenue', 'totalExpenses', 'netProfit', 'cashBalance', 'accountsReceivable',
                'accountsPayable', 'revenueByMonth', 'expensesByMonth', 'topExpenseCategories',
                'revenueByFundingStream', 'arAging', 'upcomingBillsDue', 'apDueWithin7', 'cashRunwayDays',
                'recentJournals', 'period', 'periodLabel',
            ],
            data: {
                period: next.period ?? period,
                site: next.site ?? siteFilter,
                funder: next.funder ?? funderFilter,
            },
            preserveState: true,
            preserveScroll: true,
        });
    };
    const changePeriod = (p: Period) => {
        setPeriod(p);
        reload({ period: p });
    };
    const changeSite = (v: number[]) => {
        setSiteFilter(v);
        reload({ site: v });
    };
    const changeFunder = (v: number[]) => {
        setFunderFilter(v);
        reload({ funder: v });
    };

    // §5 donut 1 — real revenue-by-funding-stream (dollars). Donuts 2 & 3 stay
    // placeholder (thousands) until Phases D / C.
    const REVENUE_COLORS = ['var(--chart-1)', 'var(--chart-5)', 'var(--chart-4)', 'var(--chart-2)', 'var(--chart-3)'];
    const revenueStreamTotal = revenueByFundingStream.reduce((sum, s) => sum + s.amount, 0);
    const revenueStreamSegments: DonutSegment[] = revenueByFundingStream.length
        ? revenueByFundingStream.map((s, i) => ({
              key: `fs-${i}`,
              label: s.name,
              value: s.amount,
              color: REVENUE_COLORS[i % REVENUE_COLORS.length],
          }))
        : [{ key: 'none', label: 'No revenue in period', value: 1, color: 'var(--border)' }];

    // §5 donut 3 — real AR aging (point-in-time, live FinInvoice via the AR service).
    const arAgingSegments: DonutSegment[] = (
        arAging && arAging.total > 0
            ? [
                  { key: 'current', label: 'Current', value: arAging.current, color: 'var(--status-success)' },
                  { key: 'd1_30', label: '1–30 days', value: arAging.d1_30, color: 'var(--chart-2)' },
                  { key: 'd31_60', label: '31–60 days', value: arAging.d31_60, color: 'var(--chart-4)' },
                  { key: 'd61_90', label: '61–90 days', value: arAging.d61_90, color: 'var(--chart-3)' },
                  { key: 'd90_plus', label: '90+ days', value: arAging.d90_plus, color: 'var(--status-critical)' },
              ].filter((s) => s.value > 0)
            : []
    );
    const arAgingDonut: DonutSegment[] = arAgingSegments.length
        ? arAgingSegments
        : [{ key: 'none', label: 'No outstanding receivables', value: 1, color: 'var(--border)' }];

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
    const profitTrend = computeTrend(
        revenueByMonth.map((rev, i) => ({ month: rev.month, amount: rev.amount - (expensesByMonth[i]?.amount ?? 0) })),
    );
    const margin = totalRevenue > 0 ? Math.round((netProfit / totalRevenue) * 1000) / 10 : 0;
    const billsDueTotal = upcomingBillsDue.reduce((sum, b) => sum + b.amount_due, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance Dashboard" />

            <PageLayout
                width="wide"
                hero={
                    <PageHero
                        icon={LayoutDashboard}
                        title={
                            <span>
                                <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold uppercase tracking-wider text-primary-foreground/85 md:justify-start">
                                    <PulseDot />
                                    Live ledger · {periodLabel ?? PERIOD_LABEL[period]}
                                </span>
                                <span className="block">Finance Dashboard</span>
                            </span>
                        }
                        description={
                            <span>
                                Live general ledger for{' '}
                                <span className="font-semibold text-primary-foreground">{orgName}</span> across{' '}
                                <span className="font-semibold text-primary-foreground">14 sites</span> and{' '}
                                <span className="font-semibold text-primary-foreground">5 funding streams</span>.
                            </span>
                        }
                        meta={[
                            // TODO(B): drive from real org/period props.
                            { icon: Calendar, label: 'FY2026 · open period Jun' },
                            { icon: MapPin, label: '14 sites · 6 regions' },
                            { icon: Users, label: '77 residents funded' },
                        ]}
                        stats={[
                            { label: 'Revenue', value: formatMoneyCompact(totalRevenue) },
                            { label: 'Expenses', value: formatMoneyCompact(totalExpenses) },
                            {
                                label: 'Net profit',
                                value: formatMoneyCompact(netProfit),
                                tone: netProfit >= 0 ? 'success' : 'critical',
                            },
                            { label: 'Cash', value: formatMoneyCompact(cashBalance) },
                        ]}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    onClick={() => setModal('journal')}
                                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                >
                                    <Plus className="mr-1 h-4 w-4" /> New Journal
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setModal('bill')}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Plus className="mr-1 h-4 w-4" /> New Bill
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setModal('invoice')}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <FileText className="mr-1 h-4 w-4" /> New Invoice
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setModal('receipt')}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Receipt className="mr-1 h-4 w-4" /> Record Receipt
                                </Button>
                            </div>
                        }
                        footer={
                            <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                                <div className="inline-flex w-fit rounded-[10px] bg-primary-foreground/15 p-[3px]">
                                    {PERIODS.map((p) => (
                                        // eslint-disable-next-line no-restricted-syntax -- segmented-control pill, not a shadcn Button
                                        <button
                                            key={p.key}
                                            type="button"
                                            onClick={() => changePeriod(p.key)}
                                            className={cn(
                                                'rounded-md px-3 py-1 text-[12.5px] font-semibold transition-colors',
                                                period === p.key
                                                    ? 'bg-primary-foreground text-primary'
                                                    : 'text-primary-foreground/85 hover:text-primary-foreground',
                                            )}
                                        >
                                            {p.label}
                                        </button>
                                    ))}
                                </div>
                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <MultiEntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={siteOptions}
                                        value={siteFilter}
                                        onChange={changeSite}
                                        onDark
                                    />
                                    <MultiEntityFilter
                                        label="Funding"
                                        allLabel="All funding"
                                        pluralLabel="funding streams"
                                        items={funderOptions}
                                        value={funderFilter}
                                        onChange={changeFunder}
                                        onDark
                                    />
                                </div>
                            </div>
                        }
                    />
                }
            >
                {/* §2 Finance hubs quick-links */}
                <FinanceHubsBar />

                {/* §3 Needs attention */}
                <NeedsAttentionStrip
                    items={ATTENTION_ITEMS}
                    subtitle="6 items due this week · bills, claims, payroll & GST"
                    viewAllHref="/finance/reports"
                />

                {/* §4 KPI cards */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard
                        label="Revenue"
                        value={formatMoneyCompact(totalRevenue)}
                        icon={TrendingUp}
                        tone="success"
                        delta={revenueTrend ? { percent: revenueTrend.percent, good: revenueTrend.percent >= 0 } : null}
                        sub="vs prev period"
                    />
                    <KpiCard
                        label="Net profit"
                        value={formatMoneyCompact(netProfit)}
                        icon={DollarSign}
                        tone="primary"
                        delta={profitTrend ? { percent: profitTrend.percent, good: profitTrend.percent >= 0 } : null}
                        sub={`${margin}% margin`}
                    />
                    <KpiCard
                        label="Cash position"
                        value={formatMoneyCompact(cashBalance)}
                        icon={Wallet}
                        tone="info"
                        sub={cashRunwayDays != null ? `${cashRunwayDays} days runway` : 'cash on hand'}
                    />
                    <KpiCard
                        label="AR outstanding"
                        value={formatMoneyCompact(accountsReceivable)}
                        icon={FileText}
                        tone="warning"
                        sub={arAging ? `${formatMoneyCompact(arAging.over60)} >60d` : 'receivables outstanding'}
                    />
                    <KpiCard
                        label="AP outstanding"
                        value={formatMoneyCompact(accountsPayable)}
                        icon={CreditCard}
                        tone="critical"
                        sub={`${apDueWithin7?.count ?? upcomingBillsDue.length} due ≤7d · ${formatMoneyCompact(apDueWithin7?.total ?? billsDueTotal)}`}
                    />
                    <KpiCard
                        label="Expenses"
                        value={formatMoneyCompact(totalExpenses)}
                        icon={TrendingDown}
                        tone="warning"
                        delta={expenseTrend ? { percent: expenseTrend.percent, good: expenseTrend.percent < 0 } : null}
                        sub="vs prev period"
                    />
                    {/* TODO(A): real prop in Phase B */}
                    <KpiCard label="Funding utilisation" value="87%" icon={Gauge} tone="primary" sub="target 90% · $54k unclaimed" />
                    {/* TODO(A): real prop in Phase B */}
                    <KpiCard label="Revenue / resident" value="$6,340" icon={Users} tone="success" sub="77 funded · benchmark $6.2k" />
                </div>

                {/* §5 Donut row */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <DonutCard
                        tone="primary"
                        title="Revenue by funding stream"
                        subtitle="Posted GL revenue this period"
                        segments={revenueStreamSegments}
                        centerValue={formatMoneyCompact(revenueStreamTotal)}
                        centerLabel="revenue"
                        accentKeys={[revenueStreamSegments[0]?.key ?? '']}
                        active={false}
                        cta="View funding streams"
                        onClick={() => router.visit('/finance/funding-streams')}
                        formatValue={(v) => formatMoneyCompact(v)}
                        showPercent
                    />
                    <DonutCard
                        tone="warning"
                        title="Funding claim utilisation"
                        subtitle="Delivered vs claimed vs paid"
                        segments={DONUT_UTILISATION}
                        centerValue="87%"
                        centerLabel="utilised"
                        accentKeys={['paid']}
                        active={false}
                        cta="Match remittances"
                        onClick={() => setModal(null)}
                        formatValue={fmtK}
                        showPercent
                    />
                    <DonutCard
                        tone="success"
                        title="Receivables aging"
                        subtitle="Outstanding by age bucket"
                        segments={arAgingDonut}
                        centerValue={formatMoneyCompact(arAging?.total ?? 0)}
                        centerLabel="AR"
                        accentKeys={['current']}
                        active={false}
                        cta="View aged receivables"
                        onClick={() => router.visit('/finance/reports/aged-receivables')}
                        formatValue={(v) => formatMoneyCompact(v)}
                        showPercent
                    />
                </div>

                {/* §6 Charts row */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-[1.35fr_1fr]">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base">Net profit trend</CardTitle>
                            {profitTrend ? (
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-[11.5px] font-bold tabular-nums',
                                        profitTrend.percent >= 0
                                            ? 'bg-status-success-bg text-status-success'
                                            : 'bg-status-critical-bg text-status-critical',
                                    )}
                                >
                                    {profitTrend.percent >= 0 ? '+' : ''}
                                    {profitTrend.percent.toFixed(1)}%
                                </span>
                            ) : null}
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={profitData}>
                                        <defs>
                                            <linearGradient id="npGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stopColor="var(--primary)" stopOpacity={0.28} />
                                                <stop offset="100%" stopColor="var(--primary)" stopOpacity={0.01} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                        <XAxis dataKey="month" tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <YAxis
                                            tick={{ fontSize: 11 }}
                                            className="fill-muted-foreground"
                                            tickFormatter={(v: number) => formatMoneyCompact(v)}
                                            width={56}
                                        />
                                        <Tooltip formatter={(value?: number) => formatMoney(value ?? 0)} />
                                        <Area
                                            type="monotone"
                                            dataKey="profit"
                                            stroke="var(--primary)"
                                            strokeWidth={2.6}
                                            fill="url(#npGradient)"
                                            name="Net profit"
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Revenue vs expenses</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                        <XAxis dataKey="month" tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <YAxis
                                            tick={{ fontSize: 11 }}
                                            className="fill-muted-foreground"
                                            tickFormatter={(v: number) => formatMoneyCompact(v)}
                                            width={56}
                                        />
                                        <Tooltip formatter={(value?: number) => formatMoney(value ?? 0)} />
                                        <Legend />
                                        <Bar dataKey="revenue" fill="var(--primary)" name="Revenue" radius={[3, 3, 0, 0]} />
                                        <Bar dataKey="expenses" fill="var(--status-warning)" name="Expenses" radius={[3, 3, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* §7 Tables row */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Upcoming bills due · next 7 days</CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/finance/bills">
                                    All bills <ArrowRight className="ml-1 h-4 w-4" />
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
                                            <TableHead>Due</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {upcomingBillsDue.map((bill) => (
                                            <TableRow key={bill.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/finance/bills/${bill.id}`}
                                                        className="font-semibold text-primary hover:underline"
                                                    >
                                                        {bill.bill_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{bill.vendor_name}</TableCell>
                                                <TableCell className="text-muted-foreground">{formatDate(bill.due_date)}</TableCell>
                                                <TableCell className="text-right font-semibold tabular-nums">
                                                    {formatMoney(bill.amount_due)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2">
                                Funding claims
                                <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-wide text-status-warning">
                                    supported living
                                </span>
                            </CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/finance/funding-streams">
                                    All claims <ArrowRight className="ml-1 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {/* TODO(D): real funding-claim pipeline data. */}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Ref</TableHead>
                                        <TableHead>Funder · period</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {FUNDING_CLAIMS.map((claim) => (
                                        <TableRow key={claim.ref}>
                                            <TableCell className="font-semibold text-primary">{claim.ref}</TableCell>
                                            <TableCell>
                                                <span className="block">{claim.funder}</span>
                                                <span className="block text-[11px] text-muted-foreground">{claim.period}</span>
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge status={claim.status} tone={claim.tone} label={claim.status} />
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatMoney(claim.amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                {/* §8 Recent journals */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Recent journals</CardTitle>
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/finance/journals">
                                All journals <ArrowRight className="ml-1 h-4 w-4" />
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
                                        <TableHead>Type</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentJournals.map((journal) => (
                                        <TableRow key={journal.id}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/journals/${journal.id}`}
                                                    className="font-semibold text-primary hover:underline"
                                                >
                                                    {journal.journal_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{formatDate(journal.journal_date)}</TableCell>
                                            <TableCell className="max-w-[280px] truncate">{journal.description ?? '—'}</TableCell>
                                            <TableCell>
                                                <span className="rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold capitalize text-primary">
                                                    {journal.type}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatMoney(journal.total_amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* §9 Footer */}
                <FinanceDashboardFooter orgName={orgName} />
            </PageLayout>

            {/* Quick-action wizard modals (reused dialogs). */}
            <NewJournalDialog
                open={modal === 'journal'}
                onClose={() => setModal(null)}
                accounts={accounts}
                costCentres={costCentres}
                fundingStreams={fundingStreams}
            />
            <NewBillDialog open={modal === 'bill'} onClose={() => setModal(null)} vendors={vendors} accounts={accounts} />
            <NewInvoiceDialog open={modal === 'invoice'} onClose={() => setModal(null)} clients={clients} taxRates={taxRates} />
            <RecordReceiptDialog open={modal === 'receipt'} onClose={() => setModal(null)} invoice={null} />
        </AppLayout>
    );
}
