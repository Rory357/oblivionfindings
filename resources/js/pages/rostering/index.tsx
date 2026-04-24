import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Tabs } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

type Staff = { id: number; name: string; email?: string };
type Client = { id: number; first_name: string; last_name: string };
type ShiftLite = {
    id: number;
    client_id: number;
    user_id: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    status: string;
    service_context: string | null;
    client: string | null;
    staff: string | null;
    tasks_total: number;
    tasks_completed: number;
    incidents_count: number;
    timesheet_status: string | null;
};

type Props = {
    canManageAny: boolean;
    weekStart: string; // YYYY-MM-DD
    weekEnd: string; // YYYY-MM-DD (exclusive)
    filters: {
        week: string;
        staff_id: number | null;
        client_id: number | null;
    };
    staff: Staff[];
    clients: Client[];
    stats: {
        total: number;
        open: number;
        draft: number;
        scheduled: number;
        in_progress: number;
        completed: number;
        cancelled: number;
        incidents: number;
        staff_overlaps: number;
        client_overlaps: number;
        timesheets_pending: number;
        time_off_conflicts: number;
    };
    shifts: ShiftLite[];
    timeOffs: Array<{
        id: number;
        user_id: number;
        user: string | null;
        starts_at: string;
        ends_at: string;
        type: string;
        label: string | null;
        notes: string | null;
    }>;
    capacity: Array<{
        user_id: number;
        name: string;
        hours: number;
        warn: 'medium' | 'high' | null;
    }>;
};

function addDays(date: Date, days: number) {
    const d = new Date(date);
    d.setDate(d.getDate() + days);
    return d;
}

function ymd(d: Date) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function fmtDay(d: Date) {
    return d.toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: 'short' });
}

function fmtTime(iso: string) {
    const d = new Date(iso);
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}


function fmtHour(h: number) {
    return `${String(h).padStart(2, '0')}:00`;
}
function statusBadgeVariant(status: string): any {
    if (status === 'in_progress') return 'default';
    if (status === 'completed') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    if (status === 'draft') return 'outline';
    return 'outline';
}

function rangesOverlap(aStartIso: string, aEndIso: string, bStartIso: string, bEndIso: string) {
    const aS = new Date(aStartIso).getTime();
    const aE = new Date(aEndIso).getTime();
    const bS = new Date(bStartIso).getTime();
    const bE = new Date(bEndIso).getTime();
    return aS < bE && bS < aE;
}

function isShiftLocked(shift: ShiftLite) {
    return shift.status === 'completed';
}

function isActionableConflictShift(shift: ShiftLite) {
    return shift.status !== 'completed' && shift.status !== 'cancelled';
}

