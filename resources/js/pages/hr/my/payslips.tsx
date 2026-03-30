import { MiniSparkline } from '@/components/dashboard/mini-sparkline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    Banknote,
    Calendar,
    ChevronRight,
    Download,
    Eye,
    FileText,
    Landmark,
    TrendingDown,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Payslips', href: '/hr/my/payslips' },
];

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
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatPeriod(start: string, end: string): string {
    const s = new Date(start);
    const e = new Date(end);
    const sMonth = s.toLocaleDateString('en-NZ', { month: 'short' });
    const eMonth = e.toLocaleDateString('en-NZ', { month: 'short', year: 'numeric' });
    return `${s.getDate()} ${sMonth} \u2013 ${e.getDate()} ${eMonth}`;
}

const STATUS_CONFIG = {
    draft: { label: 'Draft', bg: 'bg-yellow-500/10', text: 'text-yellow-600 dark:text-yellow-400', border: 'border-yellow-500/30' },
    approved: { label: 'Approved', bg: 'bg-blue-500/10', text: 'text-blue-600 dark:text-blue-400', border: 'border-blue-500/30' },
    paid: { label: 'Paid', bg: 'bg-emerald-500/10', text: 'text-emerald-600 dark:text-emerald-400', border: 'border-emerald-500/30' },
} as const;

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyPayslips({ payslips }: Props) {
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const { latest, ytdGross, ytdNet, ytdPaye, ytdKiwi, netTrend, avgNet } = useMemo(() => {
        const all = payslips.data;
        const latest = all[0] ?? null;
        const currentYear = new Date().getFullYear();
        const ytdSlips = all.filter((p) => new Date(p.pay_period_end).getFullYear() === currentYear);

        const ytdGross = ytdSlips.reduce((s, p) => s + Number(p.gross_pay), 0);
        const ytdNet = ytdSlips.reduce((s, p) => s + Number(p.net_pay), 0);
        const ytdPaye = ytdSlips.reduce((s, p) => s + Number(p.paye), 0);
        const ytdKiwi = ytdSlips.reduce((s, p) => s + Number(p.kiwisaver_employee), 0);

        const netTrend = all
            .slice(0, 6)
            .map((p) => Number(p.net_pay))
            .reverse();

        const avgNet = netTrend.length > 0 ? netTrend.reduce((a, b) => a + b, 0) / netTrend.length : 0;

        return { latest, ytdGross, ytdNet, ytdPaye, ytdKiwi, netTrend, avgNet };
    }, [payslips.data]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Payslips" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                            <Wallet className="h-5 w-5 text-emerald-600" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold md:text-2xl">My Payslips</h1>
                            <p className="text-sm text-muted-foreground">
                                View your pay history and download payslips
                            </p>
                        </div>
                    </div>
                    <Link href="/hr/my">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-1.5 h-4 w-4" />
                            My HR
                        </Button>
                    </Link>
                </div>

                {/* Summary Cards */}
                {payslips.data.length > 0 && (
                    <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
                        {/* Latest Net Pay */}
                        <button
                            onClick={() => latest && setExpandedId(expandedId === latest.id ? null : latest.id)}
                            className="text-left"
                        >
                        <Card className="overflow-hidden cursor-pointer transition-all hover:shadow-md hover:border-emerald-500/40">
                            <div className="h-1 bg-emerald-500" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">Latest Net Pay</p>
                                        <p className="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                            {latest ? nzd(latest.net_pay) : '\u2014'}
                                        </p>
                                        {latest?.payment_date && (
                                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                Paid {formatDate(latest.payment_date)}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500/10 transition-transform group-hover:scale-110">
                                        <Banknote className="h-5 w-5 text-emerald-600" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        </button>

                        {/* YTD Gross */}
                        <Card className="overflow-hidden transition-all hover:shadow-md hover:border-blue-500/40">
                            <div className="h-1 bg-blue-500" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">YTD Gross</p>
                                        <p className="mt-1 text-2xl font-bold">{nzdShort(ytdGross)}</p>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            {new Date().getFullYear()} year to date
                                        </p>
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-500/10">
                                        <TrendingUp className="h-5 w-5 text-blue-600" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* YTD PAYE */}
                        <Card className="overflow-hidden transition-all hover:shadow-md hover:border-amber-500/40">
                            <div className="h-1 bg-amber-500" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">YTD PAYE</p>
                                        <p className="mt-1 text-2xl font-bold">{nzdShort(ytdPaye)}</p>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            Tax paid this year
                                        </p>
                                    </div>
                                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10">
                                        <Landmark className="h-5 w-5 text-amber-600" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Net Pay Trend */}
                        <Card className="overflow-hidden transition-all hover:shadow-md hover:border-violet-500/40">
                            <div className="h-1 bg-violet-500" />
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground">Avg Net Pay</p>
                                        <p className="mt-1 text-2xl font-bold">{nzdShort(avgNet)}</p>
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
                                                color="#8b5cf6"
                                            />
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Payslip List */}
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-base font-semibold">Pay History</h2>
                        {payslips.total > 0 && (
                            <p className="text-xs text-muted-foreground">{payslips.total} payslip{payslips.total !== 1 ? 's' : ''}</p>
                        )}
                    </div>

                    {payslips.data.length > 0 ? (
                        <div className="space-y-3">
                            {payslips.data.map((p) => {
                                const config = STATUS_CONFIG[p.status] ?? STATUS_CONFIG.draft;
                                const isExpanded = expandedId === p.id;

                                return (
                                    <Card
                                        key={p.id}
                                        className="overflow-hidden transition-all hover:shadow-sm"
                                    >
                                        <div className="h-0.5 bg-emerald-500" />
                                        <CardContent className="p-0">
                                            {/* Main row */}
                                            <button
                                                onClick={() => setExpandedId(isExpanded ? null : p.id)}
                                                className="flex w-full items-center gap-4 p-4 text-left transition-colors hover:bg-muted/30"
                                            >
                                                {/* Date icon */}
                                                <div className="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-muted/50">
                                                    <span className="text-[10px] font-medium uppercase text-muted-foreground leading-none">
                                                        {new Date(p.pay_period_end).toLocaleDateString('en-NZ', { month: 'short' })}
                                                    </span>
                                                    <span className="text-lg font-bold leading-tight">
                                                        {new Date(p.pay_period_end).getDate()}
                                                    </span>
                                                </div>

                                                {/* Period + status */}
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-semibold text-sm">
                                                        {formatPeriod(p.pay_period_start, p.pay_period_end)}
                                                    </p>
                                                    <div className="mt-1 flex items-center gap-2">
                                                        <Badge variant="outline" className={`text-[10px] ${config.border} ${config.text} ${config.bg}`}>
                                                            {config.label}
                                                        </Badge>
                                                        {p.payment_date && (
                                                            <span className="text-[11px] text-muted-foreground">
                                                                Paid {formatDate(p.payment_date)}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Amounts */}
                                                <div className="hidden sm:flex items-center gap-6 text-right">
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Gross</p>
                                                        <p className="font-medium text-sm">{nzd(p.gross_pay)}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Deductions</p>
                                                        <p className="font-medium text-sm text-red-500">{nzd(p.total_deductions)}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Net Pay</p>
                                                        <p className="font-bold text-base text-emerald-600 dark:text-emerald-400">{nzd(p.net_pay)}</p>
                                                    </div>
                                                </div>

                                                {/* Mobile net pay */}
                                                <div className="sm:hidden text-right">
                                                    <p className="font-bold text-emerald-600 dark:text-emerald-400">{nzd(p.net_pay)}</p>
                                                </div>

                                                <ChevronRight className={`h-4 w-4 shrink-0 text-muted-foreground/50 transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                                            </button>

                                            {/* Expanded breakdown */}
                                            {isExpanded && (
                                                <div className="border-t bg-muted/20 px-4 py-4">
                                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                        {/* Earnings */}
                                                        <div>
                                                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                                                                Earnings
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>Gross Pay</span>
                                                                    <span className="font-medium">{nzd(p.gross_pay)}</span>
                                                                </div>
                                                                {Number(p.holiday_pay) > 0 && (
                                                                    <div className="flex justify-between text-muted-foreground">
                                                                        <span>Holiday Pay</span>
                                                                        <span>{nzd(p.holiday_pay)}</span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>

                                                        {/* Tax */}
                                                        <div>
                                                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                                                                Tax
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>PAYE</span>
                                                                    <span className="font-medium text-red-500">{nzd(p.paye)}</span>
                                                                </div>
                                                                <div className="flex justify-between text-muted-foreground">
                                                                    <span>ACC Levy</span>
                                                                    <span>{nzd(p.acc_levy)}</span>
                                                                </div>
                                                                {Number(p.student_loan) > 0 && (
                                                                    <div className="flex justify-between text-muted-foreground">
                                                                        <span>Student Loan</span>
                                                                        <span>{nzd(p.student_loan)}</span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>

                                                        {/* KiwiSaver */}
                                                        <div>
                                                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                                                                KiwiSaver
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>Employee</span>
                                                                    <span className="font-medium">{nzd(p.kiwisaver_employee)}</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Summary */}
                                                        <div>
                                                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                                                                Summary
                                                            </h4>
                                                            <div className="space-y-1.5 text-sm">
                                                                <div className="flex justify-between">
                                                                    <span>Total Deductions</span>
                                                                    <span className="font-medium text-red-500">{nzd(p.total_deductions)}</span>
                                                                </div>
                                                                <div className="flex justify-between border-t pt-1.5">
                                                                    <span className="font-semibold">Net Pay</span>
                                                                    <span className="font-bold text-emerald-600 dark:text-emerald-400">{nzd(p.net_pay)}</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {/* Deduction bar */}
                                                    <div className="mt-4">
                                                        <div className="flex items-center justify-between text-[10px] text-muted-foreground mb-1">
                                                            <span>Take-home ratio</span>
                                                            <span>
                                                                {Number(p.gross_pay) > 0
                                                                    ? `${((Number(p.net_pay) / Number(p.gross_pay)) * 100).toFixed(1)}%`
                                                                    : '\u2014'}
                                                            </span>
                                                        </div>
                                                        <div className="flex h-2 w-full overflow-hidden rounded-full">
                                                            <div
                                                                className="bg-emerald-500 rounded-l-full"
                                                                style={{ width: `${(Number(p.net_pay) / Number(p.gross_pay)) * 100}%` }}
                                                            />
                                                            <div
                                                                className="bg-red-400"
                                                                style={{ width: `${(Number(p.paye) / Number(p.gross_pay)) * 100}%` }}
                                                            />
                                                            <div
                                                                className="bg-amber-400"
                                                                style={{ width: `${(Number(p.kiwisaver_employee) / Number(p.gross_pay)) * 100}%` }}
                                                            />
                                                            <div
                                                                className="bg-slate-300 dark:bg-slate-600 rounded-r-full"
                                                                style={{ width: `${((Number(p.acc_levy) + Number(p.student_loan)) / Number(p.gross_pay)) * 100}%` }}
                                                            />
                                                        </div>
                                                        <div className="mt-1.5 flex flex-wrap gap-3 text-[10px]">
                                                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" />Net</span>
                                                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-red-400" />PAYE</span>
                                                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-amber-400" />KiwiSaver</span>
                                                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-600" />Other</span>
                                                        </div>
                                                    </div>

                                                    {/* Actions */}
                                                    <div className="mt-4 flex gap-2">
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/hr/payroll/payslips/${p.id}`}>
                                                                <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                                View Full Payslip
                                                            </Link>
                                                        </Button>
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/hr/payroll/payslips/${p.id}/download`}>
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
                                    <p className="font-medium">No payslips yet</p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Your payslips will appear here after each pay run is processed
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Pagination */}
                    {payslips.total > 0 && (
                        <div className="mt-4 flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">
                                Showing {(payslips.current_page - 1) * payslips.per_page + 1} to{' '}
                                {Math.min(payslips.current_page * payslips.per_page, payslips.total)} of{' '}
                                {payslips.total} payslip{payslips.total !== 1 ? 's' : ''}
                            </p>
                            <LaravelPagination links={payslips.links} />
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
