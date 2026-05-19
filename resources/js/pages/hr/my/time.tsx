import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/recruitment/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    Clock,
    Loader2,
    MapPin,
    Play,
    Square,
    Timer,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface TimeEntry {
    id: number;
    entry_date: string;
    clock_in: string;
    clock_out: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    pay_type: string;
    notes: string | null;
    shift: {
        id: number;
        starts_at: string;
        ends_at: string;
        shift_type: string;
        is_sleepover: boolean;
        is_on_call: boolean;
        expected_break_minutes?: number | null;
        location?: string | null;
        service_context_name?: string | null;
        client_name: string;
    } | null;
    client_name: string | null;
}

interface WeeklySummary {
    week_start: string;
    week_end: string;
    daily_hours: Record<string, number>;
    total_hours: number;
    total_entries: number;
}

interface UpcomingShift {
    id: number;
    starts_at: string;
    ends_at: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    expected_break_minutes?: number | null;
    client_name: string;
    location: string | null;
    service_context_name?: string | null;
    status: string;
}

interface Props {
    activeClock: {
        id: number;
        clock_in: string;
        notes: string | null;
        shift?: {
            id: number;
            starts_at: string;
            ends_at: string;
            shift_type: string;
            is_sleepover: boolean;
            is_on_call: boolean;
            expected_break_minutes?: number | null;
            location?: string | null;
            service_context_name?: string | null;
            client_name: string;
        } | null;
    } | null;
    todayEntries: TimeEntry[];
    weeklySummary: WeeklySummary;
    upcomingShifts: UpcomingShift[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Time', href: '/hr/my/time' },
];

const defaultStatusConfig = {
    className: 'border-status-info/30 text-status-info bg-status-info',
    label: 'Active',
};

const statusConfig: Record<string, { className: string; label: string }> = {
    active: defaultStatusConfig,
    submitted: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Approved',
    },
    rejected: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Rejected',
    },
};

const defaultShiftTypeConfig = {
    className:
        'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
    label: 'Standard',
};

const shiftTypeConfig: Record<string, { className: string; label: string }> = {
    standard: defaultShiftTypeConfig,
    sleepover: {
        className: 'border-primary/30 text-primary bg-primary/10',
        label: 'Sleepover',
    },
    on_call: {
        className: 'border-primary/30 text-primary bg-primary/10',
        label: 'On-Call',
    },
    split: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Split',
    },
    travel: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Travel',
    },
};

const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function getShiftTypeConfig(shiftType?: string): {
    className: string;
    label: string;
} {
    return shiftTypeConfig[shiftType ?? 'standard'] ?? defaultShiftTypeConfig;
}

function getStatusConfig(status?: string): {
    className: string;
    label: string;
} {
    return statusConfig[status ?? 'active'] ?? defaultStatusConfig;
}

