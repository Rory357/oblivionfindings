import { MiniSparkline } from '@/components/dashboard/mini-sparkline';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Link } from '@inertiajs/react';
import {
    Banknote,
    ChevronRight,
    Download,
    Eye,
    FileText,
    Landmark,
    TrendingUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Tooltip as RechartsTooltip,
    ResponsiveContainer,
    XAxis,
    YAxis,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Payslip {
    id: number;
    pay_period_start: string;
    pay_period_end: string;
    gross_pay: string;
    net_pay: string;
    paye: string;
    acc_levy: string;
    kiwisaver_employee: string;
    student_loan: string;
    holiday_pay: string;
    total_deductions: string;
    status: 'draft' | 'approved' | 'paid';
    payment_date: string | null;
}

interface Props {
    myHr: MyHrShellData;
    payslips: {
        data: Payslip[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function nzd(amount: string | number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        minimumFractionDigits: 2,
    }).format(Number(amount));
}

function nzdShort(amount: string | number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(Number(amount));
}

function formatDate(d: string | null): string {
    if (!d) return '\u2014';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatPeriod(start: string, end: string): string {
    const s = new Date(start);
    const e = new Date(end);
    const sMonth = s.toLocaleDateString('en-NZ', { month: 'short' });
    const eMonth = e.toLocaleDateString('en-NZ', {
        month: 'short',
        year: 'numeric',
    });
    return `${s.getDate()} ${sMonth} \u2013 ${e.getDate()} ${eMonth}`;
}

const STATUS_CONFIG = {
    draft: {
        label: 'Draft',
        bg: 'bg-status-warning',
        text: 'text-status-warning dark:text-status-warning',
        border: 'border-status-warning/30',
    },
    approved: {
        label: 'Approved',
        bg: 'bg-status-info',
        text: 'text-status-info dark:text-status-info',
        border: 'border-status-info/30',
    },
    paid: {
        label: 'Paid',
        bg: 'bg-status-success',
        text: 'text-status-success dark:text-status-success',
        border: 'border-status-success/30',
    },
} as const;

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyPayslips({ myHr, payslips }: Props) {
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [yearFilter, setYearFilter] = useState<string>('all');

    // Available years from data
    const years = useMemo(() => {
        const yrs = new Set<number>();
        payslips.data.forEach((p) =>
            yrs.add(new Date(p.pay_period_end).getFullYear()),
        );
        return Array.from(yrs).sort((a, b) => b - a);
    }, [payslips.data]);

    const filteredPayslips = useMemo(() => {
        if (yearFilter === 'all') return payslips.data;
        return payslips.data.filter(
            (p) =>
                new Date(p.pay_period_end).getFullYear() === Number(yearFilter),
        );
    }, [payslips.data, yearFilter]);

    const { latest, ytdGross, ytdPaye, netTrend, avgNet, chartData } =
        useMemo(() => {
            const all = payslips.data;
            const latest = all[0] ?? null;
            const currentYear = new Date().getFullYear();
            const ytdSlips = all.filter(
                (p) => new Date(p.pay_period_end).getFullYear() === currentYear,
            );

            const ytdGross = ytdSlips.reduce(
                (s, p) => s + Number(p.gross_pay),
                0,
            );
            const ytdPaye = ytdSlips.reduce((s, p) => s + Number(p.paye), 0);

            const netTrend = all
                .slice(0, 6)
                .map((p) => Number(p.net_pay))
                .reverse();

            const avgNet =
                netTrend.length > 0
                    ? netTrend.reduce((a, b) => a + b, 0) / netTrend.length
                    : 0;

            // Chart data (reversed so oldest first)
            const chartData = [...all].reverse().map((p) => ({
                period: new Date(p.pay_period_end).toLocaleDateString('en-NZ', {
                    day: 'numeric',
                    month: 'short',
                }),
                net: Number(p.net_pay),
                gross: Number(p.gross_pay),
            }));

            return {
                latest,
                ytdGross,
                ytdPaye,
                netTrend,
                avgNet,
                chartData,
            };
        }, [payslips.data]);

    return (
        <MyHrShell active="payslips" myHr={myHr} title="Payslips · My HR">
            {/* Summary Cards */}
                {payslips.data.length > 0 && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        {/* Latest Net Pay */}
                        <Card
                            role="button"
                            tabIndex={0}
                            onClick={() =>
                                latest &&
                                setExpandedId(
                                    expandedId === latest.id ? null : latest.id,
                                )
                            }
                            onKeyDown={(event) => {
                                if (
                                    (event.key === 'Enter' ||
                                        event.key === ' ') &&
                                    latest
                                ) {
                                    event.preventDefault();
                                    setExpandedId(
                                        expandedId === latest.id
                                            ? null
                                            : latest.id,
                                    );
                                }
                            }}
                            className="cursor-pointer overflow-hidden text-left transition-all hover:border-status-success/40 hover:shadow-md"
                        >
                            <div className="h-1 bg-status-success" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            Latest Net Pay
                                        </p>
                                        <p className="mt-1 text-2xl font-bold text-status-success dark:text-status-success">
                                            {latest
                                                ? nzd(latest.net_pay)
                                                : '\u2014'}
                                        </p>
                                        {latest?.payment_date && (
                                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                Paid{' '}
                                                {formatDate(
                                                    latest.payment_date,
                                                )}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-status-success-bg transition-transform group-hover:scale-110">
                                        <Banknote className="h-5 w-5 text-status-success" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* YTD Gross */}
                        <Card className="overflow-hidden transition-all hover:border-status-info/40 hover:shadow-md">
                            <div className="h-1 bg-status-info" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            YTD Gross
                                        </p>
                                        <p className="mt-1 text-2xl font-bold">
                                            {nzdShort(ytdGross)}
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            {new Date().getFullYear()} year to
                                            date
                                        </p>
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-status-info-bg">
                                        <TrendingUp className="h-5 w-5 text-status-info" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* YTD PAYE */}
                        <Card className="overflow-hidden transition-all hover:border-status-warning/40 hover:shadow-md">
                            <div className="h-1 bg-status-warning" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            YTD PAYE
                                        </p>
                                        <p className="mt-1 text-2xl font-bold">
                                            {nzdShort(ytdPaye)}
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            Tax paid this year
                                        </p>
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-status-warning-bg">
                                        <Landmark className="h-5 w-5 text-status-warning" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Net Pay Trend */}
                        <Card className="overflow-hidden transition-all hover:border-primary/40 hover:shadow-md">
                            <div className="h-1 bg-primary" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            Avg Net Pay
                                        </p>
                                        <p className="mt-1 text-2xl font-bold">
                                            {nzdShort(avgNet)}
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            Last {netTrend.length} payslips
                                        </p>
                                    </div>
                                    <div className="flex flex-col items-center gap-1">
                                        {netTrend.length > 1 && (
                                            <MiniSparkline
                                                data={netTrend}
                                                width={64}
                                                height={28}
                                                color="var(--primary)"
                                            />
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Net Pay Trend Chart */}
                {chartData.length > 2 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Net Pay Trend
                                </CardTitle>
                                <Badge
                                    variant="secondary"
                                    className="font-mono text-xs"
                                >
                                    {chartData.length} periods
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={180}>
                                <AreaChart
                                    data={chartData}
                                    margin={{
                                        top: 5,
                                        right: 10,
                                        bottom: 0,
                                        left: -10,
                                    }}
                                >
                                    <defs>
                                        <linearGradient
                                            id="netGradient"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="0%"
                                                stopColor="var(--status-success)"
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="100%"
                                                stopColor="var(--status-success)"
                                                stopOpacity={0.02}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <XAxis
                                        dataKey="period"
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{
                                            fontSize: 11,
                                            fill: 'var(--muted-foreground)',
                                        }}
                                    />
                                    <YAxis
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{
                                            fontSize: 10,
                                            fill: 'var(--muted-foreground)',
                                        }}
                                        tickFormatter={(v: number) =>
                                            `$${(v / 1000).toFixed(1)}k`
                                        }
                                        width={48}
                                    />
                                    <RechartsTooltip
                                        formatter={(value: any) => [
                                            nzd(value),
                                            '',
                                        ]}
                                        labelStyle={{ fontWeight: 600 }}
                                        contentStyle={{
                                            backgroundColor: 'var(--card)',
                                            border: '1px solid var(--border)',
                                            borderRadius: '8px',
                                            fontSize: '12px',
                                        }}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="net"
                                        stroke="var(--status-success)"
                                        strokeWidth={2}
                                        fill="url(#netGradient)"
                                        name="Net Pay"
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

                {/* Payslip List */}
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-base font-semibold">Pay History</h2>
                        <div className="flex items-center gap-3">
                            {/* Year filter tabs */}
                            {years.length > 1 && (
                                <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-0.5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setYearFilter('all')}
                                        className={`h-7 px-2.5 text-xs ${
                                            yearFilter === 'all'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        All
                                    </Button>
                                    {years.map((y) => (
                                        <Button
                                            key={y}
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setYearFilter(String(y))
                                            }
                                            className={`h-7 px-2.5 text-xs ${
                                                yearFilter === String(y)
                                                    ? 'bg-background text-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {y}
                                        </Button>
                                    ))}
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {filteredPayslips.length} payslip
                                {filteredPayslips.length !== 1 ? 's' : ''}
                            </p>
                        </div>
                    </div>

                    {filteredPayslips.length > 0 ? (
                        <div className="space-y-3">
                            {filteredPayslips.map((p) => {
                                const config =
                                    STATUS_CONFIG[p.status] ??
                                    STATUS_CONFIG.draft;
                                const isExpanded = expandedId === p.id;

                                return (
                                    <Card
                                        key={p.id}
                                        className="overflow-hidden transition-all hover:shadow-sm"
                                    >
                                        <div className="h-0.5 bg-status-success" />
                                        <CardContent className="p-0">
                                            {/* Main row */}
                                            <div
                                                role="button"
                                                tabIndex={0}
                                                onClick={() =>
                                                    setExpandedId(
                                                        isExpanded
                                                            ? null
                                                            : p.id,
                                                    )
                                                }
                                                onKeyDown={(event) => {
                                                    if (
                                                        event.key === 'Enter' ||
                                                        event.key === ' '
                                                    ) {
                                                        event.preventDefault();
                                                        setExpandedId(
                                                            isExpanded
                                                                ? null
                                                                : p.id,
                                                        );
                                                    }
                                                }}
                                                className="flex w-full items-center gap-4 p-4 text-left transition-colors hover:bg-muted/30"
                                            >
                                                {/* Date icon */}
                                                <div className="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-muted/50">
                                                    <span className="text-[10px] leading-none font-medium text-muted-foreground uppercase">
                                                        {new Date(
                                                            p.pay_period_end,
                                                        ).toLocaleDateString(
                                                            'en-NZ',
                                                            { month: 'short' },
                                                        )}
                                                    </span>
                                                    <span className="text-lg leading-tight font-bold">
                                                        {new Date(
                                                            p.pay_period_end,
                                                        ).getDate()}
                                                    </span>
                                                </div>

                                                {/* Period + status */}
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-semibold">
                                                        {formatPeriod(
                                                            p.pay_period_start,
                                                            p.pay_period_end,
                                                        )}
                                                    </p>
                                                    <div className="mt-1 flex items-center gap-2">
                                                        <Badge
                                                            variant="outline"
                                                            className={`text-[10px] ${config.border} ${config.text} ${config.bg}`}
                                                        >
                                                            {config.label}
                                                        </Badge>
                                                        {p.payment_date && (
                                                            <span className="text-[11px] text-muted-foreground">
                                                                Paid{' '}
                                                                {formatDate(
                                                                    p.payment_date,
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Inline take-home bar (fills middle gap) */}
                                                <div className="hidden flex-1 items-center px-6 lg:flex">
                                                    <div className="flex h-2 w-full max-w-[200px] overflow-hidden rounded-full">
                                                        <div
                                                            className="rounded-l-full bg-status-success"
                                                            style={{
                                                                width: `${(Number(p.net_pay) / Number(p.gross_pay)) * 100}%`,
                                                            }}
                                                        />
                                                        <div
                                                            className="rounded-r-full bg-status-critical"
                                                            style={{
                                                                width: `${(Number(p.total_deductions) / Number(p.gross_pay)) * 100}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="ml-2 text-[10px] whitespace-nowrap text-muted-foreground">
                                                        {(
                                                            (Number(p.net_pay) /
                                                                Number(
                                                                    p.gross_pay,
                                                                )) *
                                                            100
                                                        ).toFixed(0)}
                                                        % take-home
                                                    </span>
                                                </div>

                                                {/* Amounts */}
                                                <div className="hidden items-center gap-6 text-right sm:flex">
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">
                                                            Gross
                                                        </p>
                                                        <p className="text-sm font-medium">
                                                            {nzd(p.gross_pay)}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">
                                                            Deductions
                                                        </p>
                                                        <p className="text-sm font-medium text-status-critical">
                                                            {nzd(
                                                                p.total_deductions,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">
                                                            Net Pay
                                                        </p>
                                                        <p className="text-base font-bold text-status-success dark:text-status-success">
                                                            {nzd(p.net_pay)}
                                                        </p>
                                                    </div>
                                                </div>

                                                {/* Mobile net pay */}
                                                <div className="text-right sm:hidden">
                                                    <p className="font-bold text-status-success dark:text-status-success">
                                                        {nzd(p.net_pay)}
                                                    </p>
                                                </div>

                                                <ChevronRight
                                                    className={`h-4 w-4 shrink-0 text-muted-foreground/50 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                                                />
                                            </div>

                                            {/* Expanded breakdown */}
                                            {isExpanded && (
                                                <div className="border-t bg-muted/20 px-4 py-4">
                                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                        {/* Earnings */}
                                                        <div>
                                                            <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                                Earnings
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>
                                                                        Gross
                                                                        Pay
                                                                    </span>
                                                                    <span className="font-medium">
                                                                        {nzd(
                                                                            p.gross_pay,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                                {Number(
                                                                    p.holiday_pay,
                                                                ) > 0 && (
                                                                    <div className="flex justify-between text-muted-foreground">
                                                                        <span>
                                                                            Holiday
                                                                            Pay
                                                                        </span>
                                                                        <span>
                                                                            {nzd(
                                                                                p.holiday_pay,
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>

                                                        {/* Tax */}
                                                        <div>
                                                            <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                                Tax
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>
                                                                        PAYE
                                                                    </span>
                                                                    <span className="font-medium text-status-critical">
                                                                        {nzd(
                                                                            p.paye,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                                <div className="flex justify-between text-muted-foreground">
                                                                    <span>
                                                                        ACC Levy
                                                                    </span>
                                                                    <span>
                                                                        {nzd(
                                                                            p.acc_levy,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                                {Number(
                                                                    p.student_loan,
                                                                ) > 0 && (
                                                                    <div className="flex justify-between text-muted-foreground">
                                                                        <span>
                                                                            Student
                                                                            Loan
                                                                        </span>
                                                                        <span>
                                                                            {nzd(
                                                                                p.student_loan,
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>

                                                        {/* KiwiSaver */}
                                                        <div>
                                                            <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                                KiwiSaver
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>
                                                                        Employee
                                                                    </span>
                                                                    <span className="font-medium">
                                                                        {nzd(
                                                                            p.kiwisaver_employee,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Summary */}
                                                        <div>
                                                            <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                                Summary
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>
                                                                        Total
                                                                        Deductions
                                                                    </span>
                                                                    <span className="font-medium text-status-critical">
                                                                        {nzd(
                                                                            p.total_deductions,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                                <div className="flex justify-between border-t pt-1.5">
                                                                    <span className="font-semibold">
                                                                        Net Pay
                                                                    </span>
                                                                    <span className="font-bold text-status-success dark:text-status-success">
                                                                        {nzd(
                                                                            p.net_pay,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {/* Deduction bar */}
                                                    <div className="mt-4">
                                                        <div className="mb-1 flex items-center justify-between text-[10px] text-muted-foreground">
                                                            <span>
                                                                Take-home ratio
                                                            </span>
                                                            <span>
                                                                {Number(
                                                                    p.gross_pay,
                                                                ) > 0
                                                                    ? `${((Number(p.net_pay) / Number(p.gross_pay)) * 100).toFixed(1)}%`
                                                                    : '\u2014'}
                                                            </span>
                                                        </div>
                                                        <div className="flex h-2 w-full overflow-hidden rounded-full">
                                                            <div
                                                                className="rounded-l-full bg-status-success"
                                                                style={{
                                                                    width: `${(Number(p.net_pay) / Number(p.gross_pay)) * 100}%`,
                                                                }}
                                                            />
                                                            <div
                                                                className="bg-status-critical"
                                                                style={{
                                                                    width: `${(Number(p.paye) / Number(p.gross_pay)) * 100}%`,
                                                                }}
                                                            />
                                                            <div
                                                                className="bg-status-warning"
                                                                style={{
                                                                    width: `${(Number(p.kiwisaver_employee) / Number(p.gross_pay)) * 100}%`,
                                                                }}
                                                            />
                                                            <div
                                                                className="rounded-r-full bg-muted dark:bg-muted-foreground/80"
                                                                style={{
                                                                    width: `${((Number(p.acc_levy) + Number(p.student_loan)) / Number(p.gross_pay)) * 100}%`,
                                                                }}
                                                            />
                                                        </div>
                                                        <div className="mt-1.5 flex flex-wrap gap-3 text-[10px]">
                                                            <span className="flex items-center gap-1">
                                                                <span className="h-2 w-2 rounded-full bg-status-success" />
                                                                Net
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <span className="h-2 w-2 rounded-full bg-status-critical" />
                                                                PAYE
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <span className="h-2 w-2 rounded-full bg-status-warning" />
                                                                KiwiSaver
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <span className="h-2 w-2 rounded-full bg-muted dark:bg-muted-foreground/80" />
                                                                Other
                                                            </span>
                                                        </div>
                                                    </div>

                                                    {/* Actions */}
                                                    <div className="mt-4 flex gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/my/payslips/${p.id}`}
                                                            >
                                                                <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                                View Full
                                                                Payslip
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/my/payslips/${p.id}/download`}
                                                            >
                                                                <Download className="mr-1.5 h-3.5 w-3.5" />
                                                                Download PDF
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-3 py-12">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                                    <FileText className="h-7 w-7 text-muted-foreground/50" />
                                </div>
                                <div className="text-center">
                                    <p className="font-medium">
                                        No payslips yet
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Your payslips will appear here after
                                        each pay run is processed
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Pagination */}
                    {payslips.total > 0 && (
                        <div className="mt-4 flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">
                                Showing{' '}
                                {(payslips.current_page - 1) *
                                    payslips.per_page +
                                    1}{' '}
                                to{' '}
                                {Math.min(
                                    payslips.current_page * payslips.per_page,
                                    payslips.total,
                                )}{' '}
                                of {payslips.total} payslip
                                {payslips.total !== 1 ? 's' : ''}
                            </p>
                            <LaravelPagination links={payslips.links} />
                        </div>
                    )}
                </div>
        </MyHrShell>
    );
}
