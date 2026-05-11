import { ActionItems } from '@/components/dashboard/action-items';
import { CircularGauge } from '@/components/dashboard/circular-gauge';
import { ClockWidget } from '@/components/dashboard/clock-widget';
import { DonutChart } from '@/components/dashboard/donut-chart';
import { MiniSparkline } from '@/components/dashboard/mini-sparkline';
import { WeeklyHoursChart } from '@/components/dashboard/weekly-hours-chart';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    ChevronRight,
    ClipboardList,
    DollarSign,
    FileCheck,
    Heart,
    Megaphone,
    MessageSquare,
    Receipt,
    ScrollText,
    Shield,
    Star,
    Target,
    Timer,
    User,
    Wallet,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Props {
    profile: {
        id: number;
        position_title: string;
        employment_type: string;
        start_date: string | null;
        primary_site_id: number | null;
        user?: {
            id: number;
            name: string;
            email: string;
            profile_photo_path?: string | null;
        };
    } | null;
    pendingLeave: number;
    leaveBalances: Array<{
        leave_type: string;
        entitlement_hours?: number;
        taken_hours?: number;
        remaining_hours?: number;
        balance_hours?: string | number;
        accrued_hours?: string | number;
        used_hours?: string | number;
    }>;
    complianceSummary: {
        compliant: number;
        expiring_soon: number;
        expired: number;
        not_started: number;
    };
    complianceStatuses: Array<{
        id: number;
        status: string;
        requirement: { name: string; category: string };
    }>;
    policiesDue: number;
    pendingReviews: number;
    activeGoals: number;
    availableSurveys: number;
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    weeklySummary: {
        week_start: string;
        week_end: string;
        daily_hours: Record<string, number>;
        total_hours: number;
        total_entries: number;
    };
    todayTotal: number;
    latestPayslip: { net_pay: string; payment_date: string } | null;
    pendingExpenses: { count: number; total: number };
    kudosReceived: number;
    announcements: Array<{
        id: number;
        title: string;
        priority: string;
        published_at: string;
    }>;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
];

function getGreeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
}

