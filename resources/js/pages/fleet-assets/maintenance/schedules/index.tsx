import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { HalfMoonGauge, HorizontalBarChart, MiniBarChart, FLEET_COLORS } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle, Calendar, CheckCircle, ChevronDown, ChevronUp, ChevronsUpDown,
    Clock, Loader2, Plus, Timer, Activity, Check,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDistance } from '@/lib/fleet-utils';

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
};

function isDueSoon(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const diff = (new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24);
    return diff <= 14 && diff >= 0;
}

function daysUntilDue(dateStr: string | null): number | null {
    if (!dateStr) return null;
    return Math.ceil((new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24));
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
    const progress = ((nextKm - intervalKm) > 0)
        ? Math.max(0, Math.min(100, ((lastKm - (nextKm - intervalKm)) / intervalKm) * 100))
        : 0;
    return Math.round(progress);
}

export default function SchedulesIndex({
    schedules,
    assets,
    fleet_health_pct,
    schedules_per_vehicle,
    monthly_completions,
    upcoming_timeline,
}: Props) {
    const allSchedules = schedules ?? [];
    const totalCount = allSchedules.length;
    const overdueCount = allSchedules.filter((s) => s.is_overdue).length;
    const dueSoonCount = allSchedules.filter((s) => isDueSoon(s.next_due_at) && !s.is_overdue).length;
    const onTrackCount = totalCount - overdueCount - dueSoonCount;
    const healthPct = fleet_health_pct ?? (totalCount > 0 ? Math.round((onTrackCount / totalCount) * 100) : 100);
    const timeline = upcoming_timeline ?? [];
    const vehicleData = schedules_per_vehicle ?? [];
    const completionData = monthly_completions ?? [];

    const [dialogOpen, setDialogOpen] = useState(false);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
    const [markingId, setMarkingId] = useState<number | null>(null);

    function handleSort(field: string) {
        const newDir = sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(window.location.pathname, { sort: field, direction: newDir }, { preserveState: true });
    }

    function handleMarkComplete(scheduleId: number) {
        setMarkingId(scheduleId);
        router.post(`/fleet-assets/maintenance/schedules/${scheduleId}/mark-complete`, {}, {
            preserveState: true,
            onFinish: () => setMarkingId(null),
        });
    }

    function SortHeader({ field, children, className }: { field: string; children: React.ReactNode; className?: string }) {
        const active = sortField === field;
        return (
            <th className={`px-4 py-3 cursor-pointer select-none hover:bg-muted/50 font-medium ${className ?? 'text-left'}`} onClick={() => handleSort(field)}>
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (sortDir === 'asc' ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />) : <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />}
                </div>
            </th>
        );
    }

    const form = useForm({ name: '', asset_id: '', interval_km: '', interval_days: '', next_due_at: '' });

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/maintenance/schedules', {
            onSuccess: () => { form.reset(); setDialogOpen(false); },
        });
    };

    // Timeline dot color helper
    function timelineDotColor(type: string): string {
        if (type === 'overdue') return 'bg-red-500';
        if (type === 'soon') return 'bg-amber-500';
        return 'bg-purple-500';
    }

    function timelineIndicator(type: string): string {
        if (type === 'overdue') return 'text-red-500';
        if (type === 'soon') return 'text-amber-500';
        return 'text-purple-500';
    }

    function timelineLabel(item: TimelineItem): string {
        if (item.type === 'overdue') return `${Math.abs(item.days_until)} days overdue`;
        if (item.days_until === 0) return 'Due today';
        if (item.days_until === 1) return 'Due in 1 day';
        return `Due in ${item.days_until} days`;
    }

    if (totalCount === 0) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    { title: 'Service Schedules', href: '/fleet-assets/maintenance/schedules' },
                ]}
            >
                <Head title="Service Schedules" />
                <PageShell>
                    <PageHeader
                        title="Service Schedules"
                        description="Manage recurring service and maintenance schedules for assets."
                        actions={
                            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button><Plus className="mr-2 h-4 w-4" />Create Schedule</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader><DialogTitle>Create Service Schedule</DialogTitle></DialogHeader>
                                    <form onSubmit={handleCreate} className="grid gap-4">
                                        <div><label className="text-sm font-medium">Name *</label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Oil Change" /></div>
                                        <div><label className="text-sm font-medium">Asset *</label>
                                            <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select asset" /></SelectTrigger>
                                                <SelectContent>{(assets ?? []).map((a) => (<SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>))}</SelectContent>
                                            </Select>
                                        </div>
                                        <div><label className="text-sm font-medium">Interval (km)</label><Input type="number" value={form.data.interval_km} onChange={(e) => form.setData('interval_km', e.target.value)} placeholder="e.g. 10000" /></div>
                                        <div><label className="text-sm font-medium">Interval (days)</label><Input type="number" value={form.data.interval_days} onChange={(e) => form.setData('interval_days', e.target.value)} placeholder="e.g. 180" /></div>
                                        <div><label className="text-sm font-medium">Next Due Date</label><Input type="date" value={form.data.next_due_at} onChange={(e) => form.setData('next_due_at', e.target.value)} /></div>
                                        {form.errors.name && <p className="mt-1 text-xs text-destructive">{form.errors.name}</p>}
                                        {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                                        <Button type="submit" disabled={form.processing}>{form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Create Schedule</Button>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        }
                    />
                    <FleetEmptyState icon={Clock} title="No service schedules" description="Set up recurring maintenance reminders to keep your fleet in top condition." />
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Service Schedules', href: '/fleet-assets/maintenance/schedules' },
            ]}
        >
            <Head title="Service Schedules" />
            <PageShell>
                <PageHeader
                    title="Service Schedules"
                    description="Manage recurring service and maintenance schedules for assets."
                    actions={
                        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                            <DialogTrigger asChild>
                                <Button><Plus className="mr-2 h-4 w-4" />Create Schedule</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader><DialogTitle>Create Service Schedule</DialogTitle></DialogHeader>
                                <form onSubmit={handleCreate} className="grid gap-4">
                                    <div><label className="text-sm font-medium">Name *</label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Oil Change" /></div>
                                    <div><label className="text-sm font-medium">Asset *</label>
                                        <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select asset" /></SelectTrigger>
                                            <SelectContent>{(assets ?? []).map((a) => (<SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>))}</SelectContent>
                                        </Select>
                                    </div>
                                    <div><label className="text-sm font-medium">Interval (km)</label><Input type="number" value={form.data.interval_km} onChange={(e) => form.setData('interval_km', e.target.value)} placeholder="e.g. 10000" /></div>
                                    <div><label className="text-sm font-medium">Interval (days)</label><Input type="number" value={form.data.interval_days} onChange={(e) => form.setData('interval_days', e.target.value)} placeholder="e.g. 180" /></div>
                                    <div><label className="text-sm font-medium">Next Due Date</label><Input type="date" value={form.data.next_due_at} onChange={(e) => form.setData('next_due_at', e.target.value)} /></div>
                                    {form.errors.name && <p className="mt-1 text-xs text-destructive">{form.errors.name}</p>}
                                    {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                                    <Button type="submit" disabled={form.processing}>{form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Create Schedule</Button>
                                </form>
                            </DialogContent>
                        </Dialog>
                    }
                />

                {/* KPI Row */}
                <div className="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TOTAL SCHEDULES" value={totalCount} icon={Calendar} subtitle="All schedules" />
                    <FleetStatCard label="OVERDUE" value={overdueCount} icon={AlertTriangle} color="red" subtitle="Past due date" />
                    <FleetStatCard label="DUE SOON" value={dueSoonCount} icon={Timer} color="amber" subtitle="Within 14 days" />
                    <FleetStatCard label="ON TRACK" value={onTrackCount} icon={CheckCircle} subtitle="Up to date" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/30 transition-shadow hover:shadow-md flex items-center justify-center">
                        <CardContent className="p-4 flex items-center justify-center">
                            <HalfMoonGauge value={healthPct} label="Fleet Health" sublabel={`${onTrackCount} of ${totalCount} on track`} size={130} color={FLEET_COLORS.primary} />
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Schedules by Vehicle</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {vehicleData.length > 0 ? (
                                <HorizontalBarChart items={vehicleData} color={FLEET_COLORS.primary} />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">No vehicle data available.</p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Monthly Completions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {completionData.length > 0 ? (
                                <MiniBarChart data={completionData} color={FLEET_COLORS.primary} />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">No completion data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Upcoming Service Timeline */}
                {timeline.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium flex items-center gap-2">
                                <Activity className="h-4 w-4 text-purple-500" />
                                Upcoming Service Timeline
                                <span className="text-xs font-normal text-muted-foreground ml-1">(Next 30 days)</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Visual timeline bar */}
                            <div className="relative">
                                <div className="flex justify-between text-[10px] text-muted-foreground mb-1">
                                    <span>Today</span>
                                    <span>+30 days</span>
                                </div>
                                <div className="relative h-8 bg-muted/30 rounded-full overflow-hidden">
                                    {timeline.map((item) => {
                                        // For overdue items, clamp to left edge
                                        const position = item.days_until < 0 ? 0 : Math.min((item.days_until / 30) * 100, 100);
                                        return (
                                            <div
                                                key={item.id}
                                                className="absolute top-0 h-full flex items-center"
                                                style={{ left: `${position}%` }}
                                                title={`${item.name} - ${item.vehicle} (${timelineLabel(item)})`}
                                            >
                                                <div className={`h-4 w-4 rounded-full border-2 border-white shadow ${timelineDotColor(item.type)}`} />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Timeline list */}
                            <div className="space-y-1.5">
                                {timeline.map((item) => (
                                    <div key={item.id} className="flex items-center gap-2 text-sm">
                                        <span className={`inline-block h-2.5 w-2.5 rounded-full shrink-0 ${timelineDotColor(item.type)}`} />
                                        <span className="font-medium">{item.name}</span>
                                        <span className="text-muted-foreground">--</span>
                                        <span className="text-muted-foreground">{item.vehicle}</span>
                                        <span className="text-muted-foreground">--</span>
                                        <span className={`text-xs font-medium ${timelineIndicator(item.type)}`}>
                                            {timelineLabel(item)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Schedule Table */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <SortHeader field="name">Schedule Name</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">Asset</th>
                                <th className="px-4 py-3 text-left font-medium">Interval</th>
                                <th className="px-4 py-3 text-left font-medium">Last Completed</th>
                                <SortHeader field="next_due_at">Next Due</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">Days Until</th>
                                <th className="px-4 py-3 text-left font-medium">Progress</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {allSchedules.map((schedule) => {
                                const days = daysUntilDue(schedule.next_due_at);
                                const progressPct = kmProgressPct(schedule);
                                const dueSoon = isDueSoon(schedule.next_due_at) && !schedule.is_overdue;
                                return (
                                    <tr
                                        key={schedule.id}
                                        className={`border-b transition-colors hover:bg-muted/30 ${
                                            schedule.is_overdue
                                                ? 'bg-red-50/60 dark:bg-red-950/20 border-l-4 border-l-red-500'
                                                : dueSoon
                                                    ? 'border-l-4 border-l-amber-500'
                                                    : ''
                                        }`}
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4 text-muted-foreground" />
                                                <span className="font-medium">{schedule.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {schedule.asset ? (
                                                <Link href={`/fleet-assets/assets/${schedule.asset.id}`} className="text-primary hover:underline">{schedule.asset.name}</Link>
                                            ) : (
                                                <span className="text-muted-foreground">---</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {schedule.interval_km && `${formatDistance(schedule.interval_km)}`}
                                            {schedule.interval_km && schedule.interval_days && ' / '}
                                            {schedule.interval_days && `${schedule.interval_days} days`}
                                            {!schedule.interval_km && !schedule.interval_days && '---'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {schedule.last_completed_at ? formatDate(schedule.last_completed_at) : '---'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {schedule.next_due_at ? (
                                                <div className="flex items-center gap-2">
                                                    <span>{formatDate(schedule.next_due_at)}</span>
                                                    {schedule.is_overdue && (
                                                        <Badge variant="destructive" className="font-bold text-[10px]"><AlertTriangle className="mr-1 h-3 w-3" />Overdue</Badge>
                                                    )}
                                                    {dueSoon && (
                                                        <Badge variant="default" className="text-[10px]">Due soon</Badge>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">---</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {days !== null ? (
                                                <span className={`text-xs font-medium ${
                                                    schedule.is_overdue ? 'text-red-600 dark:text-red-400' :
                                                    dueSoon ? 'text-amber-600 dark:text-amber-400' :
                                                    'text-muted-foreground'
                                                }`}>
                                                    {daysUntilLabel(days, schedule.is_overdue)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">---</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {progressPct !== null ? (
                                                <div className="flex items-center gap-1.5">
                                                    <div className="h-2 w-24 rounded-full bg-muted overflow-hidden">
                                                        <div className="h-full rounded-full bg-purple-500" style={{ width: `${progressPct}%` }} />
                                                    </div>
                                                    <span className="text-[10px] text-muted-foreground tabular-nums">{progressPct}%</span>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">---</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 text-xs gap-1"
                                                disabled={markingId === schedule.id}
                                                onClick={() => handleMarkComplete(schedule.id)}
                                            >
                                                {markingId === schedule.id ? (
                                                    <Loader2 className="h-3 w-3 animate-spin" />
                                                ) : (
                                                    <Check className="h-3 w-3" />
                                                )}
                                                Mark Complete
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </AppLayout>
    );
}
