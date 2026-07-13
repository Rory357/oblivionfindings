import {
    FLEET_COLORS,
    HalfMoonGauge,
    HorizontalBarChart,
    MiniBarChart,
} from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDistance } from '@/lib/fleet-utils';
import {
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { HeroActionButton } from '@/pages/fleet-assets/maintenance/components/hero-action-button';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CalendarClock,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Clock,
    Loader2,
    Plus,
} from 'lucide-react';
import { useState } from 'react';

type ServiceSchedule = {
    id: number;
    name: string;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    interval_km: number | null;
    interval_days: number | null;
    last_completed_at: string | null;
    last_completed_km: number | null;
    next_due_at: string | null;
    next_due_km: number | null;
    is_overdue: boolean;
    created_at: string | null;
};

type TimelineItem = {
    id: number;
    name: string;
    vehicle: string;
    due_at: string;
    days_until: number;
    type: string;
};

type Props = {
    schedules: ServiceSchedule[];
    assets?: Array<{ id: number; name: string }>;
    fleet_health_pct: number;
    schedules_per_vehicle: Array<{ label: string; value: number }>;
    monthly_completions: Array<{ label: string; value: number }>;
    upcoming_timeline: TimelineItem[];
    stats?: {
        due_7d: number;
        overdue: number;
        active: number;
    };
    can: {
        manage: boolean;
    };
};

const serviceScheduleSteps = [
    { key: 'schedule', label: 'Asset & interval', blurb: 'Set the recurring service rule', icon: CalendarClock },
    { key: 'review', label: 'Review', blurb: 'Confirm before creating', icon: Check },
] as const;

function isDueSoon(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const diff =
        (new Date(dateStr).getTime() - new Date().getTime()) /
        (1000 * 60 * 60 * 24);
    return diff <= 14 && diff >= 0;
}

function daysUntilDue(dateStr: string | null): number | null {
    if (!dateStr) return null;
    return Math.ceil(
        (new Date(dateStr).getTime() - new Date().getTime()) /
            (1000 * 60 * 60 * 24),
    );
}

function daysUntilLabel(days: number | null, isOverdue: boolean): string {
    if (days === null) return '---';
    if (isOverdue) return `${Math.abs(days)}d overdue`;
    if (days === 0) return 'Today';
    if (days === 1) return '1 day';
    return `${days} days`;
}

function kmProgressPct(schedule: ServiceSchedule): number | null {
    if (!schedule.interval_km || !schedule.next_due_km) return null;
    const lastKm = Number(schedule.last_completed_km ?? 0);
    const nextKm = Number(schedule.next_due_km);
    const intervalKm = Number(schedule.interval_km);
    if (intervalKm <= 0) return null;
    // Progress = how far through the interval we are (estimated from dates, but we use km range)
    const progress =
        nextKm - intervalKm > 0
            ? Math.max(
                  0,
                  Math.min(
                      100,
                      ((lastKm - (nextKm - intervalKm)) / intervalKm) * 100,
                  ),
              )
            : 0;
    return Math.round(progress);
}

function SchedulesHero({
    stats,
    canManage,
    onCreate,
}: {
    stats: { due_7d: number; overdue: number; active: number };
    canManage: boolean;
    onCreate: () => void;
}) {
    return (
        <HeroShell>
            <div className="flex flex-wrap items-center gap-4">
                <HeroMedallion icon={CalendarClock} />
                <div className="min-w-0">
                    <HeroStatusPill>Maintenance · service schedules</HeroStatusPill>
                    <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                        Service Schedules
                    </h1>
                    <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                        Recurring service and maintenance schedules for assets.
                    </p>
                </div>
                <div className="grid flex-1 grid-cols-3 gap-2 lg:ml-auto lg:max-w-xl">
                    <HeroClusterTile
                        label="Due 7d"
                        value={fmt(stats.due_7d)}
                        caption="services this week"
                        tone={stats.due_7d > 0 ? 'warning' : 'success'}
                    />
                    <HeroClusterTile
                        label="Overdue"
                        value={fmt(stats.overdue)}
                        caption="past due date"
                        tone={stats.overdue > 0 ? 'critical' : 'success'}
                    />
                    <HeroClusterTile
                        label="Active"
                        value={fmt(stats.active)}
                        caption="schedules running"
                        tone="neutral"
                    />
                </div>
            </div>
            {canManage ? (
                <div className="flex flex-wrap items-center gap-2">
                    <HeroActionButton onClick={onCreate} icon={Plus} emphasis>
                        Create schedule
                    </HeroActionButton>
                </div>
            ) : null}
        </HeroShell>
    );
}