export default function MyTime({
    activeClock,
    todayEntries,
    weeklySummary,
    upcomingShifts,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const todayTotal = todayEntries
        .filter((e) => e.total_hours != null)
        .reduce((sum, e) => sum + (e.total_hours ?? 0), 0);

    const pendingCount = todayEntries.filter(
        (e) => e.status === 'submitted',
    ).length;

    const nextShift = upcomingShifts.length > 0 ? upcomingShifts[0] : null;
    const nextShiftLabel = nextShift
        ? `${nextShift.starts_at.slice(11, 16)} - ${nextShift.client_name || 'Unassigned'}`
        : 'None scheduled';

    function handleClockIn(shiftId?: number) {
        setProcessing(true);
        router.post(
            '/hr/my/time/clock-in',
            { shift_id: shiftId ?? null },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Clocked in successfully'),
                onError: () => toast.error('Failed to clock in'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function handleClockOut() {
        setProcessing(true);
        router.post(
            '/hr/my/time/clock-out',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Clocked out successfully'),
                onError: () => toast.error('Failed to clock out'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Time" />

            <PageShell>
                <PageHero variant="compact"
                    title="My Time"
                    backHref="/hr/my"
                    backLabel="Back to My HR"
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiCard
                        label="Today's Hours"
                        value={Number(todayTotal.toFixed(1))}
                        icon={Clock}
                        suffix="h"
                        decimals={1}
                        color="bg-primary/10 text-primary"
                    />
                    <KpiCard
                        label="This Week"
                        value={weeklySummary.total_hours}
                        icon={TrendingUp}
                        suffix="h"
                        decimals={1}
                        description={`Target: 40h`}
                        color="bg-status-success-bg text-status-success"
                    />
                    <KpiCard
                        label="Pending Entries"
                        value={pendingCount}
                        icon={AlertTriangle}
                        description="Awaiting approval"
                        color="bg-status-warning-bg text-status-warning"
                    />
                    <KpiCard
                        label="Next Shift"
                        value={upcomingShifts.length}
                        icon={Calendar}
                        description={nextShiftLabel}
                        color="bg-status-info-bg text-status-info"
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        {/* Clock In/Out */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Clock className="h-4 w-4" />
                                        Clock Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {activeClock ? (
                                        <div className="space-y-3">
                                            <div className="flex items-center gap-2">
                                                <div className="h-3 w-3 animate-pulse rounded-full bg-status-success" />
                                                <span className="text-sm font-medium">
                                                    Clocked in since{' '}
                                                    {activeClock.clock_in}
                                                </span>
                                            </div>
                                            {activeClock.shift ? (
                                                <div className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">
                                                    <div className="font-medium text-foreground">
                                                        {activeClock.shift
                                                            ?.client_name ||
                                                            'Linked shift'}
                                                    </div>
                                                    <div className="mt-1">
                                                        {
                                                            getShiftTypeConfig(
                                                                activeClock
                                                                    .shift
                                                                    ?.shift_type,
                                                            ).label
                                                        }
                                                        {activeClock.shift
                                                            ?.service_context_name
                                                            ? ` • ${activeClock.shift.service_context_name}`
                                                            : ''}
                                                        {activeClock.shift
                                                            ?.location
                                                            ? ` • ${activeClock.shift.location}`
                                                            : ''}
                                                    </div>
                                                    {(activeClock.shift
                                                        .is_sleepover ||
                                                        activeClock.shift
                                                            .is_on_call ||
                                                        activeClock.shift
                                                            .expected_break_minutes) && (
                                                        <div className="mt-1">
                                                            {activeClock.shift
                                                                .is_sleepover
                                                                ? 'Sleepover'
                                                                : null}
                                                            {activeClock.shift
                                                                .is_sleepover &&
                                                            activeClock.shift
                                                                .is_on_call
                                                                ? ' • '
                                                                : null}
                                                            {activeClock.shift
                                                                .is_on_call
                                                                ? 'On-call'
                                                                : null}
                                                            {(activeClock.shift
                                                                .is_sleepover ||
                                                                activeClock
                                                                    .shift
                                                                    .is_on_call) &&
                                                            activeClock.shift
                                                                .expected_break_minutes
                                                                ? ' • '
                                                                : null}
                                                            {activeClock.shift
                                                                .expected_break_minutes
                                                                ? `Break ${activeClock.shift.expected_break_minutes}m`
                                                                : null}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : null}
                                            {activeClock.notes && (
                                                <p className="text-sm text-muted-foreground">
                                                    {activeClock.notes}
                                                </p>
                                            )}
                                            <Button
                                                onClick={handleClockOut}
                                                variant="destructive"
                                                className="w-full"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {processing ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <Square className="mr-2 h-4 w-4" />
                                                )}
                                                {processing
                                                    ? 'Clocking Out...'
                                                    : 'Clock Out'}
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            <div className="flex items-center gap-2">
                                                <div className="h-3 w-3 rounded-full bg-muted" />
                                                <span className="text-sm text-muted-foreground">
                                                    Not clocked in
                                                </span>
                                            </div>
                                            <Button
                                                onClick={() => handleClockIn()}
                                                className="w-full"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {processing ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <Play className="mr-2 h-4 w-4" />
                                                )}
                                                {processing
                                                    ? 'Clocking In...'
                                                    : 'Clock In'}
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Weekly Chart */}
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Timer className="h-4 w-4" />
                                        Weekly Hours
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-3 text-center">
                                        <p className="text-3xl font-bold">
                                            {weeklySummary.total_hours}h
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {weeklySummary.week_start} to{' '}
                                            {weeklySummary.week_end}
                                        </p>
                                    </div>
                                    <div className="flex items-end justify-between gap-1">
                                        {Object.entries(
                                            weeklySummary.daily_hours ?? {},
                                        ).map(([date, hours], i) => {
                                            const safeHours =
                                                Number(hours) || 0;
                                            const maxHours = Math.max(
                                                10,
                                                ...Object.values(
                                                    weeklySummary.daily_hours ??
                                                        {},
                                                ).map((h) => Number(h) || 0),
                                            );
                                            const barHeight =
                                                maxHours > 0
                                                    ? (safeHours / maxHours) *
                                                      50
                                                    : 0;
                                            return (
                                                <div
                                                    key={date}
                                                    className="flex flex-1 flex-col items-center gap-1"
                                                >
                                                    <div
                                                        className="w-full rounded bg-primary/20"
                                                        style={{
                                                            height: `${Math.max(4, barHeight)}px`,
                                                        }}
                                                    >
                                                        <div
                                                            className="w-full rounded bg-primary"
                                                            style={{
                                                                height: `${barHeight}px`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {dayLabels[i] ??
                                                            date.slice(5)}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Today's Entries */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Today's Entries
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                {todayEntries.length === 0 ? (
                                    <div className="py-12 text-center">
                                        <Clock className="mx-auto mb-3 h-12 w-12 text-muted-foreground/40" />
                                        <p className="font-medium text-muted-foreground">
                                            No time entries recorded today
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Clock in above to start tracking
                                            your hours.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="overflow-hidden rounded-b-xl">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        In
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Out
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Client
                                                    </th>
                                                    <th className="px-4 py-3 text-right font-medium">
                                                        Break
                                                    </th>
                                                    <th className="px-4 py-3 text-right font-medium">
                                                        Hours
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {todayEntries.map((entry) => {
                                                    const config =
                                                        getStatusConfig(
                                                            entry.status,
                                                        );
                                                    return (
                                                        <tr
                                                            key={entry.id}
                                                            className="hover:bg-muted/30"
                                                        >
                                                            <td className="px-4 py-3">
                                                                {entry.clock_in}
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                {entry.clock_out ??
                                                                    '-'}
                                                            </td>
                                                            <td className="px-4 py-3 text-muted-foreground">
                                                                {entry.client_name ||
                                                                    entry.shift
                                                                        ?.client_name ||
                                                                    '-'}
                                                                {entry.shift && (
                                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                                        <Badge
                                                                            variant="outline"
                                                                            className={`text-[10px] ${getShiftTypeConfig(entry.shift?.shift_type).className}`}
                                                                        >
                                                                            {
                                                                                getShiftTypeConfig(
                                                                                    entry
                                                                                        .shift
                                                                                        ?.shift_type,
                                                                                )
                                                                                    .label
                                                                            }
                                                                        </Badge>
                                                                        {entry
                                                                            .shift
                                                                            .service_context_name ? (
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="text-[10px]"
                                                                            >
                                                                                {
                                                                                    entry
                                                                                        .shift
                                                                                        .service_context_name
                                                                                }
                                                                            </Badge>
                                                                        ) : null}
                                                                    </div>
                                                                )}
                                                                {entry.shift
                                                                    ?.location ? (
                                                                    <div className="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground">
                                                                        <MapPin className="h-3 w-3" />{' '}
                                                                        {
                                                                            entry
                                                                                .shift
                                                                                .location
                                                                        }
                                                                    </div>
                                                                ) : null}
                                                            </td>
                                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                                {entry.break_minutes >
                                                                0
                                                                    ? `${entry.break_minutes}m`
                                                                    : '-'}
                                                                {entry.shift
                                                                    ?.expected_break_minutes ? (
                                                                    <div className="text-[11px] text-muted-foreground">
                                                                        planned{' '}
                                                                        {
                                                                            entry
                                                                                .shift
                                                                                .expected_break_minutes
                                                                        }
                                                                        m
                                                                    </div>
                                                                ) : null}
                                                            </td>
                                                            <td className="px-4 py-3 text-right font-medium">
                                                                {entry.total_hours !=
                                                                null
                                                                    ? `${entry.total_hours}h`
                                                                    : '-'}
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <Badge
                                                                    variant="outline"
                                                                    className={
                                                                        config.className
                                                                    }
                                                                >
                                                                    {
                                                                        config.label
                                                                    }
                                                                </Badge>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Sidebar — Upcoming Shifts */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Calendar className="h-4 w-4" />
                                    Upcoming Shifts
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {upcomingShifts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No upcoming shifts in the next 3 days.
                                    </p>
                                ) : (
                                    upcomingShifts.map((shift) => {
                                        const typeConfig = getShiftTypeConfig(
                                            shift.shift_type,
                                        );
                                        return (
                                            <div
                                                key={shift.id}
                                                className="rounded-lg border p-3 transition-colors hover:bg-accent/30"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-medium">
                                                            {shift.client_name ||
                                                                'Unassigned'}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {shift.starts_at} —{' '}
                                                            {shift.ends_at?.slice(
                                                                11,
                                                                16,
                                                            )}
                                                        </p>
                                                        {shift.location && (
                                                            <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                                <MapPin className="h-3 w-3" />{' '}
                                                                {shift.location}
                                                            </p>
                                                        )}
                                                        {shift.service_context_name && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {
                                                                    shift.service_context_name
                                                                }
                                                            </p>
                                                        )}
                                                        {(shift.is_sleepover ||
                                                            shift.is_on_call ||
                                                            shift.expected_break_minutes) && (
                                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                                {shift.is_sleepover
                                                                    ? 'Sleepover'
                                                                    : null}
                                                                {shift.is_sleepover &&
                                                                shift.is_on_call
                                                                    ? ' • '
                                                                    : null}
                                                                {shift.is_on_call
                                                                    ? 'On-call'
                                                                    : null}
                                                                {(shift.is_sleepover ||
                                                                    shift.is_on_call) &&
                                                                shift.expected_break_minutes
                                                                    ? ' • '
                                                                    : null}
                                                                {shift.expected_break_minutes
                                                                    ? `Break ${shift.expected_break_minutes}m`
                                                                    : null}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <Badge
                                                        variant="outline"
                                                        className={`shrink-0 text-[10px] ${typeConfig.className}`}
                                                    >
                                                        {typeConfig.label}
                                                    </Badge>
                                                </div>
                                                {!activeClock && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-2 w-full"
                                                        onClick={() =>
                                                            handleClockIn(
                                                                shift.id,
                                                            )
                                                        }
                                                        disabled={processing}
                                                    >
                                                        <Play className="mr-1 h-3 w-3" />{' '}
                                                        Clock In to This Shift
                                                    </Button>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