function formatNzd(value: string | number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function formatLeaveType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const LEAVE_COLORS: Record<string, string> = {
    annual_leave: '#3b82f6',
    sick_leave: '#8b5cf6',
    bereavement_leave: '#64748b',
    parental_leave: '#ec4899',
    public_holiday: '#14b8a6',
};

const PRIORITY_DOT: Record<string, string> = {
    urgent: 'bg-status-critical',
    high: 'bg-status-warning',
    normal: 'bg-status-info',
    low: 'bg-muted',
};

const QUICK_LINKS = [
    { label: 'Profile', icon: User, href: '/hr/my/profile' },
    { label: 'Leave', icon: CalendarDays, href: '/hr/my/leave' },
    { label: 'Time', icon: Timer, href: '/hr/my/time' },
    { label: 'Payslips', icon: Wallet, href: '/hr/my/payslips' },
    { label: 'Expenses', icon: Receipt, href: '/hr/my/expenses' },
    { label: 'Documents', icon: FileCheck, href: '/hr/my/documents' },
    { label: 'Training', icon: BookOpen, href: '/hr/my/training' },
    { label: 'Policies', icon: ScrollText, href: '/hr/my/policies' },
    { label: 'Reviews', icon: ClipboardList, href: '/hr/my/reviews' },
    { label: 'Goals', icon: Target, href: '/hr/my/goals' },
    { label: 'Surveys', icon: MessageSquare, href: '/hr/my/surveys' },
];

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyHrIndex({
    profile,
    pendingLeave,
    leaveBalances,
    complianceSummary,
    policiesDue,
    pendingReviews,
    activeGoals,
    availableSurveys,
    activeClock,
    weeklySummary,
    todayTotal,
    latestPayslip,
    pendingExpenses,
    kudosReceived,
    announcements,
}: Props) {
    const userName = profile?.user?.name ?? 'there';
    const userInitials = userName
        .split(' ')
        .map((n) => n[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    const complianceTotal =
        complianceSummary.compliant +
        complianceSummary.expiring_soon +
        complianceSummary.expired +
        complianceSummary.not_started;

    const weeklySparkline = Object.values(weeklySummary?.daily_hours ?? {}).map(
        Number,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My HR" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* ============================================= */}
                {/* ZONE A — Hero Banner                            */}
                {/* ============================================= */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-primary-foreground shadow-lg md:p-8">
                    {/* Decorative shapes */}
                    <div className="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-white/5" />
                    <div className="absolute -bottom-8 -left-8 h-32 w-32 rounded-full bg-white/5" />
                    <div className="absolute right-24 bottom-0 h-20 w-20 rounded-full bg-white/5" />

                    <div className="relative grid items-center gap-6 lg:grid-cols-[1fr_auto_1fr]">
                        {/* Left — Greeting */}
                        <div className="flex items-center gap-4">
                            <Avatar className="h-16 w-16 border-2 border-white/30 shadow-md">
                                <AvatarImage
                                    src={
                                        profile?.user?.profile_photo_path ??
                                        undefined
                                    }
                                />
                                <AvatarFallback className="bg-white/20 text-xl font-bold text-white">
                                    {userInitials}
                                </AvatarFallback>
                            </Avatar>
                            <div>
                                <h1 className="text-2xl font-bold md:text-3xl">
                                    {getGreeting()}, {userName.split(' ')[0]}
                                </h1>
                                <p className="text-sm text-white/70">
                                    {new Date().toLocaleDateString('en-NZ', {
                                        weekday: 'long',
                                        day: 'numeric',
                                        month: 'long',
                                        year: 'numeric',
                                    })}
                                </p>
                                {profile && (
                                    <p className="mt-0.5 text-xs text-white/50">
                                        {profile.position_title}
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Centre — Clock Widget */}
                        <div className="rounded-xl bg-white/10 px-8 py-5 backdrop-blur-sm">
                            <ClockWidget
                                activeClock={activeClock}
                                todayTotal={todayTotal}
                            />
                        </div>

                        {/* Right — Quick Stats */}
                        <div className="flex items-center justify-end gap-5">
                            <div className="flex flex-col items-center gap-1 rounded-lg bg-white/10 px-4 py-3">
                                <Timer className="h-5 w-5 text-white/70" />
                                <span className="text-xl font-bold">
                                    {(weeklySummary?.total_hours ?? 0).toFixed(
                                        1,
                                    )}
                                    h
                                </span>
                                <span className="text-[10px] text-white/60">
                                    This Week
                                </span>
                                {weeklySparkline.length > 1 && (
                                    <MiniSparkline
                                        data={weeklySparkline}
                                        width={64}
                                        height={20}
                                        color="rgba(255,255,255,0.8)"
                                    />
                                )}
                            </div>

                            <div className="flex flex-col items-center gap-1 rounded-lg bg-white/10 px-4 py-3">
                                <DollarSign className="h-5 w-5 text-white/70" />
                                <span className="text-xl font-bold">
                                    {latestPayslip
                                        ? formatNzd(latestPayslip.net_pay)
                                        : '--'}
                                </span>
                                <span className="text-[10px] text-white/60">
                                    Latest Pay
                                </span>
                            </div>

                            <div className="flex flex-col items-center gap-1 rounded-lg bg-white/10 px-4 py-3">
                                <Heart className="h-5 w-5 text-status-critical" />
                                <span className="text-xl font-bold">
                                    {kudosReceived}
                                </span>
                                <span className="text-[10px] text-white/60">
                                    Kudos
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ============================================= */}
                {/* ZONE B — KPI Metrics                           */}
                {/* ============================================= */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Link href="/hr/my/leave" className="group">
                        <Card className="relative gap-0 overflow-hidden rounded-xl p-5 shadow-sm transition-all hover:border-primary/40 hover:shadow-md">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Pending Leave
                                    </p>
                                    <p className="mt-1 text-3xl font-bold tracking-tight">
                                        {pendingLeave}
                                    </p>
                                </div>
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-status-info-bg text-status-info transition-transform group-hover:scale-110 dark:text-status-info">
                                    <CalendarDays className="h-6 w-6" />
                                </div>
                            </div>
                            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-status-info-bg">
                                <div
                                    className="h-full rounded-full bg-status-info transition-all"
                                    style={{
                                        width: pendingLeave > 0 ? '100%' : '0%',
                                    }}
                                />
                            </div>
                        </Card>
                    </Link>

                    <Link href="/hr/my/expenses" className="group">
                        <Card className="relative gap-0 overflow-hidden rounded-xl p-5 shadow-sm transition-all hover:border-primary/40 hover:shadow-md">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Pending Expenses
                                    </p>
                                    <p className="mt-1 text-3xl font-bold tracking-tight">
                                        {pendingExpenses.count}
                                    </p>
                                    {pendingExpenses.total > 0 && (
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {formatNzd(pendingExpenses.total)}{' '}
                                            total
                                        </p>
                                    )}
                                </div>
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning transition-transform group-hover:scale-110 dark:text-status-warning">
                                    <Receipt className="h-6 w-6" />
                                </div>
                            </div>
                            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-status-warning-bg">
                                <div
                                    className="h-full rounded-full bg-status-warning transition-all"
                                    style={{
                                        width:
                                            pendingExpenses.count > 0
                                                ? '100%'
                                                : '0%',
                                    }}
                                />
                            </div>
                        </Card>
                    </Link>

                    <Link href="/hr/my/reviews" className="group">
                        <Card className="relative gap-0 overflow-hidden rounded-xl p-5 shadow-sm transition-all hover:border-primary/40 hover:shadow-md">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Pending Reviews
                                    </p>
                                    <p className="mt-1 text-3xl font-bold tracking-tight">
                                        {pendingReviews}
                                    </p>
                                </div>
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform group-hover:scale-110 dark:text-primary">
                                    <ClipboardList className="h-6 w-6" />
                                </div>
                            </div>
                            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-primary/10 dark:bg-primary/30">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{
                                        width:
                                            pendingReviews > 0 ? '100%' : '0%',
                                    }}
                                />
                            </div>
                        </Card>
                    </Link>

                    <Link href="/hr/my/goals" className="group">
                        <Card className="relative gap-0 overflow-hidden rounded-xl p-5 shadow-sm transition-all hover:border-primary/40 hover:shadow-md">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Active Goals
                                    </p>
                                    <p className="mt-1 text-3xl font-bold tracking-tight">
                                        {activeGoals}
                                    </p>
                                </div>
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-status-success-bg text-status-success transition-transform group-hover:scale-110 dark:text-status-success">
                                    <Target className="h-6 w-6" />
                                </div>
                            </div>
                            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-status-success-bg">
                                <div
                                    className="h-full rounded-full bg-status-success transition-all"
                                    style={{
                                        width: activeGoals > 0 ? '100%' : '0%',
                                    }}
                                />
                            </div>
                        </Card>
                    </Link>
                </div>

                {/* ============================================= */}
                {/* ZONE C — Visual Data                           */}
                {/* ============================================= */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left Column */}
                    <div className="flex flex-col gap-6">
                        {/* Leave Balances */}
                        <div>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="text-base font-semibold">
                                    Leave Balances
                                </h2>
                                <Link
                                    href="/hr/my/leave"
                                    className="text-xs text-muted-foreground hover:text-foreground"
                                >
                                    View all{' '}
                                    <ChevronRight className="inline h-3 w-3" />
                                </Link>
                            </div>

                            {leaveBalances.length > 0 ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {leaveBalances.map((b, i) => {
                                        const used = Number(
                                            b.taken_hours ?? b.used_hours ?? 0,
                                        );
                                        const total = Number(
                                            b.entitlement_hours ??
                                                b.accrued_hours ??
                                                0,
                                        );
                                        const remaining = Math.max(
                                            0,
                                            total - used,
                                        );
                                        const pct =
                                            total > 0
                                                ? Math.min(
                                                      100,
                                                      (used / total) * 100,
                                                  )
                                                : 0;
                                        const remainingWeeks = (
                                            remaining / 40
                                        ).toFixed(1);
                                        const totalWeeks = (total / 40).toFixed(
                                            1,
                                        );
                                        const typeKey = b.leave_type.includes(
                                            '_',
                                        )
                                            ? b.leave_type
                                            : `${b.leave_type}_leave`;
                                        const color =
                                            LEAVE_COLORS[typeKey] ??
                                            LEAVE_COLORS[b.leave_type] ??
                                            '#06b6d4';

                                        return (
                                            <Link
                                                key={i}
                                                href="/hr/my/leave"
                                                className="group"
                                            >
                                                <Card className="overflow-hidden transition-all group-hover:border-primary/40 group-hover:shadow-md">
                                                    {/* Coloured top accent */}
                                                    <div
                                                        className="h-1"
                                                        style={{
                                                            backgroundColor:
                                                                color,
                                                        }}
                                                    />

                                                    <CardContent className="p-5">
                                                        <div className="flex items-start gap-4">
                                                            {/* Gauge */}
                                                            <CircularGauge
                                                                value={used}
                                                                max={total}
                                                                label=""
                                                                color={color}
                                                                size={90}
                                                                thickness={8}
                                                            />

                                                            {/* Details */}
                                                            <div className="min-w-0 flex-1">
                                                                <h3 className="text-sm font-semibold">
                                                                    {formatLeaveType(
                                                                        b.leave_type,
                                                                    )}
                                                                </h3>

                                                                <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                                                                    <div>
                                                                        <span className="text-muted-foreground">
                                                                            Remaining
                                                                        </span>
                                                                        <p
                                                                            className="text-base leading-tight font-bold"
                                                                            style={{
                                                                                color,
                                                                            }}
                                                                        >
                                                                            {remaining.toFixed(
                                                                                0,
                                                                            )}
                                                                            h
                                                                        </p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-muted-foreground">
                                                                            Entitlement
                                                                        </span>
                                                                        <p className="text-sm leading-tight font-semibold">
                                                                            {total.toFixed(
                                                                                0,
                                                                            )}
                                                                            h
                                                                        </p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-muted-foreground">
                                                                            Used
                                                                        </span>
                                                                        <p className="text-sm leading-tight font-medium">
                                                                            {used.toFixed(
                                                                                0,
                                                                            )}
                                                                            h
                                                                        </p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-muted-foreground">
                                                                            Weeks
                                                                            Left
                                                                        </span>
                                                                        <p className="text-sm leading-tight font-semibold">
                                                                            {
                                                                                remainingWeeks
                                                                            }
                                                                            <span className="font-normal text-muted-foreground">
                                                                                /
                                                                                {
                                                                                    totalWeeks
                                                                                }
                                                                            </span>
                                                                        </p>
                                                                    </div>
                                                                </div>

                                                                {/* Progress bar */}
                                                                <div className="mt-3">
                                                                    <div className="mb-1 flex justify-between text-[10px] text-muted-foreground">
                                                                        <span>
                                                                            {pct.toFixed(
                                                                                0,
                                                                            )}
                                                                            %
                                                                            used
                                                                        </span>
                                                                        <span>
                                                                            {(
                                                                                100 -
                                                                                pct
                                                                            ).toFixed(
                                                                                0,
                                                                            )}
                                                                            %
                                                                            remaining
                                                                        </span>
                                                                    </div>
                                                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted/40">
                                                                        <div
                                                                            className="h-full rounded-full transition-all duration-700"
                                                                            style={{
                                                                                width: `${pct}%`,
                                                                                backgroundColor:
                                                                                    color,
                                                                            }}
                                                                        />
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            </Link>
                                        );
                                    })}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-8">
                                        <p className="text-center text-sm text-muted-foreground">
                                            No leave balances recorded yet.
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                        </div>

                        {/* Weekly Hours Chart */}
                        <Card>
                            <CardContent className="pt-5">
                                <WeeklyHoursChart
                                    dailyHours={
                                        weeklySummary?.daily_hours ?? {}
                                    }
                                    totalHours={weeklySummary?.total_hours ?? 0}
                                    weekStart={weeklySummary?.week_start}
                                />
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column */}
                    <div className="flex flex-col gap-6">
                        {/* Compliance Donut */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base">
                                        Compliance
                                    </CardTitle>
                                    <Link
                                        href="/hr/my/training"
                                        className="text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        View all{' '}
                                        <ChevronRight className="inline h-3 w-3" />
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {complianceTotal > 0 ? (
                                    <DonutChart
                                        data={[
                                            {
                                                label: 'Compliant',
                                                value: complianceSummary.compliant,
                                                color: '#10b981',
                                            },
                                            {
                                                label: 'Expiring',
                                                value: complianceSummary.expiring_soon,
                                                color: '#f59e0b',
                                            },
                                            {
                                                label: 'Expired',
                                                value: complianceSummary.expired,
                                                color: '#ef4444',
                                            },
                                            {
                                                label: 'Not Started',
                                                value: complianceSummary.not_started,
                                                color: '#94a3b8',
                                            },
                                        ]}
                                        size={140}
                                        thickness={20}
                                        centerValue={
                                            complianceSummary.compliant
                                        }
                                        centerLabel="compliant"
                                    />
                                ) : (
                                    <div className="flex flex-col items-center gap-2 py-6">
                                        <Shield className="h-8 w-8 text-muted-foreground/40" />
                                        <p className="text-sm text-muted-foreground">
                                            No compliance items assigned.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Action Items */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    Needs Your Attention
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ActionItems
                                    items={[
                                        {
                                            icon: FileCheck,
                                            label: 'Policies to attest',
                                            count: policiesDue,
                                            href: '/hr/my/policies',
                                            variant:
                                                policiesDue > 0
                                                    ? 'warning'
                                                    : 'default',
                                        },
                                        {
                                            icon: ClipboardList,
                                            label: 'Reviews to sign',
                                            count: pendingReviews,
                                            href: '/hr/my/reviews',
                                            variant:
                                                pendingReviews > 0
                                                    ? 'warning'
                                                    : 'default',
                                        },
                                        {
                                            icon: MessageSquare,
                                            label: 'Surveys to complete',
                                            count: availableSurveys,
                                            href: '/hr/my/surveys',
                                            variant: 'info',
                                        },
                                        {
                                            icon: Star,
                                            label: 'Active goals',
                                            count: activeGoals,
                                            href: '/hr/my/goals',
                                            variant: 'default',
                                        },
                                    ]}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ============================================= */}
                {/* ZONE D — Announcements + Quick Links            */}
                {/* ============================================= */}
                <div className="grid gap-6 md:grid-cols-[2fr_3fr]">
                    {/* Announcements */}
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Megaphone className="h-4 w-4" />
                                    Announcements
                                    {announcements.length > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-1"
                                        >
                                            {announcements.length}
                                        </Badge>
                                    )}
                                </CardTitle>
                                <Link
                                    href="/hr/announcements"
                                    className="text-xs text-muted-foreground hover:text-foreground"
                                >
                                    View all
                                </Link>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {announcements.length > 0 ? (
                                <div className="divide-y">
                                    {announcements.map((a) => (
                                        <Link
                                            key={a.id}
                                            href={`/hr/announcements/${a.id}`}
                                            className="flex items-start gap-2.5 rounded py-2.5 transition-colors hover:bg-muted/50"
                                        >
                                            <span
                                                className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${PRIORITY_DOT[a.priority] ?? PRIORITY_DOT.normal}`}
                                            />
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {a.title}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground">
                                                    {new Date(
                                                        a.published_at,
                                                    ).toLocaleDateString(
                                                        'en-NZ',
                                                        {
                                                            day: 'numeric',
                                                            month: 'short',
                                                        },
                                                    )}
                                                </p>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-1.5 py-4 text-center">
                                    <span className="text-2xl">&#10003;</span>
                                    <p className="text-sm text-muted-foreground">
                                        All caught up!
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Quick Links */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Quick Links
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-5 gap-2">
                                {QUICK_LINKS.map((link) => {
                                    const Icon = link.icon;
                                    return (
                                        <Link
                                            key={link.href}
                                            href={link.href}
                                            className="flex flex-col items-center gap-1.5 rounded-xl p-3 text-center transition-all hover:bg-muted hover:shadow-sm"
                                        >
                                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                                <Icon className="h-5 w-5 text-primary" />
                                            </div>
                                            <span className="text-[11px] leading-tight font-medium">
                                                {link.label}
                                            </span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