export default function RosteringIndex(props: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const timeOffForm = useForm({
        user_id: props.filters.staff_id ? String(props.filters.staff_id) : 'self',
        starts_at: `${props.weekStart}T09:00`,
        ends_at: `${props.weekStart}T17:00`,
        type: 'leave',
        label: '',
        notes: '',
        return_to: '/rostering',
    });

    const assignForm = useForm({ user_id: '', return_to: '/rostering' });

    // Ops dashboard: per-shift quick assignment selections
    const [opsAssignSelection, setOpsAssignSelection] = useState<Record<number, string>>({});

    const [resolveModal, setResolveModal] = useState<null | {
        kind: 'staff' | 'client';
        a: ShiftLite;
        b: ShiftLite;
        staffId?: number;
        clientId?: number;
    }>(null);

    const [resolveReassignSelection, setResolveReassignSelection] = useState<Record<number, string>>({});
    const [coverageMode, setCoverageMode] = useState<'understaffed' | 'assigned'>('understaffed');

    const resolveState = useMemo(() => {
        if (!resolveModal) return null;
        const aLocked = isShiftLocked(resolveModal.a);
        const bLocked = isShiftLocked(resolveModal.b);
        return {
            aLocked,
            bLocked,
            bothLocked: aLocked && bLocked,
        };
    }, [resolveModal]);

    const startDate = useMemo(() => new Date(`${props.weekStart}T00:00:00`), [props.weekStart]);

    const staffById = useMemo(() => {
        const m = new Map<number, Staff>();
        for (const s of props.staff) m.set(s.id, s);
        return m;
    }, [props.staff]);

    const capacityByUserId = useMemo(() => {
        const m = new Map<number, { hours: number; warn: 'medium' | 'high' | null }>();
        for (const c of props.capacity ?? []) m.set(c.user_id, { hours: c.hours, warn: c.warn });
        return m;
    }, [props.capacity]);

    const availableStaffForShift = (shift: ShiftLite) => {
        if (!props.canManageAny) return [] as Staff[];
        // Candidate staff are staff list filtered by: no shift conflicts, no time-off conflicts.
        const candidates: Staff[] = [];
        for (const u of props.staff) {
            // shift conflict check
            const hasShiftConflict = props.shifts.some((s) => {
                if (s.id === shift.id) return false;
                if (s.user_id !== u.id) return false;
                return rangesOverlap(s.starts_at, s.ends_at, shift.starts_at, shift.ends_at);
            });
            if (hasShiftConflict) continue;

            const hasTimeOff = (props.timeOffs ?? []).some((t) => {
                if (t.user_id !== u.id) return false;
                return rangesOverlap(t.starts_at, t.ends_at, shift.starts_at, shift.ends_at);
            });
            if (hasTimeOff) continue;

            candidates.push(u);
        }

        // sort by capacity (lowest hours first), then name
        candidates.sort((a, b) => {
            const ah = capacityByUserId.get(a.id)?.hours ?? 0;
            const bh = capacityByUserId.get(b.id)?.hours ?? 0;
            if (ah !== bh) return ah - bh;
            return a.name.localeCompare(b.name);
        });
        return candidates;
    };

    const days = useMemo(() => {
        return Array.from({ length: 7 }).map((_, i) => addDays(startDate, i));
    }, [startDate]);

    const shiftsByStaffDay = useMemo(() => {
        const map = new Map<string, ShiftLite[]>();
        for (const s of props.shifts) {
            const d = ymd(new Date(s.starts_at));
            const staffId = s.user_id ?? 0;
            const key = `${staffId}-${d}`;
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(s);
        }
        // sort each bucket by time
        for (const [k, v] of map.entries()) {
            v.sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
            map.set(k, v);
        }
        return map;
    }, [props.shifts]);

    const shiftsByDay = useMemo(() => {
        const map = new Map<string, ShiftLite[]>();
        for (const s of props.shifts) {
            const d = ymd(new Date(s.starts_at));
            if (!map.has(d)) map.set(d, []);
            map.get(d)!.push(s);
        }
        for (const [k, v] of map.entries()) {
            v.sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
            map.set(k, v);
        }
        return map;
    }, [props.shifts]);

    const openShifts = useMemo(() => props.shifts.filter((s) => s.user_id === null), [props.shifts]);


    const coverageHeatmap = useMemo(() => {
        // 7 days x 24 hours: show assigned vs open demand per hour block.
        const dayKeys = days.map((d) => ymd(d));
        const grid: Record<string, { assigned: number[]; open: number[] }> = {};
        for (const dk of dayKeys) {
            grid[dk] = { assigned: Array(24).fill(0), open: Array(24).fill(0) };
        }

        for (const sh of props.shifts) {
            const s = new Date(sh.starts_at);
            const e = new Date(sh.ends_at);
            // walk hour blocks overlapped by shift
            let cur = new Date(s);
            cur.setMinutes(0, 0, 0);
            while (cur.getTime() < e.getTime()) {
                const hourStart = cur.getTime();
                const hourEnd = hourStart + 60 * 60 * 1000;
                const overlaps = s.getTime() < hourEnd && e.getTime() > hourStart;
                if (overlaps) {
                    const dk = ymd(new Date(hourStart));
                    const h = new Date(hourStart).getHours();
                    if (grid[dk]) {
                        if (sh.user_id === null) grid[dk].open[h] += 1;
                        else grid[dk].assigned[h] += 1;
                    }
                }
                cur = new Date(hourEnd);
            }
        }

        return { dayKeys, grid };
    }, [props.shifts, days]);


    const actionableShifts = useMemo(() => props.shifts.filter(isActionableConflictShift), [props.shifts]);

    const timeOffConflicts = useMemo(() => {
        if (!props.timeOffs?.length) return [] as Array<{ shift: ShiftLite; timeOffId: number; label: string }>;
        const out: Array<{ shift: ShiftLite; timeOffId: number; label: string }> = [];
        for (const sh of actionableShifts) {
            if (!sh.user_id) continue;
            for (const t of props.timeOffs) {
                if (t.user_id !== sh.user_id) continue;
                if (rangesOverlap(sh.starts_at, sh.ends_at, t.starts_at, t.ends_at)) {
                    out.push({
                        shift: sh,
                        timeOffId: t.id,
                        label: `${t.user ?? 'Staff'} · ${t.type}${t.label ? ` · ${t.label}` : ''}`,
                    });
                }
            }
        }
        return out;
    }, [actionableShifts, props.timeOffs]);

    const staffOverlapsDetailed = useMemo(() => {
        const byStaff = new Map<number, ShiftLite[]>();
        for (const s of actionableShifts) {
            if (!s.user_id) continue;
            if (!byStaff.has(s.user_id)) byStaff.set(s.user_id, []);
            byStaff.get(s.user_id)!.push(s);
        }
        const out: Array<{ staffId: number; a: ShiftLite; b: ShiftLite }> = [];
        for (const [staffId, list] of byStaff.entries()) {
            list.sort((x, y) => new Date(x.starts_at).getTime() - new Date(y.starts_at).getTime());
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (rangesOverlap(a.starts_at, a.ends_at, b.starts_at, b.ends_at)) {
                    out.push({ staffId, a, b });
                }
            }
        }
        return out;
    }, [actionableShifts]);

    const clientOverlapsDetailed = useMemo(() => {
        const byClient = new Map<number, ShiftLite[]>();
        for (const s of actionableShifts) {
            if (!byClient.has(s.client_id)) byClient.set(s.client_id, []);
            byClient.get(s.client_id)!.push(s);
        }
        const out: Array<{ clientId: number; a: ShiftLite; b: ShiftLite }> = [];
        for (const [clientId, list] of byClient.entries()) {
            list.sort((x, y) => new Date(x.starts_at).getTime() - new Date(y.starts_at).getTime());
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (rangesOverlap(a.starts_at, a.ends_at, b.starts_at, b.ends_at)) {
                    out.push({ clientId, a, b });
                }
            }
        }
        return out;
    }, [actionableShifts]);

    const historicalLockedOverlaps = useMemo(() => {
        const lockedShifts = props.shifts.filter(isShiftLocked);
        const out: Array<{ kind: 'staff' | 'client'; a: ShiftLite; b: ShiftLite }> = [];

        const byStaff = new Map<number, ShiftLite[]>();
        for (const s of lockedShifts) {
            if (!s.user_id) continue;
            if (!byStaff.has(s.user_id)) byStaff.set(s.user_id, []);
            byStaff.get(s.user_id)!.push(s);
        }
        for (const [, list] of byStaff.entries()) {
            list.sort((x, y) => new Date(x.starts_at).getTime() - new Date(y.starts_at).getTime());
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (rangesOverlap(a.starts_at, a.ends_at, b.starts_at, b.ends_at)) {
                    out.push({ kind: 'staff', a, b });
                }
            }
        }

        const byClient = new Map<number, ShiftLite[]>();
        for (const s of lockedShifts) {
            if (!byClient.has(s.client_id)) byClient.set(s.client_id, []);
            byClient.get(s.client_id)!.push(s);
        }
        for (const [, list] of byClient.entries()) {
            list.sort((x, y) => new Date(x.starts_at).getTime() - new Date(y.starts_at).getTime());
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (rangesOverlap(a.starts_at, a.ends_at, b.starts_at, b.ends_at)) {
                    out.push({ kind: 'client', a, b });
                }
            }
        }

        return out;
    }, [props.shifts]);

    const timesheetsNeedingAttention = useMemo(() => {
        if (props.canManageAny) {
            return props.shifts.filter((s) => s.timesheet_status === 'submitted');
        }
        return props.shifts.filter((s) => s.timesheet_status === 'draft' || s.timesheet_status === 'returned');
    }, [props.shifts, props.canManageAny]);

    const shiftsWithIncidents = useMemo(() => props.shifts.filter((s) => s.incidents_count > 0), [props.shifts]);

    const goWeek = (offsetDays: number) => {
        const target = ymd(addDays(startDate, offsetDays));
        router.get(
            '/rostering',
            {
                week: target,
                staff_id: props.filters.staff_id ?? undefined,
                client_id: props.filters.client_id ?? undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const updateFilter = (next: Partial<Props['filters']>) => {
        router.get(
            '/rostering',
            {
                week: props.filters.week,
                staff_id: next.staff_id ?? props.filters.staff_id ?? undefined,
                client_id: next.client_id ?? props.filters.client_id ?? undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Rostering', href: '/rostering' }]}>
            <Head title="Rostering" />

            <div className="space-y-4 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <HeadingSmall title="Rostering" description="Week view of shifts with operational signals (tasks, incidents, timesheets)." />
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => goWeek(-7)}>
                            <ChevronLeft className="mr-1 h-4 w-4" /> Prev
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => goWeek(7)}>
                            Next <ChevronRight className="ml-1 h-4 w-4" />
                        </Button>

                        <Separator orientation="vertical" className="mx-1 hidden h-8 md:block" />

                        <Link href="/shifts/create">
                            <Button size="sm">
                                <Plus className="mr-1 h-4 w-4" /> New shift
                            </Button>
                        </Link>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">This week</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">{props.weekStart} → {ymd(addDays(startDate, 6))}</div>
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="outline">Total: {props.stats.total}</Badge>
                                <Badge variant={props.stats.open > 0 ? 'default' : 'outline'}>Open: {props.stats.open}</Badge>
                                <Badge variant="outline">Scheduled: {props.stats.scheduled}</Badge>
                                <Badge variant="outline">In progress: {props.stats.in_progress}</Badge>
                                <Badge variant="outline">Draft: {props.stats.draft}</Badge>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Operational signals</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Badge variant={props.stats.incidents > 0 ? 'destructive' : 'outline'}>
                                Incidents: {props.stats.incidents}
                            </Badge>
                            <Badge variant={props.stats.timesheets_pending > 0 ? 'default' : 'outline'}>
                                Timesheets pending: {props.stats.timesheets_pending}
                            </Badge>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Overlaps</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Badge variant={props.stats.staff_overlaps > 0 ? 'destructive' : 'outline'}>
                                Staff overlaps: {props.stats.staff_overlaps}
                            </Badge>
                            <Badge variant={props.stats.client_overlaps > 0 ? 'destructive' : 'outline'}>
                                {clientSingular} overlaps: {props.stats.client_overlaps}
                            </Badge>
                            <Badge variant={props.stats.time_off_conflicts > 0 ? 'destructive' : 'outline'}>
                                Time-off conflicts: {props.stats.time_off_conflicts}
                            </Badge>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Filters</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {props.canManageAny ? (
                                <div className="grid grid-cols-1 gap-2">
                                    <Select
                                        value={props.filters.staff_id ? String(props.filters.staff_id) : 'all'}
                                        onValueChange={(v) => updateFilter({ staff_id: v === 'all' ? null : Number(v) })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All staff" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All staff</SelectItem>
                                            {props.staff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Select
                                        value={props.filters.client_id ? String(props.filters.client_id) : 'all'}
                                        onValueChange={(v) => updateFilter({ client_id: v === 'all' ? null : Number(v) })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder={`All ${clientPlural.toLowerCase()}`} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">{`All ${clientPlural.toLowerCase()}`}</SelectItem>
                                            {props.clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : (
                                <div className="text-sm text-muted-foreground">You’re viewing your assigned shifts.</div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Tabs
                    tabs={[
                        {
                            key: 'ops',
                            label: (
                                <span className="flex items-center gap-2">
                                    Ops
                                    {props.stats.open + props.stats.staff_overlaps + props.stats.client_overlaps + props.stats.time_off_conflicts > 0 ? (
                                        <Badge variant="destructive" className="text-[10px]">
                                            action
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-[10px]">
                                            ok
                                        </Badge>
                                    )}
                                </span>
                            ),
                            content: (
                                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    <Card className="lg:col-span-2">
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-base">Fix now</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* Open shifts */}
                                            <div className="space-y-2">
                                                <div className="flex items-center justify-between">
                                                    <div className="text-sm font-medium">Open shifts</div>
                                                    <Badge variant={openShifts.length > 0 ? 'default' : 'outline'}>{openShifts.length}</Badge>
                                                </div>
                                                {openShifts.length === 0 ? (
                                                    <div className="text-sm text-muted-foreground">No open shifts in this week.</div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {openShifts.slice(0, 8).map((sh) => (
                                                            <div key={sh.id} className="rounded-md border p-3">
                                                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                                    <div>
                                                                        <div className="text-sm font-medium">
                                                                            {sh.client ?? clientSingular} · {new Date(sh.starts_at).toLocaleDateString()} {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            {sh.location ? `${sh.location} · ` : ''}Status: {sh.status}
                                                                        </div>
                                                                    </div>

                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        {props.canManageAny ? (
                                                                            <>
                                                                                <Select
                                                                                    value={opsAssignSelection[sh.id] ?? ''}
                                                                                    onValueChange={(v) =>
                                                                                        setOpsAssignSelection((prev) => ({ ...prev, [sh.id]: v }))
                                                                                    }
                                                                                >
                                                                                    <SelectTrigger className="w-[220px]">
                                                                                        <SelectValue placeholder="Assign staff" />
                                                                                    </SelectTrigger>
                                                                                    <SelectContent>
                                                                                        {availableStaffForShift(sh).map((s) => (
                                                                                            <SelectItem key={s.id} value={String(s.id)}>
                                                                                                {s.name}
                                                                                            </SelectItem>
                                                                                        ))}
                                                                                    </SelectContent>
                                                                                </Select>
                                                                                <Button
                                                                                    size="sm"
                                                                                    disabled={!opsAssignSelection[sh.id]}
                                                                                    onClick={() => {
                                                                                        const userId = opsAssignSelection[sh.id];
                                                                                        if (!userId) return;
                                                                                        router.post(
                                                                                            `/shifts/${sh.id}/assign`,
                                                                                            { user_id: userId, return_to: '/rostering' },
                                                                                            {
                                                                                                preserveScroll: true,
                                                                                                onSuccess: () =>
                                                                                                    setOpsAssignSelection((prev) => ({ ...prev, [sh.id]: '' })),
                                                                                            },
                                                                                        );
                                                                                    }}
                                                                                >
                                                                                    Assign
                                                                                </Button>
                                                                            </>
                                                                        ) : null}
                                                                        <Link href={`/shifts/${sh.id}`}>
                                                                            <Button size="sm" variant="outline">
                                                                                Open
                                                                            </Button>
                                                                        </Link>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ))}
                                                        {openShifts.length > 8 ? (
                                                            <div className="text-xs text-muted-foreground">Showing 8 of {openShifts.length} open shifts.</div>
                                                        ) : null}
                                                    </div>
                                                )}
                                            </div>

                                            <Separator />

                                            {/* Conflicts */}
                                            <div className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <div className="text-sm font-medium">Conflicts</div>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Badge variant={timeOffConflicts.length > 0 ? 'destructive' : 'outline'}>
                                                            Time-off: {timeOffConflicts.length}
                                                        </Badge>
                                                        <Badge variant={staffOverlapsDetailed.length > 0 ? 'destructive' : 'outline'}>
                                                            Staff overlaps: {staffOverlapsDetailed.length}
                                                        </Badge>
                                                        <Badge variant={clientOverlapsDetailed.length > 0 ? 'destructive' : 'outline'}>
                                                            Client overlaps: {clientOverlapsDetailed.length}
                                                        </Badge>
                                                        <Badge variant={historicalLockedOverlaps.length > 0 ? 'secondary' : 'outline'}>
                                                            Historical (locked): {historicalLockedOverlaps.length}
                                                        </Badge>
                                                    </div>
                                                </div>

                                                {timeOffConflicts.length === 0 && staffOverlapsDetailed.length === 0 && clientOverlapsDetailed.length === 0 ? (
                                                    <div className="text-sm text-muted-foreground">No actionable conflicts detected in this roster window.</div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {timeOffConflicts.slice(0, 4).map(({ shift, timeOffId, label }) => (
                                                            <div key={`to-${shift.id}-${timeOffId}`} className="rounded-md border p-3">
                                                                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                    <div>
                                                                        <div className="text-sm font-medium">Time-off conflict · {shift.staff ?? 'Staff'}</div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            {shift.client ?? clientSingular} · {new Date(shift.starts_at).toLocaleDateString()} {fmtTime(shift.starts_at)}–{fmtTime(shift.ends_at)}
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">{label}</div>
                                                                    </div>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <Link href={`/shifts/${shift.id}`}>
                                                                            <Button size="sm" variant="outline">Open shift</Button>
                                                                        </Link>
                                                                        {props.canManageAny ? (
                                                                            <Button
                                                                                size="sm"
                                                                                variant="destructive"
                                                                                onClick={() => {
                                                                                    router.delete(`/rostering/time-off/${timeOffId}`, {
                                                                                        preserveScroll: true,
                                                                                    });
                                                                                }}
                                                                            >
                                                                                Remove time-off
                                                                            </Button>
                                                                        ) : null}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ))}

                                                        {staffOverlapsDetailed.slice(0, 4).map(({ a, b }) => (
                                                            <div key={`so-${a.id}-${b.id}`} className="rounded-md border p-3">
                                                                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                    <div>
                                                                        <div className="text-sm font-medium">Staff overlap · {a.staff ?? 'Staff'}</div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            A: {new Date(a.starts_at).toLocaleDateString()} {fmtTime(a.starts_at)}–{fmtTime(a.ends_at)} · {a.client ?? clientSingular}
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            B: {new Date(b.starts_at).toLocaleDateString()} {fmtTime(b.starts_at)}–{fmtTime(b.ends_at)} · {b.client ?? clientSingular}
                                                                        </div>
                                                                    </div>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <Link href={`/shifts/${a.id}`}><Button size="sm" variant="outline">Open A</Button></Link>
                                                                        <Link href={`/shifts/${b.id}`}><Button size="sm" variant="outline">Open B</Button></Link>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="default"
                                                                            onClick={() =>
                                                                                setResolveModal({
                                                                                    kind: 'staff',
                                                                                    a,
                                                                                    b,
                                                                                    staffId: a.user_id ?? b.user_id ?? undefined,
                                                                                })
                                                                            }
                                                                        >
                                                                            Resolve
                                                                        </Button>
                                                                        {props.canManageAny ? (
                                                                            <Button
                                                                                size="sm"
                                                                                variant="destructive"
                                                                                onClick={() => {
                                                                                    router.post(`/shifts/${b.id}/unassign`, { return_to: '/rostering' }, { preserveScroll: true });
                                                                                }}
                                                                            >
                                                                                Unassign B
                                                                            </Button>
                                                                        ) : null}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ))}

                                                        {clientOverlapsDetailed.slice(0, 4).map(({ clientId, a, b }) => (
                                                            <div key={`co-${a.id}-${b.id}`} className="rounded-md border p-3">
                                                                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                    <div>
                                                                        <div className="text-sm font-medium">{clientSingular} overlap · {a.client ?? clientSingular}</div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            A: {new Date(a.starts_at).toLocaleDateString()} {fmtTime(a.starts_at)}–{fmtTime(a.ends_at)} · {a.staff ?? 'Staff'}
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            B: {new Date(b.starts_at).toLocaleDateString()} {fmtTime(b.starts_at)}–{fmtTime(b.ends_at)} · {b.staff ?? 'Staff'}
                                                                        </div>
                                                                    </div>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <Link href={`/shifts/${a.id}`}><Button size="sm" variant="outline">Open A</Button></Link>
                                                                        <Link href={`/shifts/${b.id}`}><Button size="sm" variant="outline">Open B</Button></Link>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="default"
                                                                            onClick={() => setResolveModal({ kind: 'client', a, b, clientId })}
                                                                        >
                                                                            Resolve
                                                                        </Button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}

                                                {historicalLockedOverlaps.length > 0 ? (
                                                    <div className="rounded-md border border-dashed p-3">
                                                        <div className="text-sm font-medium">Historical overlaps (both shifts locked)</div>
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            These are non-actionable in rostering. Reopen a shift only if an audit correction is required.
                                                        </div>
                                                    </div>
                                                ) : null}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <div className="space-y-3">
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-base">Payroll & compliance</CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <div className="text-sm font-medium">Timesheets needing attention</div>
                                                    <Badge variant={timesheetsNeedingAttention.length > 0 ? 'default' : 'outline'}>
                                                        {timesheetsNeedingAttention.length}
                                                    </Badge>
                                                </div>
                                                {timesheetsNeedingAttention.length === 0 ? (
                                                    <div className="text-sm text-muted-foreground">Nothing to action in this week.</div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {timesheetsNeedingAttention.slice(0, 6).map((sh) => (
                                                            <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                                                <div className="rounded-md border p-2 hover:bg-muted">
                                                                    <div className="flex items-start justify-between gap-2">
                                                                        <div className="text-xs font-medium">
                                                                            {new Date(sh.starts_at).toLocaleDateString()} {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                                        </div>
                                                                        {sh.timesheet_status ? (
                                                                            <Badge variant="outline" className="text-[10px]">TS: {sh.timesheet_status}</Badge>
                                                                        ) : null}
                                                                    </div>
                                                                    <div className="mt-1 text-xs text-foreground">{sh.client ?? clientSingular} · {sh.staff ?? 'Staff'}</div>
                                                                </div>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                                <div>
                                                    <Link href="/timesheets">
                                                        <Button variant="outline" size="sm">Open Timesheets</Button>
                                                    </Link>
                                                </div>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-base">Safety signals</CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <div className="text-sm font-medium">Shifts with incidents</div>
                                                    <Badge variant={shiftsWithIncidents.length > 0 ? 'destructive' : 'outline'}>
                                                        {shiftsWithIncidents.length}
                                                    </Badge>
                                                </div>
                                                {shiftsWithIncidents.length === 0 ? (
                                                    <div className="text-sm text-muted-foreground">No incidents linked to shifts in this week.</div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {shiftsWithIncidents.slice(0, 6).map((sh) => (
                                                            <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                                                <div className="rounded-md border p-2 hover:bg-muted">
                                                                    <div className="flex items-start justify-between gap-2">
                                                                        <div className="text-xs font-medium">
                                                                            {new Date(sh.starts_at).toLocaleDateString()} {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                                        </div>
                                                                        <Badge variant="destructive" className="text-[10px]">
                                                                            {sh.incidents_count} incident{sh.incidents_count === 1 ? '' : 's'}
                                                                        </Badge>
                                                                    </div>
                                                                    <div className="mt-1 text-xs text-foreground">{sh.client ?? clientSingular} · {sh.staff ?? 'Staff'}</div>
                                                                </div>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                                <div>
                                                    <Link href="/incidents">
                                                        <Button variant="outline" size="sm">Open Incidents</Button>
                                                    </Link>
                                                </div>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-base">Capacity</CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {props.capacity.filter((c) => c.warn).length === 0 ? (
                                                    <div className="text-sm text-muted-foreground">No capacity warnings this week.</div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {props.capacity
                                                            .filter((c) => c.warn)
                                                            .slice(0, 8)
                                                            .map((c) => (
                                                                <div key={c.user_id} className="flex items-center justify-between rounded-md border p-2">
                                                                    <div className="text-sm">{c.name}</div>
                                                                    <Badge variant={c.warn === 'high' ? 'destructive' : 'default'}>
                                                                        {c.hours.toFixed(1)}h
                                                                    </Badge>
                                                                </div>
                                                            ))}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-base">Coverage heatmap</CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-3">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="text-xs text-muted-foreground">Hourly view for this week. Understaffed = open demand not assigned.</div>
                                                    <div className="flex items-center gap-2">
                                                        <Button size="sm" variant={coverageMode === 'understaffed' ? 'default' : 'outline'} onClick={() => setCoverageMode('understaffed')}>
                                                            Understaffed
                                                        </Button>
                                                        <Button size="sm" variant={coverageMode === 'assigned' ? 'default' : 'outline'} onClick={() => setCoverageMode('assigned')}>
                                                            Assigned
                                                        </Button>
                                                    </div>
                                                </div>

                                                <div className="overflow-x-auto">
                                                    <div className="min-w-[720px]">
                                                        <div className="grid grid-cols-[72px_repeat(7,1fr)] gap-1">
                                                            <div className="text-[10px] text-muted-foreground"></div>
                                                            {days.map((d) => (
                                                                <div key={ymd(d)} className="text-[10px] font-medium text-foreground">{fmtDay(d)}</div>
                                                            ))}

                                                            {Array.from({ length: 24 }).map((_, h) => (
                                                                <Fragment key={h}>
                                                                    <div className="text-[10px] text-muted-foreground">{String(h).padStart(2, '0')}:00</div>
                                                                    {days.map((d) => {
                                                                        const dk = ymd(d);
                                                                        const cell = coverageHeatmap.grid[dk]?.assigned ? {
                                                                            assigned: coverageHeatmap.grid[dk].assigned[h] ?? 0,
                                                                            open: coverageHeatmap.grid[dk].open[h] ?? 0,
                                                                        } : { assigned: 0, open: 0 };
                                                                        const v = coverageMode === 'assigned' ? cell.assigned : cell.open;
                                                                        const bg = coverageMode === 'assigned'
                                                                            ? (v >= 3 ? 'bg-muted' : v === 2 ? 'bg-muted' : v === 1 ? 'bg-muted' : 'bg-transparent')
                                                                            : (v >= 3 ? 'bg-status-warning-bg' : v === 2 ? 'bg-status-warning-bg' : v === 1 ? 'bg-status-warning-bg' : 'bg-transparent');
                                                                        return (
                                                                            <div key={`${dk}-${h}`} className={`h-7 rounded border ${bg} flex items-center justify-center`}>
                                                                                <span className="text-[10px] text-foreground">{v > 0 ? v : ''}</span>
                                                                            </div>
                                                                        );
                                                                    })}
                                                                </Fragment>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2 text-[10px] text-muted-foreground">
                                                    <span>Tip: Understaffed cells indicate hours where an open shift exists.</span>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'week',
                            label: 'Week roster',
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">Week roster</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {props.canManageAny ? (
                                            <div className="overflow-x-auto">
                                                <table className="min-w-[900px] w-full border-collapse">
                                                    <thead>
                                                        <tr className="border-b">
                                                            <th className="w-48 px-2 py-2 text-left text-xs font-medium text-muted-foreground">Staff</th>
                                                            {days.map((d) => (
                                                                <th key={ymd(d)} className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">
                                                                    {fmtDay(d)}
                                                                </th>
                                                            ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {props.staff.map((s) => (
                                                            <tr key={s.id} className="border-b align-top">
                                                                <td className="px-2 py-3 text-sm font-medium">{s.name}</td>
                                                                {days.map((d) => {
                                                                    const key = `${s.id}-${ymd(d)}`;
                                                                    const items = shiftsByStaffDay.get(key) ?? [];
                                                                    return (
                                                                        <td key={key} className="px-2 py-2">
                                                                            <div className="space-y-2">
                                                                                {items.length === 0 ? (
                                                                                    <div className="text-xs text-muted-foreground">—</div>
                                                                                ) : (
                                                                                    items.map((sh) => (
                                                                                        <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                                                                            <div className="rounded-md border p-2 hover:bg-muted">
                                                                                                <div className="flex items-start justify-between gap-2">
                                                                                                    <div className="text-xs font-medium">
                                                                                                        {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                                                                    </div>
                                                                                                    <Badge variant={statusBadgeVariant(sh.status)} className="text-[10px]">
                                                                                                        {sh.status}
                                                                                                    </Badge>
                                                                                                </div>
                                                                                                <div className="mt-1 text-xs text-foreground">{sh.client ?? clientSingular}</div>

                                                                                                <div className="mt-1 flex flex-wrap gap-1">
                                                                                                    {sh.incidents_count > 0 && (
                                                                                                        <Badge variant="destructive" className="text-[10px]">
                                                                                                            {sh.incidents_count} incident{sh.incidents_count === 1 ? '' : 's'}
                                                                                                        </Badge>
                                                                                                    )}
                                                                                                    {sh.tasks_total > 0 && (
                                                                                                        <Badge variant="outline" className="text-[10px]">
                                                                                                            Tasks: {sh.tasks_completed}/{sh.tasks_total}
                                                                                                        </Badge>
                                                                                                    )}
                                                                                                    {sh.timesheet_status && (
                                                                                                        <Badge variant="outline" className="text-[10px]">
                                                                                                            TS: {sh.timesheet_status}
                                                                                                        </Badge>
                                                                                                    )}
                                                                                                </div>
                                                                                            </div>
                                                                                        </Link>
                                                                                    ))
                                                                                )}
                                                                            </div>
                                                                        </td>
                                                                    );
                                                                })}
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <div className="space-y-4">
                                                {days.map((d) => {
                                                    const key = ymd(d);
                                                    const items = shiftsByDay.get(key) ?? [];
                                                    return (
                                                        <div key={key}>
                                                            <div className="mb-2 text-sm font-medium">{fmtDay(d)}</div>
                                                            <div className="space-y-2">
                                                                {items.length === 0 ? (
                                                                    <div className="text-sm text-muted-foreground">No shifts.</div>
                                                                ) : (
                                                                    items.map((sh) => (
                                                                        <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                                                            <div className="rounded-md border p-3 hover:bg-muted">
                                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                                    <div className="text-sm font-medium">
                                                                                        {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)} · {sh.client}
                                                                                    </div>
                                                                                    <Badge variant={statusBadgeVariant(sh.status)}>{sh.status}</Badge>
                                                                                </div>
                                                                                <div className="mt-2 flex flex-wrap gap-2">
                                                                                    {sh.tasks_total > 0 && (
                                                                                        <Badge variant="outline">
                                                                                            Tasks: {sh.tasks_completed}/{sh.tasks_total}
                                                                                        </Badge>
                                                                                    )}
                                                                                    {sh.incidents_count > 0 && (
                                                                                        <Badge variant="destructive">
                                                                                            {sh.incidents_count} incident{sh.incidents_count === 1 ? '' : 's'}
                                                                                        </Badge>
                                                                                    )}
                                                                                    {sh.timesheet_status && (
                                                                                        <Badge variant="outline">Timesheet: {sh.timesheet_status}</Badge>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                        </Link>
                                                                    ))
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ),
                        },
                        {
                            key: 'open',
                            label: (
                                <span className="flex items-center gap-2">
                                    Open shifts
                                    {props.stats.open > 0 ? <Badge variant="default" className="text-[10px]">{props.stats.open}</Badge> : null}
                                </span>
                            ),
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">Open / unassigned shifts</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {!props.canManageAny ? (
                                            <div className="text-sm text-muted-foreground">Only managers can assign open shifts.</div>
                                        ) : null}

                                        {props.shifts.filter((s) => s.user_id === null).length === 0 ? (
                                            <div className="text-sm text-muted-foreground">No open shifts in this week.</div>
                                        ) : (
                                            <div className="space-y-2">
                                                {props.shifts
                                                    .filter((s) => s.user_id === null)
                                                    .map((sh) => (
                                                        <div key={sh.id} className="rounded-md border p-3">
                                                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                                <div>
                                                                    <div className="text-sm font-medium">{sh.client ?? clientSingular} · {new Date(sh.starts_at).toLocaleDateString()} {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}</div>
                                                                    <div className="mt-1 text-xs text-muted-foreground">Status: {sh.status}{sh.location ? ` · ${sh.location}` : ''}</div>
                                                                </div>
                                                                {props.canManageAny ? (
                                                                    <div className="flex items-center gap-2">
                                                                        <Select
                                                                            value={assignForm.data.user_id}
                                                                            onValueChange={(v) => assignForm.setData('user_id', v)}
                                                                        >
                                                                            <SelectTrigger className="w-[220px]">
                                                                                <SelectValue placeholder="Assign staff" />
                                                                            </SelectTrigger>
                                                                            <SelectContent>
                                                                                {availableStaffForShift(sh).map((s) => (
                                                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                                                ))}
                                                                            </SelectContent>
                                                                        </Select>
                                                                        <Button
                                                                            size="sm"
                                                                            disabled={assignForm.processing || !assignForm.data.user_id}
                                                                            onClick={() => {
                                                                                assignForm.post(`/shifts/${sh.id}/assign`, {
                                                                                    preserveScroll: true,
                                                                                    onSuccess: () => assignForm.reset('user_id'),
                                                                                });
                                                                            }}
                                                                        >
                                                                            Assign
                                                                        </Button>
                                                                        <Link href={`/shifts/${sh.id}`}>
                                                                            <Button size="sm" variant="outline">Open</Button>
                                                                        </Link>
                                                                    </div>
                                                                ) : (
                                                                    <Link href={`/shifts/${sh.id}`}>
                                                                        <Button size="sm" variant="outline">Open</Button>
                                                                    </Link>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ),
                        },
                        {
                            key: 'timeoff',
                            label: (
                                <span className="flex items-center gap-2">
                                    Time off
                                    {props.stats.time_off_conflicts > 0 ? <Badge variant="destructive" className="text-[10px]">conflicts</Badge> : null}
                                </span>
                            ),
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">Leave / unavailability (one-off)</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <form
                                            className="rounded-md border p-3 space-y-3"
                                            onSubmit={(e) => {
                                                e.preventDefault();
	                                                // NOTE: Inertia's useForm().transform() does not reliably return the form object
	                                                // across versions, so do not chain .transform().post().
	                                                timeOffForm.transform((d: any) => ({
	                                                    ...d,
	                                                    return_to: '/rostering',
	                                                    user_id: d.user_id === 'self' ? undefined : Number(d.user_id),
	                                                }));

	                                                timeOffForm.post('/rostering/time-off', {
	                                                    preserveScroll: true,
	                                                    onFinish: () => timeOffForm.transform((d: any) => d),
	                                                });
                                            }}
                                        >
                                            <div className="font-medium">Add time-off block</div>
                                            <div className="grid gap-3 md:grid-cols-4">
                                                {props.canManageAny ? (
                                                    <div className="space-y-1">
                                                        <Label>Staff</Label>
                                                        <Select value={timeOffForm.data.user_id} onValueChange={(v) => timeOffForm.setData('user_id', v)}>
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select staff" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="self">(Me)</SelectItem>
                                                                {props.staff.map((s) => (
                                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                ) : null}

                                                <div className="space-y-1">
                                                    <Label>Type</Label>
                                                    <Select value={timeOffForm.data.type} onValueChange={(v) => timeOffForm.setData('type', v)}>
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="leave">Leave</SelectItem>
                                                            <SelectItem value="unavailable">Unavailable</SelectItem>
                                                            <SelectItem value="training">Training</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1">
                                                    <Label>Start</Label>
                                                    <Input type="datetime-local" value={timeOffForm.data.starts_at} onChange={(e) => timeOffForm.setData('starts_at', e.target.value)} />
                                                </div>

                                                <div className="space-y-1">
                                                    <Label>End</Label>
                                                    <Input type="datetime-local" value={timeOffForm.data.ends_at} onChange={(e) => timeOffForm.setData('ends_at', e.target.value)} />
                                                </div>
                                            </div>

                                            <div className="grid gap-3 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <Label>Label</Label>
                                                    <Input value={timeOffForm.data.label} onChange={(e) => timeOffForm.setData('label', e.target.value)} placeholder="e.g. Annual leave" />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Notes</Label>
                                                    <Input value={timeOffForm.data.notes} onChange={(e) => timeOffForm.setData('notes', e.target.value)} placeholder="Optional" />
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <Button type="submit" size="sm" disabled={timeOffForm.processing}>Save</Button>
                                                {timeOffForm.recentlySuccessful ? <span className="text-xs text-muted-foreground">Saved.</span> : null}
                                            </div>
                                        </form>

                                        <div className="space-y-2">
                                            {props.timeOffs.length === 0 ? (
                                                <div className="text-sm text-muted-foreground">No time-off blocks in this week.</div>
                                            ) : (
                                                props.timeOffs.map((b) => (
                                                    <div key={b.id} className="rounded-md border p-3 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {props.canManageAny ? (b.user ?? 'Staff') : 'Me'} · {new Date(b.starts_at).toLocaleDateString()} {fmtTime(b.starts_at)}–{fmtTime(b.ends_at)}
                                                            </div>
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                <span className="capitalize">{b.type}</span>
                                                                {b.label ? ` · ${b.label}` : ''}
                                                                {b.notes ? ` · ${b.notes}` : ''}
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Badge variant="outline" className="capitalize">{b.type}</Badge>
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() => {
                                                                    if (!confirm('Delete this time-off block?')) return;
                                                                    router.delete(`/rostering/time-off/${b.id}`, { preserveScroll: true, data: { return_to: '/rostering' } });
                                                                }}
                                                            >
                                                                Delete
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ))
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            ),
                        },
                        {
                            key: 'capacity',
                            label: 'Capacity',
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">Weekly capacity</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {!props.canManageAny ? (
                                            <div className="text-sm text-muted-foreground">Capacity is available for managers.</div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <table className="min-w-[500px] w-full border-collapse">
                                                    <thead>
                                                        <tr className="border-b">
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">Staff</th>
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">Hours</th>
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">Signal</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {props.capacity.map((c) => (
                                                            <tr key={c.user_id} className="border-b">
                                                                <td className="px-2 py-2 text-sm font-medium">{c.name}</td>
                                                                <td className="px-2 py-2 text-sm">{c.hours}</td>
                                                                <td className="px-2 py-2">
                                                                    {c.warn === 'high' ? (
                                                                        <Badge variant="destructive">High</Badge>
                                                                    ) : c.warn === 'medium' ? (
                                                                        <Badge variant="default">Watch</Badge>
                                                                    ) : (
                                                                        <Badge variant="outline">OK</Badge>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    Signals: Watch at ≥40h, High at ≥50h for the roster week.
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ),
                        },
                    ]}
                />

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Timesheets needing attention</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">
                                Shifts with a linked timesheet still in draft/submitted/returned.
                            </div>
                            <div className="space-y-2">
                                {props.shifts
                                    .filter((s) => ['draft', 'submitted', 'returned'].includes(s.timesheet_status ?? ''))
                                    .slice(0, 8)
                                    .map((sh) => (
                                        <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                            <div className="rounded-md border p-2 hover:bg-muted">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-sm font-medium">{sh.client}</div>
                                                    <Badge variant="outline">TS: {sh.timesheet_status}</Badge>
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {new Date(sh.starts_at).toLocaleDateString()} · {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                    {props.canManageAny && sh.staff ? ` · ${sh.staff}` : ''}
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                {props.stats.timesheets_pending === 0 && (
                                    <div className="text-sm text-muted-foreground">No pending timesheets in this week.</div>
                                )}
                            </div>

                            <div>
                                <Link href="/timesheets">
                                    <Button variant="outline" size="sm">Open Timesheets</Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Incidents in this roster window</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">Quick jump into the shifts that have incidents linked.</div>
                            <div className="space-y-2">
                                {props.shifts
                                    .filter((s) => s.incidents_count > 0)
                                    .slice(0, 8)
                                    .map((sh) => (
                                        <Link key={sh.id} href={`/shifts/${sh.id}`} className="block">
                                            <div className="rounded-md border p-2 hover:bg-muted">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-sm font-medium">{sh.client}</div>
                                                    <Badge variant="destructive">{sh.incidents_count} incident{sh.incidents_count === 1 ? '' : 's'}</Badge>
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {new Date(sh.starts_at).toLocaleDateString()} · {fmtTime(sh.starts_at)}–{fmtTime(sh.ends_at)}
                                                    {props.canManageAny && sh.staff ? ` · ${sh.staff}` : ''}
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                {props.stats.incidents === 0 && (
                                    <div className="text-sm text-muted-foreground">No incidents linked to shifts in this week.</div>
                                )}
                            </div>
                            <div>
                                <Link href="/incidents">
                                    <Button variant="outline" size="sm">Open Incidents</Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            {/* Resolve overlap dialog */}
            <Dialog open={!!resolveModal} onOpenChange={(o) => !o && setResolveModal(null)}
            >
                <DialogContent className="sm:max-w-[720px]">
                    <DialogHeader>
                        <DialogTitle>Resolve overlap</DialogTitle>
                    </DialogHeader>

                    {resolveModal ? (
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                                <div className="rounded-md border p-3">
                                    <div className="flex items-center gap-2">
                                        <div className="text-sm font-medium">A</div>
                                        {resolveState?.aLocked ? <Badge variant="secondary">Locked</Badge> : null}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {new Date(resolveModal.a.starts_at).toLocaleDateString()} {fmtTime(resolveModal.a.starts_at)}–{fmtTime(resolveModal.a.ends_at)}
                                    </div>
                                    <div className="mt-1 text-xs">{resolveModal.a.client ?? clientSingular} · {resolveModal.a.staff ?? 'Unassigned'} </div>
                                    <div className="mt-2">
                                        <Link href={`/shifts/${resolveModal.a.id}`}>
                                            <Button size="sm" variant="outline">Open A</Button>
                                        </Link>
                                    </div>
                                </div>

                                <div className="rounded-md border p-3">
                                    <div className="flex items-center gap-2">
                                        <div className="text-sm font-medium">B</div>
                                        {resolveState?.bLocked ? <Badge variant="secondary">Locked</Badge> : null}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {new Date(resolveModal.b.starts_at).toLocaleDateString()} {fmtTime(resolveModal.b.starts_at)}–{fmtTime(resolveModal.b.ends_at)}
                                    </div>
                                    <div className="mt-1 text-xs">{resolveModal.b.client ?? clientSingular} · {resolveModal.b.staff ?? 'Unassigned'} </div>
                                    <div className="mt-2">
                                        <Link href={`/shifts/${resolveModal.b.id}`}>
                                            <Button size="sm" variant="outline">Open B</Button>
                                        </Link>
                                    </div>
                                </div>
                            </div>

                            {resolveModal.kind === 'staff' ? (
                                <div className="space-y-3">
                                    <div className="text-sm text-muted-foreground">
                                        Choose the quickest safe fix. Suggestions consider time-off + existing roster conflicts + lowest weekly hours.
                                    </div>
                                    {resolveState?.bothLocked ? (
                                        <div className="text-sm text-muted-foreground">
                                            Both shifts are locked (completed). This overlap is historical and cannot be resolved from rostering.
                                        </div>
                                    ) : null}

                                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <div className="text-sm font-medium">Keep A</div>
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={!!resolveState?.bLocked}
                                                    onClick={() => {
                                                        if (resolveState?.bLocked) return;
                                                        router.post(`/shifts/${resolveModal.b.id}/unassign`, { return_to: '/rostering' }, {
                                                            preserveScroll: true,
                                                            onSuccess: () => setResolveModal(null),
                                                        });
                                                    }}
                                                >
                                                    Open B (unassign)
                                                </Button>
                                            </div>

                                            <div className="rounded-md border p-2">
                                                <div className="text-xs text-muted-foreground">Reassign B to:</div>
                                                <div className="mt-2 flex items-center gap-2">
                                                    <Select
                                                        value={resolveReassignSelection[resolveModal.b.id] ?? ''}
                                                        onValueChange={(v) =>
                                                            setResolveReassignSelection((prev) => ({ ...prev, [resolveModal.b.id]: v }))
                                                        }
                                                    >
                                                        <SelectTrigger className="w-[260px]">
                                                            <SelectValue placeholder="Suggested staff" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {availableStaffForShift(resolveModal.b)
                                                                .filter((u) => u.id !== resolveModal.staffId)
                                                                .slice(0, 12)
                                                                .map((u) => (
                                                                    <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                                                ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Button
                                                        size="sm"
                                                        disabled={!!resolveState?.bLocked || !resolveReassignSelection[resolveModal.b.id]}
                                                        onClick={() => {
                                                            if (resolveState?.bLocked) return;
                                                            const uid = resolveReassignSelection[resolveModal.b.id];
                                                            if (!uid) return;
                                                            router.post(`/shifts/${resolveModal.b.id}/assign`, { user_id: uid, return_to: '/rostering' }, {
                                                                preserveScroll: true,
                                                                onSuccess: () => setResolveModal(null),
                                                            });
                                                        }}
                                                    >
                                                        Reassign B
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <div className="text-sm font-medium">Keep B</div>
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={!!resolveState?.aLocked}
                                                    onClick={() => {
                                                        if (resolveState?.aLocked) return;
                                                        router.post(`/shifts/${resolveModal.a.id}/unassign`, { return_to: '/rostering' }, {
                                                            preserveScroll: true,
                                                            onSuccess: () => setResolveModal(null),
                                                        });
                                                    }}
                                                >
                                                    Open A (unassign)
                                                </Button>
                                            </div>

                                            <div className="rounded-md border p-2">
                                                <div className="text-xs text-muted-foreground">Reassign A to:</div>
                                                <div className="mt-2 flex items-center gap-2">
                                                    <Select
                                                        value={resolveReassignSelection[resolveModal.a.id] ?? ''}
                                                        onValueChange={(v) =>
                                                            setResolveReassignSelection((prev) => ({ ...prev, [resolveModal.a.id]: v }))
                                                        }
                                                    >
                                                        <SelectTrigger className="w-[260px]">
                                                            <SelectValue placeholder="Suggested staff" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {availableStaffForShift(resolveModal.a)
                                                                .filter((u) => u.id !== resolveModal.staffId)
                                                                .slice(0, 12)
                                                                .map((u) => (
                                                                    <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                                                ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Button
                                                        size="sm"
                                                        disabled={!!resolveState?.aLocked || !resolveReassignSelection[resolveModal.a.id]}
                                                        onClick={() => {
                                                            if (resolveState?.aLocked) return;
                                                            const uid = resolveReassignSelection[resolveModal.a.id];
                                                            if (!uid) return;
                                                            router.post(`/shifts/${resolveModal.a.id}/assign`, { user_id: uid, return_to: '/rostering' }, {
                                                                preserveScroll: true,
                                                                onSuccess: () => setResolveModal(null),
                                                            });
                                                        }}
                                                    >
                                                        Reassign A
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <div className="text-sm text-muted-foreground">
                                        This is a client double-booking. Resolve by opening one shift (so it becomes an open slot) and then adjust times/staffing.
                                    </div>
                                    {resolveState?.bothLocked ? (
                                        <div className="text-sm text-muted-foreground">
                                            Both shifts are locked (completed). This overlap is historical and cannot be resolved from rostering.
                                        </div>
                                    ) : null}
                                    {props.canManageAny ? (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={!!resolveState?.aLocked}
                                                onClick={() => {
                                                    if (resolveState?.aLocked) return;
                                                    router.post(`/shifts/${resolveModal.a.id}/unassign`, { return_to: '/rostering' }, {
                                                        preserveScroll: true,
                                                        onSuccess: () => setResolveModal(null),
                                                    });
                                                }}
                                            >
                                                Open A (unassign)
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={!!resolveState?.bLocked}
                                                onClick={() => {
                                                    if (resolveState?.bLocked) return;
                                                    router.post(`/shifts/${resolveModal.b.id}/unassign`, { return_to: '/rostering' }, {
                                                        preserveScroll: true,
                                                        onSuccess: () => setResolveModal(null),
                                                    });
                                                }}
                                            >
                                                Open B (unassign)
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            )}
                
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setResolveModal(null)}>Close</Button>
                            </DialogFooter>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            </div>
        </AppLayout>
    );
}