export default function SchedulesIndex({
    schedules,
    assets,
    fleet_health_pct,
    schedules_per_vehicle,
    monthly_completions,
    upcoming_timeline,
    stats,
    can,
}: Props) {
    const allSchedules = schedules ?? [];
    const totalCount = allSchedules.length;
    const overdueCount = allSchedules.filter((s) => s.is_overdue).length;
    const dueSoonCount = allSchedules.filter(
        (s) => isDueSoon(s.next_due_at) && !s.is_overdue,
    ).length;
    const onTrackCount = totalCount - overdueCount - dueSoonCount;
    const healthPct =
        fleet_health_pct ??
        (totalCount > 0 ? Math.round((onTrackCount / totalCount) * 100) : 100);
    const timeline = upcoming_timeline ?? [];
    const vehicleData = schedules_per_vehicle ?? [];
    const completionData = monthly_completions ?? [];

    const [dialogOpen, setDialogOpen] = useState(false);
    const [scheduleStepIndex, setScheduleStepIndex] = useState(0);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
    const [markingId, setMarkingId] = useState<number | null>(null);

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(
            window.location.pathname,
            { sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    function handleMarkComplete(scheduleId: number) {
        setMarkingId(scheduleId);
        router.post(
            `/fleet-assets/maintenance/schedules/${scheduleId}/mark-complete`,
            {},
            {
                preserveState: true,
                onFinish: () => setMarkingId(null),
            },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
                onClick={() => handleSort(field)}
            >
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </div>
            </th>
        );
    };

    const form = useForm({
        name: '',
        asset_id: '',
        interval_km: '',
        interval_days: '',
        next_due_at: '',
    });

    const handleCreate = () => {
        form.post('/fleet-assets/maintenance/schedules', {
            onSuccess: () => {
                form.reset();
                setScheduleStepIndex(0);
                setDialogOpen(false);
            },
        });
    };
    const openScheduleDialog = () => {
        setScheduleStepIndex(0);
        setDialogOpen(true);
    };
    const closeScheduleDialog = () => {
        setScheduleStepIndex(0);
        setDialogOpen(false);
    };
    const canReviewSchedule = Boolean(
        form.data.name.trim() &&
            form.data.asset_id &&
            (form.data.interval_km || form.data.interval_days || form.data.next_due_at),
    );
    const selectedScheduleAsset = (assets ?? []).find(
        (asset) => String(asset.id) === form.data.asset_id,
    );

    // Timeline dot color helper
    function timelineDotColor(type: string): string {
        if (type === 'overdue') return 'bg-status-critical';
        if (type === 'soon') return 'bg-status-warning';
        return 'bg-primary';
    }

    function timelineIndicator(type: string): string {
        if (type === 'overdue') return 'text-status-critical';
        if (type === 'soon') return 'text-status-warning';
        return 'text-primary';
    }

    function timelineLabel(item: TimelineItem): string {
        if (item.type === 'overdue')
            return `${Math.abs(item.days_until)} days overdue`;
        if (item.days_until === 0) return 'Due today';
        if (item.days_until === 1) return 'Due in 1 day';
        return `Due in ${item.days_until} days`;
    }

    const heroStats = stats ?? {
        due_7d: dueSoonCount,
        overdue: overdueCount,
        active: totalCount,
    };

    // Create dialog — rendered once, controlled (opened from the hero quick action
    // and the empty state).
    const createScheduleDialog = can.manage ? (
        <WizardShell
            open={dialogOpen}
            onClose={closeScheduleDialog}
            title="Create service schedule"
            description="Set a Fleet asset service interval and review it before creating the schedule."
            railIcon={CalendarClock}
            railTitle="Service schedule"
            railSub="Fleet maintenance"
            steps={serviceScheduleSteps}
            stepIndex={scheduleStepIndex}
            onStepClick={(index) => {
                if (index === 0 || canReviewSchedule) setScheduleStepIndex(index);
            }}
            footerStart={
                <Button type="button" variant="outline" onClick={closeScheduleDialog}>
                    Cancel
                </Button>
            }
            footerEnd={
                scheduleStepIndex === 0 ? (
                    <Button type="button" disabled={!canReviewSchedule} onClick={() => setScheduleStepIndex(1)}>
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button type="button" variant="outline" onClick={() => setScheduleStepIndex(0)}>
                            Back
                        </Button>
                        <Button type="button" onClick={handleCreate} disabled={form.processing}>
                            {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                            Create schedule
                        </Button>
                    </>
                )
            }
        >
            {scheduleStepIndex === 0 ? (
                <WizardStepPane>
                    <div className="grid gap-4">
                    <div>
                        <label htmlFor="service-schedule-name" className="text-sm font-medium">Name *</label>
                        <Input
                            id="service-schedule-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="e.g. Oil Change"
                        />
                    </div>
                    <div>
                        <label htmlFor="service-schedule-asset" className="text-sm font-medium">Asset *</label>
                        <Select
                            value={form.data.asset_id}
                            onValueChange={(v) => form.setData('asset_id', v)}
                        >
                            <SelectTrigger id="service-schedule-asset">
                                <SelectValue placeholder="Select asset" />
                            </SelectTrigger>
                            <SelectContent>
                                {(assets ?? []).map((a) => (
                                    <SelectItem key={a.id} value={String(a.id)}>
                                        {a.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <label htmlFor="service-schedule-km" className="text-sm font-medium">
                            Interval (km)
                        </label>
                        <Input
                            id="service-schedule-km"
                            type="number"
                            value={form.data.interval_km}
                            onChange={(e) =>
                                form.setData('interval_km', e.target.value)
                            }
                            placeholder="e.g. 10000"
                        />
                    </div>
                    <div>
                        <label htmlFor="service-schedule-days" className="text-sm font-medium">
                            Interval (days)
                        </label>
                        <Input
                            id="service-schedule-days"
                            type="number"
                            value={form.data.interval_days}
                            onChange={(e) =>
                                form.setData('interval_days', e.target.value)
                            }
                            placeholder="e.g. 180"
                        />
                    </div>
                    <div>
                        <label htmlFor="service-schedule-due" className="text-sm font-medium">
                            Next Due Date
                        </label>
                        <Input
                            id="service-schedule-due"
                            type="date"
                            value={form.data.next_due_at}
                            onChange={(e) =>
                                form.setData('next_due_at', e.target.value)
                            }
                        />
                    </div>
                    {form.errors.name && (
                        <p className="mt-1 text-xs text-destructive">
                            {form.errors.name}
                        </p>
                    )}
                    {form.errors.asset_id && (
                        <p className="mt-1 text-xs text-destructive">
                            {form.errors.asset_id}
                        </p>
                    )}
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <dl className="grid gap-4 rounded-xl border border-border bg-card/70 p-4 text-sm sm:grid-cols-2">
                        <div><dt className="text-muted-foreground">Schedule</dt><dd className="font-medium">{form.data.name.trim()}</dd></div>
                        <div><dt className="text-muted-foreground">Asset</dt><dd className="font-medium">{selectedScheduleAsset?.name ?? 'Selected asset'}</dd></div>
                        <div><dt className="text-muted-foreground">Distance interval</dt><dd className="font-medium">{form.data.interval_km ? `${form.data.interval_km} km` : 'Not set'}</dd></div>
                        <div><dt className="text-muted-foreground">Time interval</dt><dd className="font-medium">{form.data.interval_days ? `${form.data.interval_days} days` : 'Not set'}</dd></div>
                    </dl>
                </WizardStepPane>
            )}
        </WizardShell>
    ) : null;

    if (totalCount === 0) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    {
                        title: 'Service Schedules',
                        href: '/fleet-assets/maintenance/schedules',
                    },
                ]}
            >
                <Head title="Service Schedules" />
                <PageShell>
                    <SchedulesHero
                        stats={heroStats}
                        canManage={can.manage}
                        onCreate={openScheduleDialog}
                    />
                    <FleetEmptyState
                        icon={Clock}
                        title="No service schedules"
                        description="Set up recurring maintenance reminders to keep your fleet in top condition."
                        actionLabel={can.manage ? 'Create Schedule' : undefined}
                        onAction={
                            can.manage ? openScheduleDialog : undefined
                        }
                    />
                    {createScheduleDialog}
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                {
                    title: 'Service Schedules',
                    href: '/fleet-assets/maintenance/schedules',
                },
            ]}
        >
            <Head title="Service Schedules" />
            <PageShell>
                <SchedulesHero
                    stats={heroStats}
                    canManage={can.manage}
                    onCreate={openScheduleDialog}
                />

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="flex items-center justify-center border bg-primary/10 transition-shadow hover:shadow-md dark:bg-primary/30">
                        <CardContent className="flex items-center justify-center p-4">
                            <HalfMoonGauge
                                value={healthPct}
                                label="Fleet Health"
                                sublabel={`${onTrackCount} of ${totalCount} on track`}
                                size={130}
                                color={FLEET_COLORS.primary}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Schedules by Vehicle
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {vehicleData.length > 0 ? (
                                <HorizontalBarChart
                                    items={vehicleData}
                                    color={FLEET_COLORS.primary}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No vehicle data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Monthly Completions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {completionData.length > 0 ? (
                                <MiniBarChart
                                    data={completionData}
                                    color={FLEET_COLORS.primary}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No completion data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Upcoming Service Timeline */}
                {timeline.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Activity className="h-4 w-4 text-primary" />
                                Upcoming Service Timeline
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    (Next 30 days)
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Visual timeline bar */}
                            <div className="relative">
                                <div className="mb-1 flex justify-between text-[10px] text-muted-foreground">
                                    <span>Today</span>
                                    <span>+30 days</span>
                                </div>
                                <div className="relative h-8 overflow-hidden rounded-full bg-muted/30">
                                    {timeline.map((item) => {
                                        // For overdue items, clamp to left edge
                                        const position =
                                            item.days_until < 0
                                                ? 0
                                                : Math.min(
                                                      (item.days_until / 30) *
                                                          100,
                                                      100,
                                                  );
                                        return (
                                            <div
                                                key={item.id}
                                                className="absolute top-0 flex h-full items-center"
                                                style={{ left: `${position}%` }}
                                                title={`${item.name} - ${item.vehicle} (${timelineLabel(item)})`}
                                            >
                                                <div
                                                    className={`h-4 w-4 rounded-full border-2 border-white shadow ${timelineDotColor(item.type)}`}
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Timeline list */}
                            <div className="space-y-1.5">
                                {timeline.map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <span
                                            className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${timelineDotColor(item.type)}`}
                                        />
                                        <span className="font-medium">
                                            {item.name}
                                        </span>
                                        <span className="text-muted-foreground">
                                            --
                                        </span>
                                        <span className="text-muted-foreground">
                                            {item.vehicle}
                                        </span>
                                        <span className="text-muted-foreground">
                                            --
                                        </span>
                                        <span
                                            className={`text-xs font-medium ${timelineIndicator(item.type)}`}
                                        >
                                            {timelineLabel(item)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Schedule Table */}
                <div className="overflow-hidden rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                {renderSortHeader('name', 'Schedule Name')}
                                <th className="px-4 py-3 text-left font-medium">
                                    Asset
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Interval
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Last Completed
                                </th>
                                {renderSortHeader('next_due_at', 'Next Due')}
                                <th className="px-4 py-3 text-left font-medium">
                                    Days Until
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Progress
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {allSchedules.map((schedule) => {
                                const days = daysUntilDue(schedule.next_due_at);
                                const progressPct = kmProgressPct(schedule);
                                const dueSoon =
                                    isDueSoon(schedule.next_due_at) &&
                                    !schedule.is_overdue;
                                return (
                                    <tr
                                        key={schedule.id}
                                        className={`border-b transition-colors hover:bg-muted/30 ${
                                            schedule.is_overdue
                                                ? 'border-l-4 border-l-red-500 bg-status-critical-bg'
                                                : dueSoon
                                                  ? 'border-l-4 border-l-amber-500'
                                                  : ''
                                        }`}
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4 text-muted-foreground" />
                                                <span className="font-medium">
                                                    {schedule.name}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {schedule.asset ? (
                                                <Link
                                                    href={`/fleet-assets/assets/${schedule.asset.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {schedule.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    ---
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {schedule.interval_km &&
                                                `${formatDistance(schedule.interval_km)}`}
                                            {schedule.interval_km &&
                                                schedule.interval_days &&
                                                ' / '}
                                            {schedule.interval_days &&
                                                `${schedule.interval_days} days`}
                                            {!schedule.interval_km &&
                                                !schedule.interval_days &&
                                                '---'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {schedule.last_completed_at
                                                ? formatDate(
                                                      schedule.last_completed_at,
                                                  )
                                                : '---'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {schedule.next_due_at ? (
                                                <div className="flex items-center gap-2">
                                                    <span>
                                                        {formatDate(
                                                            schedule.next_due_at,
                                                        )}
                                                    </span>
                                                    {schedule.is_overdue && (
                                                        <Badge
                                                            variant="destructive"
                                                            className="text-[10px] font-bold"
                                                        >
                                                            <AlertTriangle className="mr-1 h-3 w-3" />
                                                            Overdue
                                                        </Badge>
                                                    )}
                                                    {dueSoon && (
                                                        <Badge
                                                            variant="default"
                                                            className="text-[10px]"
                                                        >
                                                            Due soon
                                                        </Badge>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    ---
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {days !== null ? (
                                                <span
                                                    className={`text-xs font-medium ${
                                                        schedule.is_overdue
                                                            ? 'text-status-critical dark:text-status-critical'
                                                            : dueSoon
                                                              ? 'text-status-warning dark:text-status-warning'
                                                              : 'text-muted-foreground'
                                                    }`}
                                                >
                                                    {daysUntilLabel(
                                                        days,
                                                        schedule.is_overdue,
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    ---
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {progressPct !== null ? (
                                                <div className="flex items-center gap-1.5">
                                                    <div className="h-2 w-24 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary"
                                                            style={{
                                                                width: `${progressPct}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] text-muted-foreground tabular-nums">
                                                        {progressPct}%
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    ---
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manage ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 gap-1 text-xs"
                                                    disabled={
                                                        markingId ===
                                                        schedule.id
                                                    }
                                                    onClick={() =>
                                                        handleMarkComplete(
                                                            schedule.id,
                                                        )
                                                    }
                                                >
                                                    {markingId ===
                                                    schedule.id ? (
                                                        <Loader2 className="h-3 w-3 animate-spin" />
                                                    ) : (
                                                        <Check className="h-3 w-3" />
                                                    )}
                                                    Mark Complete
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    View only
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {createScheduleDialog}
            </PageShell>
        </AppLayout>
    );
}
