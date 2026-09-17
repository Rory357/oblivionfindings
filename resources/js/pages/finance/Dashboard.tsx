import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpen,
    Clock,
    Coins,
    CreditCard,
    FileText,
    Landmark,
    LayoutDashboard,
    Percent,
    Plus,
    Receipt,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ComponentProps } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import {
    FinanceSectionRail,
    NewBillDialog,
    NewInvoiceDialog,
    NewJournalDialog,
    RecordReceiptDialog,
    StatusBadge,
    formatMoney,
    formatMoneyCompact,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { type DonutSegment } from '@/components/rostering/donut';
import { DonutCard } from '@/components/rostering/donut-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';

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

interface FundingClaim {
    reference: string;
    funder: string;
    period: string;
    status: string;
    amount: number;
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
    fundingClaims?: FundingClaim[];
    fundingUtilisation?: {
        claimed_paid: number;
        awaiting_remittance: number;
        delivered_unclaimed: number;
        write_off_risk: number;
        unclaimed_total: number;
        utilisation_pct: number;
    };
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
    payrollAwaitingApproval?: { count: number; total_gross: number };
    paydayFilingDue?: { count: number };
    recentJournals: RecentJournal[];
    fundedResidents?: number;
    revenuePerResident?: number;
    gstDue?: {
        due: string;
        amount: number | null;
        status: string;
        ref: string | null;
    } | null;
    openPeriodLabel?: string | null;
    siteCount?: number;
    regionCount?: number;
    period?: Period;
    periodLabel?: string;
    // Reference data for the quick-action wizard modals.
    accounts?: RefItem[];
    costCentres?: RefItem[];
    fundingStreams?: RefItem[];
    vendors?: NamedItem[];
    clients?: NamedItem[];
    taxRates?: TaxRateItem[];
    // Header filter options.
    siteOptions?: NamedItem[];
    funderOptions?: NamedItem[];
    orgName?: string;
}

type FinanceAbilities = {
    finance?: {
        dashboard?: boolean;
        ap?: { view?: boolean };
        bank?: { view?: boolean };
        ledger?: { view?: boolean };
        reports?: { view?: boolean };
    };
};

type Period = 'month' | 'quarter' | 'fy';
type Modal = null | 'journal' | 'bill' | 'invoice' | 'receipt';

type AttentionItem = {
    id: string;
    severity: 'critical' | 'warning' | 'info';
    icon: LucideIcon;
    title: string;
    body: string;
    tag: string;
    href?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Overview', href: '/finance' },
];

const PERIOD_OPTIONS: { value: Period; label: string }[] = [
    { value: 'month', label: 'This month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'fy', label: 'Financial year' },
];
const PERIOD_LABEL: Record<Period, string> = {
    month: 'This month',
    quarter: 'This quarter',
    fy: 'FY2026',
};

const ALL = 'all';

const SEVERITY_VARIANT: Record<
    AttentionItem['severity'],
    'critical' | 'warning' | 'info'
> = {
    critical: 'critical',
    warning: 'warning',
    info: 'info',
};

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

function computeTrend(data: MonthlyData[]): { percent: number } | null {
    if (data.length < 2) return null;
    const current = data[data.length - 1].amount;
    const previous = data[data.length - 2].amount;
    if (previous === 0) return null;

    return { percent: ((current - previous) / Math.abs(previous)) * 100 };
}

export default function FinanceDashboard({
    totalRevenue,
    totalExpenses,
    netProfit,
    cashBalance,
    accountsReceivable,
    revenueByMonth,
    expensesByMonth,
    revenueByFundingStream = [],
    fundingClaims = [],
    fundingUtilisation,
    arAging,
    upcomingBillsDue,
    apDueWithin7,
    cashRunwayDays,
    payrollAwaitingApproval,
    paydayFilingDue,
    recentJournals,
    gstDue,
    openPeriodLabel,
    siteCount,
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
    const [siteFilter, setSiteFilter] = useState<string>(ALL);
    const [funderFilter, setFunderFilter] = useState<string>(ALL);
    const [search, setSearch] = useState('');

    // A meter links to the list its number came from, but those lists live
    // behind their own permissions — a viewer holding only finance.dashboard
    // can read this page and would land on a 403. An unreachable destination
    // is dropped, so the block still shows its number without pretending to
    // be a way in.
    const can = usePage<{ auth?: { can?: FinanceAbilities } }>().props.auth?.can;
    const canReports = Boolean(can?.finance?.reports?.view);
    const canBills = Boolean(can?.finance?.ap?.view);
    const canCash = Boolean(can?.finance?.bank?.view || can?.finance?.dashboard);
    const canLedger = Boolean(can?.finance?.ledger?.view);
    const linkIf = (allowed: boolean, href: string) => (allowed ? href : undefined);

    const billCtx = useEntityContextMenu<UpcomingBill>();
    const claimCtx = useEntityContextMenu<FundingClaim>();
    const journalCtx = useEntityContextMenu<RecentJournal>();
    const attentionCtx = useEntityContextMenu<AttentionItem>();

    // Period / filter changes → real Inertia partial reload. `only` trims the
    // payload to the period-aware metric props (ref-data closures are skipped).
    const reload = (next: {
        period?: Period;
        site?: string;
        funder?: string;
    }) => {
        const siteValue = next.site ?? siteFilter;
        const funderValue = next.funder ?? funderFilter;

        router.reload({
            only: [
                'totalRevenue',
                'totalExpenses',
                'netProfit',
                'cashBalance',
                'accountsReceivable',
                'accountsPayable',
                'revenueByMonth',
                'expensesByMonth',
                'topExpenseCategories',
                'revenueByFundingStream',
                'fundingClaims',
                'fundingUtilisation',
                'arAging',
                'upcomingBillsDue',
                'apDueWithin7',
                'cashRunwayDays',
                'payrollAwaitingApproval',
                'paydayFilingDue',
                'fundedResidents',
                'revenuePerResident',
                'gstDue',
                'recentJournals',
                'period',
                'periodLabel',
            ],
            data: {
                period: next.period ?? period,
                site: siteValue === ALL ? [] : [Number(siteValue)],
                funder: funderValue === ALL ? [] : [Number(funderValue)],
            },
            preserveState: true,
            preserveScroll: true,
        });
    };
    const changePeriod = (p: Period) => {
        setPeriod(p);
        reload({ period: p });
    };
    const changeSite = (v: string) => {
        setSiteFilter(v);
        reload({ site: v });
    };
    const changeFunder = (v: string) => {
        setFunderFilter(v);
        reload({ funder: v });
    };

    /* ---------------- Derived numbers ---------------- */

    const revenueTrend = computeTrend(revenueByMonth);
    const profitTrend = computeTrend(
        revenueByMonth.map((rev, i) => ({
            month: rev.month,
            amount: rev.amount - (expensesByMonth[i]?.amount ?? 0),
        })),
    );
    const margin =
        totalRevenue > 0
            ? Math.round((netProfit / totalRevenue) * 1000) / 10
            : 0;
    const billsDueTotal = upcomingBillsDue.reduce(
        (sum, b) => sum + b.amount_due,
        0,
    );
    const billsDueCount = apDueWithin7?.count ?? upcomingBillsDue.length;
    const billsDueAmount = apDueWithin7?.total ?? billsDueTotal;
    const utilisationPct = fundingUtilisation?.utilisation_pct ?? 0;

    /* ---------------- Donut segments ---------------- */

    const revenueStreamTotal = revenueByFundingStream.reduce(
        (sum, s) => sum + s.amount,
        0,
    );
    const revenueStreamSegments: DonutSegment[] = revenueByFundingStream.length
        ? revenueByFundingStream.map((s, i) => ({
              key: `fs-${i}`,
              label: s.name,
              value: s.amount,
              color: chartColor(i),
          }))
        : [
              {
                  key: 'none',
                  label: 'No revenue in period',
                  value: 1,
                  color: 'var(--border)',
              },
          ];

    const arAgingSegments: DonutSegment[] =
        arAging && arAging.total > 0
            ? [
                  {
                      key: 'current',
                      label: 'Current',
                      value: arAging.current,
                      color: 'var(--status-success)',
                  },
                  {
                      key: 'd1_30',
                      label: '1–30 days',
                      value: arAging.d1_30,
                      color: 'var(--chart-2)',
                  },
                  {
                      key: 'd31_60',
                      label: '31–60 days',
                      value: arAging.d31_60,
                      color: 'var(--chart-4)',
                  },
                  {
                      key: 'd61_90',
                      label: '61–90 days',
                      value: arAging.d61_90,
                      color: 'var(--chart-3)',
                  },
                  {
                      key: 'd90_plus',
                      label: '90+ days',
                      value: arAging.d90_plus,
                      color: 'var(--status-critical)',
                  },
              ].filter((s) => s.value > 0)
            : [];
    const arAgingDonut: DonutSegment[] = arAgingSegments.length
        ? arAgingSegments
        : [
              {
                  key: 'none',
                  label: 'No outstanding receivables',
                  value: 1,
                  color: 'var(--border)',
              },
          ];

    const utilSegmentsRaw: DonutSegment[] = fundingUtilisation
        ? [
              {
                  key: 'paid',
                  label: 'Claimed & paid',
                  value: fundingUtilisation.claimed_paid,
                  color: 'var(--status-success)',
              },
              {
                  key: 'awaiting',
                  label: 'Awaiting remittance',
                  value: fundingUtilisation.awaiting_remittance,
                  color: 'var(--chart-2)',
              },
              {
                  key: 'delivered',
                  label: 'Delivered, not yet claimed',
                  value: fundingUtilisation.delivered_unclaimed,
                  color: 'var(--status-warning)',
              },
              {
                  key: 'writeoff',
                  label: 'Unfunded / write-off risk',
                  value: fundingUtilisation.write_off_risk,
                  color: 'var(--status-critical)',
              },
          ].filter((s) => s.value > 0)
        : [];
    const utilDonut: DonutSegment[] = utilSegmentsRaw.length
        ? utilSegmentsRaw
        : [
              {
                  key: 'none',
                  label: 'No funding activity',
                  value: 1,
                  color: 'var(--border)',
              },
          ];

    /* ---------------- Needs attention (real data only) ---------------- */

    const attentionItems: AttentionItem[] = [];
    if (arAging && arAging.d90_plus > 0) {
        attentionItems.push({
            id: 'ar90',
            severity: 'critical',
            icon: FileText,
            title: 'Receivables overdue past 90 days',
            body: 'Invoices aged past 90 days still need chasing.',
            tag: `${formatMoneyCompact(arAging.d90_plus)} · 90+ days`,
            href: '/finance/reports/aged-receivables',
        });
    }
    if (apDueWithin7 && apDueWithin7.count > 0) {
        attentionItems.push({
            id: 'bills7',
            severity: 'warning',
            icon: CreditCard,
            title: 'Bills due within 7 days',
            body: 'Approved bills falling due this week.',
            tag: `${apDueWithin7.count} · ${formatMoneyCompact(apDueWithin7.total)}`,
            href: '/finance/bills',
        });
    }
    if (payrollAwaitingApproval && payrollAwaitingApproval.count > 0) {
        attentionItems.push({
            id: 'payroll',
            severity: 'info',
            icon: Clock,
            title: 'Payroll run awaiting approval',
            body: `${payrollAwaitingApproval.count} run${payrollAwaitingApproval.count === 1 ? '' : 's'} not yet posted to the ledger.`,
            tag: formatMoneyCompact(payrollAwaitingApproval.total_gross),
        });
    }
    if (paydayFilingDue && paydayFilingDue.count > 0) {
        attentionItems.push({
            id: 'payday',
            severity: 'warning',
            icon: Percent,
            title: 'IRD payday filing due',
            body: 'Posted payroll runs still owe an employment information filing.',
            tag: `${paydayFilingDue.count} run${paydayFilingDue.count === 1 ? '' : 's'}`,
            href: '/finance/ird-filings',
        });
    }
    if (fundingUtilisation && fundingUtilisation.unclaimed_total > 0) {
        attentionItems.push({
            id: 'unclaimed',
            severity: 'warning',
            icon: Coins,
            title: 'Delivered hours not yet claimed',
            body: 'Service delivered without a funding claim raised.',
            tag: `${formatMoneyCompact(fundingUtilisation.unclaimed_total)} unclaimed`,
            href: '/finance/reports/funding-stream-summary',
        });
    }
    if (gstDue) {
        const dueLabel = new Date(gstDue.due).toLocaleDateString('en-NZ', {
            day: '2-digit',
            month: 'short',
        });
        attentionItems.push({
            id: 'gst',
            severity: gstDue.status === 'overdue' ? 'critical' : 'warning',
            icon: Landmark,
            title: 'GST return due',
            body: 'The period GST return filing deadline is approaching.',
            tag: `due ${dueLabel}`,
            href: '/finance/gst-returns',
        });
    }

    /* ---------------- Scoped search over the body lists ---------------- */

    const query = search.trim().toLowerCase();
    const matches = (...parts: (string | null | undefined)[]) =>
        query === '' ||
        parts.some((p) => (p ?? '').toLowerCase().includes(query));

    const bills = upcomingBillsDue.filter((b) =>
        matches(b.bill_number, b.vendor_name),
    );
    const claims = fundingClaims.filter((c) =>
        matches(c.reference, c.funder, c.period, c.status),
    );
    const journals = recentJournals.filter((j) =>
        matches(j.journal_number, j.description, j.type),
    );
    const attention = attentionItems.filter((a) => matches(a.title, a.body));

    /* ---------------- Charts ---------------- */

    const chartData = revenueByMonth.map((rev, i) => ({
        month: rev.month,
        revenue: rev.amount,
        expenses: expensesByMonth[i]?.amount ?? 0,
    }));
    const profitData = revenueByMonth.map((rev, i) => ({
        month: rev.month,
        profit: rev.amount - (expensesByMonth[i]?.amount ?? 0),
    }));
    const shortMonth = (m: string) => String(m).split(' ')[0];
    const lastProfitIdx = profitData.length - 1;
    const renderLastProfitLabel = (props: {
        x?: number;
        y?: number;
        value?: number;
        index?: number;
    }) => {
        if (props.index !== lastProfitIdx || props.x == null || props.y == null)
            return null;

        return (
            <text
                x={props.x}
                y={props.y - 12}
                textAnchor="middle"
                fontSize={12}
                fontWeight={700}
                fill={chartColor(0)}
            >
                {formatMoneyCompact(props.value ?? 0)}
            </text>
        );
    };

    /* ---------------- Row actions ---------------- */

    const billActions = (bill: UpcomingBill): MenuItem[] => [
        {
            label: 'Open bill',
            icon: Receipt,
            onClick: () => router.visit(`/finance/bills/${bill.id}`),
        },
        {
            label: 'All bills',
            icon: ArrowRight,
            onClick: () => router.visit('/finance/bills'),
        },
    ];
    const claimActions = (): MenuItem[] => [
        {
            label: 'Open funding summary',
            icon: Coins,
            onClick: () =>
                router.visit('/finance/reports/funding-stream-summary'),
        },
    ];
    const journalActions = (journal: RecentJournal): MenuItem[] => [
        {
            label: 'Open journal',
            icon: BookOpen,
            onClick: () => router.visit(`/finance/journals/${journal.id}`),
        },
        {
            label: 'All journals',
            icon: ArrowRight,
            onClick: () => router.visit('/finance/journals'),
        },
    ];
    const attentionActions = (item: AttentionItem): MenuItem[] =>
        compactMenu([
            item.href
                ? {
                      label: 'Open',
                      icon: ArrowRight,
                      onClick: () => router.visit(item.href as string),
                  }
                : null,
        ]);

    /* ---------------- Table columns ---------------- */

    const billColumns: EntityTableColumn<UpcomingBill>[] = [
        {
            key: 'due',
            label: 'Due',
            width: '130px',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.due_date)}
                </span>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '130px',
            align: 'right',
            cell: (b) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(b.amount_due)}
                </span>
            ),
        },
    ];

    const claimColumns: EntityTableColumn<FundingClaim>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '140px',
            cell: (c) => <StatusBadge status={c.status} size="sm" />,
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '130px',
            align: 'right',
            cell: (c) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(c.amount)}
                </span>
            ),
        },
    ];

    const journalColumns: EntityTableColumn<RecentJournal>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '130px',
            cell: (j) => (
                <span className="text-muted-foreground">
                    {formatDate(j.journal_date)}
                </span>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            width: '140px',
            cell: (j) => <StatusBadge status={j.type} size="sm" />,
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '130px',
            align: 'right',
            cell: (j) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(j.total_amount)}
                </span>
            ),
        },
    ];

    const attentionColumns: EntityTableColumn<AttentionItem>[] = [
        {
            key: 'tag',
            label: 'Detail',
            width: '220px',
            align: 'right',
            cell: (a) => (
                <EntityStatusChip variant={SEVERITY_VARIANT[a.severity]}>
                    {a.tag}
                </EntityStatusChip>
            ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={LayoutDashboard}
            title="Finance"
            titleChip={
                <PageHeaderStatusChip variant="info">
                    {openPeriodLabel
                        ? `Period ${openPeriodLabel}`
                        : (periodLabel ?? PERIOD_LABEL[period])}
                </PageHeaderStatusChip>
            }
            subline={`${orgName} · ${siteCount ?? 0} ${
                siteCount === 1 ? 'site' : 'sites'
            } · ${fundingStreams.length} funding ${
                fundingStreams.length === 1 ? 'stream' : 'streams'
            }`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search bills, claims, journals…"
                    />
                    <PageHeaderGlassButton
                        icon={Receipt}
                        onClick={() => setModal('bill')}
                    >
                        New bill
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton
                        icon={FileText}
                        onClick={() => setModal('invoice')}
                    >
                        New invoice
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton
                        icon={Wallet}
                        onClick={() => setModal('receipt')}
                    >
                        Record receipt
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setModal('journal')}
                    >
                        New journal
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Revenue"
                        href={linkIf(canReports, '/finance/reports/profit-loss')}
                        ariaLabel="View the profit and loss report"
                    >
                        <PageHeaderMeterBig>
                            {formatMoneyCompact(totalRevenue)}
                        </PageHeaderMeterBig>
                        {revenueTrend ? (
                            <PageHeaderMeterDelta
                                trend={
                                    revenueTrend.percent >= 0 ? 'up' : 'down'
                                }
                                good={revenueTrend.percent >= 0}
                            >
                                {Math.abs(revenueTrend.percent).toFixed(1)}% vs
                                previous
                            </PageHeaderMeterDelta>
                        ) : (
                            <PageHeaderMeterCaption>
                                no comparable previous period
                            </PageHeaderMeterCaption>
                        )}
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Net profit"
                        tone={netProfit >= 0 ? 'success' : 'critical'}
                        href={linkIf(canReports, '/finance/reports/profit-loss')}
                        ariaLabel="View net profit in the profit and loss report"
                    >
                        <PageHeaderMeterBig>
                            {formatMoneyCompact(netProfit)}
                        </PageHeaderMeterBig>
                        {profitTrend ? (
                            <PageHeaderMeterDelta
                                trend={profitTrend.percent >= 0 ? 'up' : 'down'}
                                good={profitTrend.percent >= 0}
                            >
                                {Math.abs(profitTrend.percent).toFixed(1)}% ·{' '}
                                {margin}% margin
                            </PageHeaderMeterDelta>
                        ) : (
                            <PageHeaderMeterCaption>
                                {margin}% margin
                            </PageHeaderMeterCaption>
                        )}
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Cash"
                        href={linkIf(canCash, '/finance/cash-position')}
                        ariaLabel="View the cash position"
                    >
                        <PageHeaderMeterBig>
                            {formatMoneyCompact(cashBalance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {cashRunwayDays != null
                                ? `${cashRunwayDays} days runway`
                                : 'cash on hand'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Receivables"
                        tone={
                            arAging && arAging.over60 > 0 ? 'warning' : 'brand'
                        }
                        href={linkIf(canReports, '/finance/reports/aged-receivables')}
                        ariaLabel="View aged receivables"
                    >
                        <PageHeaderMeterBig>
                            {formatMoneyCompact(accountsReceivable)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {arAging
                                ? `${formatMoneyCompact(arAging.over60)} over 60 days`
                                : 'outstanding invoices'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Bills due ≤ 7 days"
                        tone={billsDueCount > 0 ? 'critical' : 'success'}
                        href={linkIf(canBills, '/finance/bills')}
                        ariaLabel="View bills falling due"
                    >
                        <PageHeaderMeterBig>
                            {formatMoneyCompact(billsDueAmount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {billsDueCount}{' '}
                            {billsDueCount === 1 ? 'bill' : 'bills'} approved
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Funding utilisation"
                        value="target 90%"
                        href={linkIf(canReports, '/finance/reports/funding-stream-summary')}
                        ariaLabel="View the funding stream summary"
                    >
                        <PageHeaderMeterBig>
                            {utilisationPct}%
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={utilisationPct} />
                        <PageHeaderMeterCaption>
                            {fundingUtilisation
                                ? `${formatMoneyCompact(fundingUtilisation.unclaimed_total)} unclaimed`
                                : 'claimed vs delivered'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderViewToggle
                        value={period}
                        onChange={changePeriod}
                        ariaLabel="Reporting period"
                        options={PERIOD_OPTIONS}
                    />
                    <PageHeaderFilterSelect
                        label="All sites"
                        value={siteFilter}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'All sites' },
                            ...siteOptions.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            })),
                        ]}
                        onChange={changeSite}
                    />
                    <PageHeaderFilterSelect
                        label="All funding"
                        value={funderFilter}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'All funding' },
                            ...funderOptions.map((f) => ({
                                value: String(f.id),
                                label: f.name,
                            })),
                        ]}
                        onChange={changeFunder}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {/* Needs attention — built only from live metrics */}
                    {attentionItems.length > 0 ? (
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Needs attention"
                                caption={`${attention.length} of ${attentionItems.length} shown`}
                            />
                            {attention.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Clock}
                                    heading="Nothing matches your search"
                                    description="Clear the search to see every open item."
                                />
                            ) : (
                                <EntityTable
                                    rows={attention}
                                    rowKey={(a) => a.id}
                                    identityLabel="Item"
                                    identityWidth="2.4fr"
                                    minWidth={640}
                                    identity={(a) => ({
                                        icon: a.icon,
                                        name: a.title,
                                        subline: a.body,
                                    })}
                                    columns={attentionColumns}
                                    actionsFor={attentionActions}
                                    onOpen={(a) =>
                                        a.href
                                            ? router.visit(a.href)
                                            : undefined
                                    }
                                    onRowContextMenu={(e, a) => {
                                        if (a.href) attentionCtx.open(e, a);
                                    }}
                                />
                            )}
                        </div>
                    ) : null}

                    {/* Funding, revenue mix and receivables ageing */}
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <DonutCard
                            tone="primary"
                            title="Revenue by funding stream"
                            subtitle="Posted GL revenue this period"
                            segments={revenueStreamSegments}
                            centerValue={formatMoneyCompact(revenueStreamTotal)}
                            centerLabel="revenue"
                            accentKeys={[revenueStreamSegments[0]?.key ?? '']}
                            active={false}
                            cta="View funding summary"
                            onClick={() =>
                                router.visit(
                                    '/finance/reports/funding-stream-summary',
                                )
                            }
                            formatValue={(v) => formatMoneyCompact(v)}
                            showPercent
                        />
                        <DonutCard
                            tone="warning"
                            title="Funding claim utilisation"
                            subtitle="Delivered vs claimed vs paid"
                            segments={utilDonut}
                            centerValue={`${utilisationPct}%`}
                            centerLabel="utilised"
                            accentKeys={['paid']}
                            active={false}
                            cta="View funding summary"
                            onClick={() =>
                                router.visit(
                                    '/finance/reports/funding-stream-summary',
                                )
                            }
                            formatValue={(v) => formatMoneyCompact(v)}
                            showPercent
                        />
                        <DonutCard
                            tone="success"
                            title="Receivables ageing"
                            subtitle="Outstanding by age bucket"
                            segments={arAgingDonut}
                            centerValue={formatMoneyCompact(
                                arAging?.total ?? 0,
                            )}
                            centerLabel="receivables"
                            accentKeys={['current']}
                            active={false}
                            cta="View aged receivables"
                            onClick={() =>
                                router.visit(
                                    '/finance/reports/aged-receivables',
                                )
                            }
                            formatValue={(v) => formatMoneyCompact(v)}
                            showPercent
                        />
                    </div>

                    {/* Trend charts */}
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.35fr_1fr]">
                        <Card>
                            <CardHeader className="flex flex-row items-start justify-between">
                                <div>
                                    <CardTitle>Net profit trend</CardTitle>
                                    <p className="text-caption mt-0.5">
                                        Rolling 6 periods · NZD
                                    </p>
                                </div>
                                {profitTrend ? (
                                    <StatusBadge
                                        size="sm"
                                        variant={
                                            profitTrend.percent >= 0
                                                ? 'success'
                                                : 'critical'
                                        }
                                        label={`${profitTrend.percent >= 0 ? '+' : ''}${profitTrend.percent.toFixed(1)}%`}
                                    />
                                ) : null}
                            </CardHeader>
                            <CardContent>
                                <div className="h-64">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={profitData}
                                            margin={{
                                                top: 16,
                                                right: 16,
                                                bottom: 0,
                                                left: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="npGradient"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="0%"
                                                        stopColor={chartColor(
                                                            0,
                                                        )}
                                                        stopOpacity={0.28}
                                                    />
                                                    <stop
                                                        offset="100%"
                                                        stopColor={chartColor(
                                                            0,
                                                        )}
                                                        stopOpacity={0.01}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                vertical={false}
                                                className="stroke-border/60"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tickFormatter={shortMonth}
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                tickFormatter={(v: number) =>
                                                    formatMoneyCompact(v)
                                                }
                                                axisLine={false}
                                                tickLine={false}
                                                width={52}
                                            />
                                            <Tooltip
                                                formatter={(value?: number) =>
                                                    formatMoney(value ?? 0)
                                                }
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="profit"
                                                stroke={chartColor(0)}
                                                strokeWidth={2.6}
                                                fill="url(#npGradient)"
                                                name="Net profit"
                                                dot={{
                                                    r: 3.5,
                                                    fill: 'var(--card)',
                                                    stroke: chartColor(0),
                                                    strokeWidth: 2,
                                                }}
                                                activeDot={{
                                                    r: 5,
                                                    fill: chartColor(0),
                                                }}
                                            >
                                                <LabelList
                                                    dataKey="profit"
                                                    content={
                                                        renderLastProfitLabel as ComponentProps<
                                                            typeof LabelList
                                                        >['content']
                                                    }
                                                />
                                            </Area>
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Revenue vs expenses</CardTitle>
                                <p className="text-caption mt-0.5">
                                    Last 6 periods ·{' '}
                                    {formatMoneyCompact(totalExpenses)} expenses
                                </p>
                            </CardHeader>
                            <CardContent>
                                <div className="h-64">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={chartData}
                                            margin={{
                                                top: 8,
                                                right: 16,
                                                bottom: 0,
                                                left: 0,
                                            }}
                                        >
                                            <CartesianGrid
                                                vertical={false}
                                                className="stroke-border/60"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tickFormatter={shortMonth}
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                tickFormatter={(v: number) =>
                                                    formatMoneyCompact(v)
                                                }
                                                axisLine={false}
                                                tickLine={false}
                                                width={52}
                                            />
                                            <Tooltip
                                                formatter={(value?: number) =>
                                                    formatMoney(value ?? 0)
                                                }
                                                cursor={{
                                                    fill: 'var(--accent)',
                                                }}
                                            />
                                            <Legend
                                                verticalAlign="top"
                                                align="right"
                                                iconType="circle"
                                                iconSize={9}
                                                wrapperStyle={{
                                                    fontSize: 12,
                                                    paddingBottom: 8,
                                                }}
                                            />
                                            <Bar
                                                dataKey="revenue"
                                                fill={chartColor(0)}
                                                name="Revenue"
                                                radius={[3, 3, 0, 0]}
                                                maxBarSize={14}
                                            />
                                            <Bar
                                                dataKey="expenses"
                                                fill={chartColor(3)}
                                                name="Expenses"
                                                radius={[3, 3, 0, 0]}
                                                maxBarSize={14}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Upcoming bills + funding claims */}
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Upcoming bills due · next 7 days"
                                caption={`${bills.length} of ${upcomingBillsDue.length} shown`}
                                right={
                                    canBills ? (
                                        <Button asChild variant="outline" size="sm">
                                            <Link href="/finance/bills">
                                                All bills
                                                <ArrowRight className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    ) : null
                                }
                            />
                            {bills.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Receipt}
                                    heading={
                                        upcomingBillsDue.length === 0
                                            ? 'No bills due in the next 7 days'
                                            : 'No bills match your search'
                                    }
                                    description={
                                        upcomingBillsDue.length === 0
                                            ? 'Approved bills appear here as their due dates come within a week.'
                                            : 'Clear the search to see every bill due this week.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={bills}
                                    rowKey={(b) => b.id}
                                    identityLabel="Bill"
                                    minWidth={560}
                                    identity={(b) => ({
                                        icon: Receipt,
                                        name: b.bill_number,
                                        subline: b.vendor_name,
                                    })}
                                    hrefFor={(b) => `/finance/bills/${b.id}`}
                                    columns={billColumns}
                                    actionsFor={billActions}
                                    onOpen={(b) =>
                                        router.visit(`/finance/bills/${b.id}`)
                                    }
                                    onRowContextMenu={(e, b) =>
                                        billCtx.open(e, b)
                                    }
                                />
                            )}
                        </div>

                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Funding claims"
                                caption={`${claims.length} of ${fundingClaims.length} shown`}
                                right={
                                    canReports ? (
                                        <Button asChild variant="outline" size="sm">
                                            <Link href="/finance/reports/funding-stream-summary">
                                                All claims
                                                <ArrowRight className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    ) : null
                                }
                            />
                            {claims.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Coins}
                                    heading={
                                        fundingClaims.length === 0
                                            ? 'No funding claims yet'
                                            : 'No claims match your search'
                                    }
                                    description={
                                        fundingClaims.length === 0
                                            ? 'Claims appear here once delivered service is claimed against a funding stream.'
                                            : 'Clear the search to see every claim.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={claims}
                                    rowKey={(c) => c.reference}
                                    identityLabel="Claim"
                                    minWidth={560}
                                    identity={(c) => ({
                                        icon: Coins,
                                        name: c.reference,
                                        subline: `${c.funder} · ${c.period}`,
                                    })}
                                    columns={claimColumns}
                                    actionsFor={claimActions}
                                    onOpen={() =>
                                        router.visit(
                                            '/finance/reports/funding-stream-summary',
                                        )
                                    }
                                    onRowContextMenu={(e, c) =>
                                        claimCtx.open(e, c)
                                    }
                                />
                            )}
                        </div>
                    </div>

                    {/* Recent journals */}
                    <div className="flex flex-col gap-5">
                        <ListCaption
                            title="Recent journals"
                            caption={`${journals.length} of ${recentJournals.length} shown`}
                            right={
                                canLedger ? (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href="/finance/journals">
                                            All journals
                                            <ArrowRight className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                ) : null
                            }
                        />
                        {journals.length === 0 ? (
                            <EmptyState
                                variant="compact"
                                icon={BookOpen}
                                heading={
                                    recentJournals.length === 0
                                        ? 'No journal entries yet'
                                        : 'No journals match your search'
                                }
                                description={
                                    recentJournals.length === 0
                                        ? 'Post a journal to see the most recent ledger activity here.'
                                        : 'Clear the search to see every recent journal.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={journals}
                                rowKey={(j) => j.id}
                                identityLabel="Journal"
                                identityWidth="2.2fr"
                                identity={(j) => ({
                                    icon: BookOpen,
                                    name: j.journal_number,
                                    subline: j.description ?? '—',
                                })}
                                hrefFor={(j) => `/finance/journals/${j.id}`}
                                columns={journalColumns}
                                actionsFor={journalActions}
                                onOpen={(j) =>
                                    router.visit(`/finance/journals/${j.id}`)
                                }
                                onRowContextMenu={(e, j) =>
                                    journalCtx.open(e, j)
                                }
                            />
                        )}
                    </div>
                </div>
            </PageLayout>

            {attentionCtx.ctx ? (
                <EntityContextMenu
                    x={attentionCtx.ctx.x}
                    y={attentionCtx.ctx.y}
                    icon={attentionCtx.ctx.record.icon}
                    title={attentionCtx.ctx.record.title}
                    items={attentionActions(attentionCtx.ctx.record)}
                    onClose={attentionCtx.close}
                />
            ) : null}
            {billCtx.ctx ? (
                <EntityContextMenu
                    x={billCtx.ctx.x}
                    y={billCtx.ctx.y}
                    icon={Receipt}
                    title={billCtx.ctx.record.bill_number}
                    items={billActions(billCtx.ctx.record)}
                    onClose={billCtx.close}
                />
            ) : null}
            {claimCtx.ctx ? (
                <EntityContextMenu
                    x={claimCtx.ctx.x}
                    y={claimCtx.ctx.y}
                    icon={Coins}
                    title={claimCtx.ctx.record.reference}
                    items={claimActions()}
                    onClose={claimCtx.close}
                />
            ) : null}
            {journalCtx.ctx ? (
                <EntityContextMenu
                    x={journalCtx.ctx.x}
                    y={journalCtx.ctx.y}
                    icon={BookOpen}
                    title={journalCtx.ctx.record.journal_number}
                    items={journalActions(journalCtx.ctx.record)}
                    onClose={journalCtx.close}
                />
            ) : null}

            {/* Quick-action wizard modals (reused dialogs). */}
            <NewJournalDialog
                open={modal === 'journal'}
                onClose={() => setModal(null)}
                accounts={accounts}
                costCentres={costCentres}
                fundingStreams={fundingStreams}
            />
            <NewBillDialog
                open={modal === 'bill'}
                onClose={() => setModal(null)}
                vendors={vendors}
                accounts={accounts}
            />
            <NewInvoiceDialog
                open={modal === 'invoice'}
                onClose={() => setModal(null)}
                clients={clients}
                taxRates={taxRates}
            />
            <RecordReceiptDialog
                open={modal === 'receipt'}
                onClose={() => setModal(null)}
                invoice={null}
            />
        </AppLayout>
    );
}
